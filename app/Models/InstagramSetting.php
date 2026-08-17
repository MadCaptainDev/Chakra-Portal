<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;

/**
 * The studio's Instagram app credentials. One row, like CompanySetting.
 *
 * See the migration for why these live here rather than in .env.
 */
class InstagramSetting extends Model
{
    protected $fillable = [
        'app_id',
        'app_secret',
        'updated_by_id',
    ];

    protected $casts = [
        // Encrypted, not hashed: every OAuth exchange signs with it, so it has
        // to be readable back. Same trade as ClientCredential::$secret.
        'app_secret' => 'encrypted',
        'verified_at' => 'datetime',
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

    /**
     * Where Instagram sends the client back.
     *
     * Built from app.url rather than the current request: Meta matches this
     * string exactly against what is registered in the dashboard, so it must
     * not vary with the hostname an admin happened to sign in through. That
     * mismatch is the most common reason this flow fails, and the error Meta
     * returns for it names nothing useful.
     */
    public function callbackUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/oauth/instagram/callback';
    }

    /** Whether a connection can even be attempted. */
    public function isConfigured(): bool
    {
        return filled($this->app_id) && filled($this->app_secret);
    }
}
