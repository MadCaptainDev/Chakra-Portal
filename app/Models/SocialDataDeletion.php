<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The receipt for one data deletion request.
 *
 * See the migration: the status URL Meta is given has to keep working after
 * the data is gone, so something has to survive the deletion. This is that
 * something, and it deliberately holds no account content.
 */
class SocialDataDeletion extends Model
{
    protected $guarded = [];

    protected $casts = [
        'requested_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public static function open(string $platform, ?string $platformUserId): self
    {
        return self::create([
            'platform' => $platform,
            'platform_user_id' => $platformUserId,
            'confirmation_code' => Str::lower(Str::random(20)),
            'requested_at' => now(),
        ]);
    }

    public function complete(string $outcome): void
    {
        $this->forceFill([
            'completed_at' => now(),
            'outcome' => mb_substr($outcome, 0, 255),
        ])->save();
    }

    public function statusUrl(): string
    {
        return route('instagram.deletion-status', ['code' => $this->confirmation_code]);
    }
}
