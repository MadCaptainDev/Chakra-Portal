<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;

/**
 * The studio's Notion integration token. One row, like instagram_settings /
 * whatsapp_settings.
 *
 * Simpler than either of those: Notion is a plain outbound API the portal
 * calls with a bearer token, not an inbound webhook, so there is no verify
 * token to generate or rotate here.
 */
class NotionSetting extends Model
{
    protected $fillable = [
        'api_key',
        'updated_by_id',
    ];

    protected $casts = [
        // Encrypted, not hashed: every sync call signs with it, so it has to
        // be readable back. Same trade as InstagramSetting::$app_secret.
        'api_key' => 'encrypted',
    ];

    /**
     * The one row, created empty on first use.
     *
     * The retry covers two admins opening the settings screen at once: one
     * inserts, the other loses the primary key and simply reads what the
     * winner wrote.
     */
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

    /** Whether a sync can even be attempted. */
    public function isConfigured(): bool
    {
        return filled($this->api_key);
    }
}
