<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One signed-in copy of Chakra Planner on somebody's computer.
 *
 * Same token shape as McpToken -- prefixed, hashed, plaintext seen once --
 * but a separate table on purpose: a planner login must not be able to call
 * the MCP endpoint, and turning somebody's MCP access off (Developer →
 * Limits) must not sign them out of the planner.
 */
class PlannerSession extends Model
{
    public const PREFIX = 'chakraplanner_';

    private const RANDOM_LENGTH = 48;

    protected $fillable = ['user_id', 'device_name', 'platform', 'app_version'];

    protected $casts = [
        'last_used_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  array{device_name: string, platform?: ?string, app_version?: ?string}  $device
     * @return array{session: PlannerSession, plain: string}
     */
    public static function issue(User $user, array $device): array
    {
        $plain = self::PREFIX.Str::random(self::RANDOM_LENGTH);

        $session = new self([
            'user_id' => $user->id,
            'device_name' => Str::limit($device['device_name'], 120, ''),
            'platform' => $device['platform'] ?? null,
            'app_version' => $device['app_version'] ?? null,
        ]);
        $session->forceFill(['token_hash' => hash('sha256', $plain), 'last_used_at' => now()])->save();

        return ['session' => $session, 'plain' => $plain];
    }

    /** Looked up by hash, like McpToken::resolve(). */
    public static function resolve(?string $plain): ?self
    {
        if (! is_string($plain) || ! str_starts_with($plain, self::PREFIX)) {
            return null;
        }

        return self::with('user')->where('token_hash', hash('sha256', $plain))->first();
    }

    /** At most once a minute: the app polls and every write would be a row update per call. */
    public function touchLastUsed(?string $appVersion = null): void
    {
        if ($this->last_used_at?->gt(now()->subMinute()) && ($appVersion === null || $appVersion === $this->app_version)) {
            return;
        }

        $this->forceFill(['last_used_at' => now(), 'app_version' => $appVersion ?? $this->app_version])->save();
    }
}
