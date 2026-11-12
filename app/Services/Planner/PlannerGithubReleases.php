<?php

namespace App\Services\Planner;

use App\Models\PlannerRelease;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Chakra Planner builds published as GitHub releases
 * (config services.planner.github_repo, a public repo).
 *
 * The newest non-draft release is read from GitHub's API and cached for a
 * few minutes. Installers are matched to platforms by file name (the names
 * tauri-action gives them); if the release carries Tauri's latest.json, its
 * signed updater entries are used for the app's self-update. Builds uploaded
 * straight to the portal (PlannerRelease) still work: per platform, whichever
 * source has the higher version wins (see best()).
 */
class PlannerGithubReleases
{
    private const CACHE_KEY = 'planner.github_release';

    public static function repo(): ?string
    {
        return config('services.planner.github_repo') ?: null;
    }

    public static function pageUrl(): ?string
    {
        return ($repo = self::repo()) ? "https://github.com/{$repo}/releases" : null;
    }

    /**
     * @return array{version: string, notes: string, pub_date: string, page_url: string,
     *               installers: array<string, string>, updater: array<string, array{url: string, signature: string}>}|null
     */
    public static function latest(): ?array
    {
        if (! self::repo()) {
            return null;
        }

        // Cache a miss too (as false), so a GitHub outage is not hit on every page view.
        $cached = Cache::remember(self::CACHE_KEY, now()->addMinutes(10), fn () => self::fetch() ?? false);

        return $cached ?: null;
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The newest download for one platform from either source.
     *
     * @return array{version: string, date: \Carbon\CarbonInterface|null, installer_url: string, updater_url: ?string, signature: ?string, notes: string, source: string}|null
     */
    public static function best(string $platform): ?array
    {
        $options = [];

        $gh = self::latest();
        if ($gh && isset($gh['installers'][$platform])) {
            $options[] = [
                'version' => $gh['version'],
                'date' => $gh['pub_date'] ? \Illuminate\Support\Carbon::parse($gh['pub_date']) : null,
                'installer_url' => $gh['installers'][$platform],
                'updater_url' => $gh['updater'][$platform]['url'] ?? null,
                'signature' => $gh['updater'][$platform]['signature'] ?? null,
                'notes' => $gh['notes'],
                'source' => 'github',
            ];
        }

        $local = PlannerRelease::latestPerPlatform()[$platform] ?? null;
        if ($local) {
            $options[] = [
                'version' => $local->version,
                'date' => $local->created_at,
                'installer_url' => asset($local->installer_path ?? $local->file_path),
                'updater_url' => asset($local->file_path),
                'signature' => $local->signature,
                'notes' => (string) $local->notes,
                'source' => 'portal',
            ];
        }

        usort($options, fn ($a, $b) => version_compare($b['version'], $a['version']));

        return $options[0] ?? null;
    }

    private static function fetch(): ?array
    {
        try {
            $res = Http::timeout(8)
                ->withHeaders(['Accept' => 'application/vnd.github+json', 'User-Agent' => 'chakra-portal'])
                ->get('https://api.github.com/repos/'.self::repo().'/releases', ['per_page' => 10]);
            if (! $res->ok()) {
                return null;
            }

            $release = collect($res->json())
                ->reject(fn ($r) => ($r['draft'] ?? false) || ($r['prerelease'] ?? false))
                ->sort(fn ($a, $b) => version_compare(ltrim($b['tag_name'] ?? '', 'v'), ltrim($a['tag_name'] ?? '', 'v')))
                ->first();
            if (! $release) {
                return null;
            }

            $assets = collect($release['assets'] ?? [])->pluck('browser_download_url', 'name')->all();

            return [
                'version' => ltrim((string) $release['tag_name'], 'v'),
                'notes' => (string) ($release['body'] ?? ''),
                'pub_date' => (string) ($release['published_at'] ?? ''),
                'page_url' => (string) ($release['html_url'] ?? self::pageUrl()),
                'installers' => self::installers($assets),
                'updater' => self::updater($assets),
            ];
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Platform => installer URL, by tauri-action's file names:
     * "…_x64-setup.exe" / "…_x64_en-US.msi", "…_aarch64.dmg", "…_x64.dmg",
     * "…_universal.dmg" (both Macs).
     *
     * @param  array<string, string>  $assets  name => url
     * @return array<string, string>
     */
    public static function installers(array $assets): array
    {
        $find = function (callable $match) use ($assets): ?string {
            foreach ($assets as $name => $url) {
                if ($match(strtolower($name))) {
                    return $url;
                }
            }

            return null;
        };
        $ends = fn (string $suffix) => fn (string $n) => str_ends_with($n, $suffix);

        $windows = $find(fn ($n) => str_ends_with($n, '.exe') && ! str_contains($n, 'arm64'))
            ?? $find($ends('.msi'));
        $universal = $find(fn ($n) => str_ends_with($n, '.dmg') && str_contains($n, 'universal'));
        $appleChip = $find(fn ($n) => str_ends_with($n, '.dmg') && (str_contains($n, 'aarch64') || str_contains($n, 'arm64'))) ?? $universal;
        $intel = $find(fn ($n) => str_ends_with($n, '.dmg') && (str_contains($n, 'x64') || str_contains($n, 'x86_64'))) ?? $universal;

        // A single .dmg with no architecture in its name: offer it to both Macs.
        if (! $appleChip && ! $intel && ($any = $find($ends('.dmg')))) {
            $appleChip = $intel = $any;
        }

        return array_filter([
            'windows-x86_64' => $windows,
            'darwin-aarch64' => $appleChip,
            'darwin-x86_64' => $intel,
        ]);
    }

    /** @param  array<string, string>  $assets */
    private static function updater(array $assets): array
    {
        $url = $assets['latest.json'] ?? null;
        if (! $url) {
            return [];
        }

        $json = Http::timeout(8)->withHeaders(['User-Agent' => 'chakra-portal'])->get($url)->json();
        $out = [];
        foreach ((array) ($json['platforms'] ?? []) as $key => $entry) {
            // latest.json may also carry "windows-x86_64-nsis" style keys; keep the plain ones.
            $platform = preg_replace('/-(nsis|msi|app)$/', '', (string) $key);
            if (isset(PlannerRelease::PLATFORMS[$platform], $entry['url'], $entry['signature']) && ! isset($out[$platform])) {
                $out[$platform] = ['url' => $entry['url'], 'signature' => $entry['signature']];
            }
        }

        return $out;
    }
}
