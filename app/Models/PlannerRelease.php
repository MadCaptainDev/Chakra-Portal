<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Chakra Planner installer, for one platform. The app's updater asks
 * /api/planner/app/latest for the newest; people download from the
 * Chakra Planner page in the portal.
 */
class PlannerRelease extends Model
{
    /**
     * Tauri's updater target names, which the app reports as {target}-{arch}.
     *
     * @var array<string, string>
     */
    public const PLATFORMS = [
        'windows-x86_64' => 'Windows',
        'darwin-aarch64' => 'Mac (Apple chip)',
        'darwin-x86_64' => 'Mac (Intel)',
    ];

    protected $fillable = ['version', 'platform', 'file_path', 'installer_path', 'signature', 'notes', 'uploaded_by_id'];

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    public function url(): string
    {
        return asset($this->file_path);
    }

    /** Newest release per platform, by version number rather than upload order. */
    public static function latestPerPlatform(): array
    {
        return static::query()->get()
            ->groupBy('platform')
            ->map(fn ($rows) => $rows->sort(fn (self $a, self $b) => version_compare($b->version, $a->version))->first())
            ->all();
    }
}
