<?php

namespace App\Http\Controllers\Api\Planner;

use App\Http\Controllers\Controller;
use App\Models\PlannerRelease;
use App\Services\Planner\PlannerGithubReleases;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Chakra Planner's own updates. Builds come from the GitHub releases repo
 * (PlannerGithubReleases) or are uploaded straight to the portal (store);
 * per platform the higher version wins.
 *
 * GitHub builds the installers and uploads them here (store); the app's
 * built-in updater asks update() on start; people download from the Chakra
 * Planner page in the portal (download). Each release per platform carries
 * two files: the signed updater bundle the app installs itself from, and
 * the installer a person downloads (on Windows these are the same file).
 */
class PlannerReleaseController extends Controller
{
    /**
     * Tauri's dynamic update check: 204 when there is nothing newer.
     * Configure the app's updater endpoint as
     * https://chakragroups.in/api/planner/app/update/{{target}}/{{arch}}/{{current_version}}
     */
    public function update(string $target, string $arch, string $current): JsonResponse|Response
    {
        $best = PlannerGithubReleases::best("{$target}-{$arch}");

        // No signed updater file (e.g. a GitHub release without latest.json): nothing to install.
        if (! $best || ! $best['updater_url'] || ! $best['signature']
            || version_compare($best['version'], ltrim($current, 'v'), '<=')) {
            return response()->noContent();
        }

        return response()->json([
            'version' => $best['version'],
            'notes' => $best['notes'],
            'pub_date' => $best['date']?->toIso8601ZuluString(),
            'url' => $best['updater_url'],
            'signature' => $best['signature'],
        ]);
    }

    /** Newest release per platform, for anyone (the download page, a status check). */
    public function latest(): JsonResponse
    {
        return response()->json([
            'platforms' => collect(PlannerRelease::PLATFORMS)
                ->map(fn (string $label, string $platform) => ($b = PlannerGithubReleases::best($platform)) ? [
                    'version' => $b['version'],
                    'label' => $label,
                    'download_url' => route('api.planner.app.download', $platform),
                    'notes' => $b['notes'],
                    'pub_date' => $b['date']?->toIso8601ZuluString(),
                ] : null)
                ->filter(),
        ]);
    }

    public function download(string $platform): RedirectResponse|JsonResponse
    {
        $best = PlannerGithubReleases::best($platform);

        if (! $best) {
            return response()->json(['message' => 'No Chakra Planner release for that platform yet.', 'code' => 'no_release'], 404);
        }

        return redirect()->away($best['installer_url']);
    }

    /** Upload one platform's build. Needs Chakra Planner → Manage. */
    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->can('planner.manage')) {
            return response()->json(['message' => 'Uploading a release needs Chakra Planner → Manage.', 'code' => 'no_manage'], 403);
        }

        $data = $request->validate([
            'version' => ['required', 'string', 'max:40', 'regex:/^\d+\.\d+\.\d+([-+][0-9A-Za-z.-]+)?$/'],
            'platform' => ['required', 'in:'.implode(',', array_keys(PlannerRelease::PLATFORMS))],
            'file' => ['required', 'file', 'max:512000'],
            'installer' => ['nullable', 'file', 'max:512000'],
            'signature' => ['nullable', 'string', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $release = PlannerRelease::query()->firstOrNew(['version' => $data['version'], 'platform' => $data['platform']]);
        foreach (['file_path', 'installer_path'] as $old) {
            if ($release->{$old}) {
                File::delete(public_path($release->{$old}));
            }
        }

        $release->forceFill([
            'file_path' => $this->keep($request->file('file'), $data['version']),
            'installer_path' => $request->hasFile('installer') ? $this->keep($request->file('installer'), $data['version']) : null,
            'signature' => isset($data['signature']) ? trim($data['signature']) : null,
            'notes' => $data['notes'] ?? null,
            'uploaded_by_id' => $request->user()->id,
        ])->save();

        return response()->json(['release' => [
            'id' => $release->id,
            'version' => $release->version,
            'platform' => $release->platform,
            'url' => asset($release->file_path),
            'installer_url' => $release->installer_path ? asset($release->installer_path) : null,
        ]], 201);
    }

    /**
     * Under public/uploads/planner/{version}/, keeping the original name
     * (dots and all -- "Chakra Planner.app.tar.gz" must stay a .tar.gz),
     * behind a random prefix so an old link cannot be guessed.
     */
    private function keep(UploadedFile $file, string $version): string
    {
        $folder = 'uploads/planner/'.$version;
        $name = Str::random(10).'-'.preg_replace('/[^A-Za-z0-9._-]+/', '-', $file->getClientOriginalName());
        $file->move(public_path($folder), $name);

        return $folder.'/'.$name;
    }
}
