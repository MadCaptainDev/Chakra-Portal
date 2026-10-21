<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;

/**
 * Chakra Planner's two secrets. One row, like NotionSetting.
 *
 * - ably_key: the Ably API key ("appId.keyId:secret") the portal publishes
 *   live changes with and signs the app's realtime tokens with. Empty means
 *   live updates are off and the app falls back to polling.
 * - notion_webhook_secret: the verification_token Notion sends once when a
 *   webhook subscription is created. It has to be pasted back into Notion to
 *   verify the subscription, and every event after that is signed with it.
 */
class PlannerSetting extends Model
{
    protected $fillable = ['ably_key', 'updated_by_id'];

    protected $casts = [
        'ably_key' => 'encrypted',
        'notion_webhook_secret' => 'encrypted',
        'notion_webhook_secret_at' => 'datetime',
        'notion_webhook_last_event_at' => 'datetime',
    ];

    /** The one row, created empty on first use. Same race guard as NotionSetting. */
    public static function current(): self
    {
        if ($existing = static::query()->whereKey(1)->first()) {
            return $existing;
        }

        try {
            return static::forceCreate(['id' => 1]);
        } catch (QueryException $e) {
            return static::query()->whereKey(1)->first() ?? throw $e;
        }
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }

    public function realtimeEnabled(): bool
    {
        return filled($this->ably_key) && str_contains((string) $this->ably_key, ':');
    }

    /** "appId.keyId", the half of the key that is safe to show. */
    public function ablyKeyName(): ?string
    {
        return $this->realtimeEnabled() ? strstr((string) $this->ably_key, ':', true) : null;
    }
}
