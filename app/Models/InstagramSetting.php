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
    /** The floor and ceiling a person can set the sync throttle to. */
    public const MIN_SYNC_THROTTLE_MINUTES = 1;

    public const MAX_SYNC_THROTTLE_MINUTES = 1440;

    protected $fillable = [
        'app_id',
        'app_secret',
        'sync_throttle_minutes',
        'updated_by_id',
    ];

    protected $casts = [
        // Encrypted, not hashed: every OAuth exchange signs with it, so it has
        // to be readable back. Same trade as ClientCredential::$secret.
        'app_secret' => 'encrypted',
        'verified_at' => 'datetime',
        'webhook_verified_at' => 'datetime',
        'sync_throttle_minutes' => 'integer',
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
            /*
             * Heal a row that predates the verify token rather than
             * backfilling it in a migration. The row is created the first time
             * anybody opens the settings screen, so it can and does exist
             * before a column that is added later -- and a settings screen
             * showing an empty token is a screen somebody copies an empty
             * string out of.
             */
            if (blank($existing->verify_token)) {
                $existing->forceFill(['verify_token' => self::freshToken()])->save();
            }

            return $existing;
        }

        try {
            // forceCreate: verify_token is generated here and is not fillable,
            // so mass assignment would silently drop it and leave a row that
            // can never complete a handshake.
            //
            // sync_throttle_minutes is passed explicitly, matching the
            // migration's own column default, rather than left to that DB
            // default alone -- forceCreate()'s returned model only carries
            // what was actually passed to it, not what the database filled
            // in for the columns that were not. Leaving it out meant the
            // very first call to current() in a fresh install returned a
            // model with sync_throttle_minutes null in PHP even though the
            // row in the database correctly held 15, and addMinutes(null)
            // on that value silently added nothing to the throttle.
            return static::forceCreate([
                'id' => 1,
                'verify_token' => self::freshToken(),
                'sync_throttle_minutes' => 15,
            ]);
        } catch (QueryException $e) {
            return static::query()->whereKey(1)->first() ?? throw $e;
        }
    }

    /**
     * Where META posts events. NOT the OAuth redirect URI.
     *
     * Public, because Meta verifies it with an anonymous GET and has no cookie
     * to send. Pasting the OAuth callback here instead is the classic mistake:
     * that route is behind `auth`, so the handshake is answered with a
     * redirect to the login page and Meta reports only that it could not be
     * validated.
     */
    public function webhookUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/webhooks/instagram';
    }

    public function matchesVerifyToken(?string $candidate): bool
    {
        // hash_equals, not ===, so a wrong token cannot be found one character
        // at a time by timing the responses.
        return is_string($candidate)
            && filled($this->verify_token)
            && hash_equals((string) $this->verify_token, $candidate);
    }

    /**
     * Issue a new verify token.
     *
     * The old one dies immediately, so the subscription has to be verified
     * again in the Meta dashboard -- said plainly on the screen, because a
     * silent rotation looks exactly like an outage.
     */
    public function rotateVerifyToken(): void
    {
        $this->forceFill([
            'verify_token' => self::freshToken(),
            'webhook_verified_at' => null,
        ])->save();
    }

    private static function freshToken(): string
    {
        return \Illuminate\Support\Str::random(40);
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
