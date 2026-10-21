<?php

namespace App\Http\Controllers\Api\Planner;

use App\Http\Controllers\Controller;
use App\Models\PlannerRelease;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Chakra Planner's own updates, hosted by the portal.
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
        $release = PlannerRelease::latestPerPlatform()["{$target}-{$arch}"] ?? null;

        if (! $release || version_compare($release->version, ltrim($current, 'v'), '<=')) {
            return response()->noContent();
        }

        return response()->json([
            'version' => $release->version,
            'notes' => (string) $release->notes,
            'pub_date' => $release->created_at->toIso8601ZuluString(),
            'url' => asset($release->file_path),
            'signature' => (string) $release->signature,
        ]);
    }

    /** Newest release per platform, for anyone (the download page, a status check). */
    public function latest(): JsonResponse
    {
        return response()->json([
            'platforms' => collect(PlannerRelease::latestPerPlatform())->map(fn (PlannerRelease $r) => [
                'version' => $r->version,
                'label' => PlannerRelease::PLATFORMS[$r->platform] ?? $r->platform,
                'download_url' => route('api.planner.app.download', $r->platform),
                'notes' => $r->notes,
                'pub_date' => $r->created_at->toIso8601ZuluString(),
            ]),
        ]);
    }

    public function download(string $platform): RedirectResponse|JsonResponse
    {
        $release = PlannerRelease::latestPerPlatform()[$platform] ?? null;

        if (! $release) {
            return response()->json(['message' => 'No Chakra Planner release for that platform yet.', 'code' => 'no_release'], 404);
        }

        return redirect()->away(asset($release->installer_path ?? $release->file_path));
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
