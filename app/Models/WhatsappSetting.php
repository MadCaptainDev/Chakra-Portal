<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * How Meta reaches this portal. One row, like CompanySetting.
 *
 * The verify token is generated the first time anybody opens the screen, so
 * the setup instructions in the Meta dashboard can be followed straight
 * through -- there is never a moment where the admin has to invent a secret
 * themselves, which is how "chakra123" ends up in production.
 */
class WhatsappSetting extends Model
{
    /**
     * Long enough that guessing it is not a strategy, short enough to be
     * copied by hand off a screen if the copy button ever fails.
     */
    private const TOKEN_LENGTH = 40;

    /*
     * verify_token is not fillable: it is generated here and rotated by its own
     * action, never posted. The timestamps are stamped by the webhook itself.
     */
    protected $fillable = [
        'app_secret',
        'access_token',
        'api_version',
        'phone_number_id',
        'business_account_id',
        'display_phone_number',
        'updated_by_id',
    ];

    protected $casts = [
        // Encrypted, not hashed: every incoming webhook is signed with it, so
        // it has to be readable back. See the migration for why that trade is
        // accepted.
        'app_secret' => 'encrypted',
        // Same trade, higher stakes: this one can send as the studio.
        'access_token' => 'encrypted',
        'verified_at' => 'datetime',
        'last_event_at' => 'datetime',
    ];

    /**
     * The one row, created with a token already in it on first use.
     *
     * forceCreate rather than firstOrCreate, because verify_token is
     * deliberately not fillable -- mass assignment would silently drop it and
     * the row would fail its NOT NULL constraint.
     */
    public static function current(): self
    {
        if ($existing = static::query()->whereKey(1)->first()) {
            return $existing;
        }

        try {
            return static::forceCreate([
                'id' => 1,
                'verify_token' => self::freshToken(),
            ]);
        } catch (QueryException $e) {
            /*
             * Two requests reaching a fresh install at the same moment: one
             * inserts, the other loses the primary key and lands here. The row
             * it wanted now exists, so read it rather than failing -- and
             * whichever token won is the one the screen will show.
             */
            $existing = static::query()->whereKey(1)->first();

            if (! $existing) {
                throw $e;
            }

            return $existing;
        }
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }

    /**
     * The URL Meta posts to. Built from app.url rather than the current
     * request, because it is copied into the Meta dashboard once and must not
     * change depending on which hostname the admin happened to sign in through.
     */
    public function callbackUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/webhooks/whatsapp';
    }

    /**
     * Rotate the token. The old one stops working immediately, which means the
     * subscription in the Meta dashboard has to be re-verified -- said plainly
     * on the screen, because a silent rotation looks exactly like an outage.
     */
    public function rotateVerifyToken(): void
    {
        $this->forceFill([
            'verify_token' => self::freshToken(),
            // The handshake that proved the old token is no longer proof of
            // anything, so the badge goes back to "not verified" and tells the
            // truth until Meta confirms the new one.
            'verified_at' => null,
        ])->save();
    }

    /** Whether the endpoint is able to accept traffic at all. */
    public function isReceiving(): bool
    {
        return filled($this->app_secret);
    }

    /**
     * Sending needs both halves: who we are (the phone number ID) and proof we
     * are allowed to be them (the token). Either one missing is not a partial
     * capability, it is no capability.
     */
    public function canSend(): bool
    {
        return filled($this->access_token) && filled($this->phone_number_id);
    }

    /** Where a send is POSTed. */
    public function messagesEndpoint(): string
    {
        return sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            $this->api_version ?: 'v22.0',
            $this->phone_number_id
        );
    }

    public function matchesVerifyToken(?string $candidate): bool
    {
        // hash_equals, not ===, so a wrong token cannot be found one character
        // at a time by timing the responses.
        return is_string($candidate) && hash_equals($this->verify_token, $candidate);
    }

    private static function freshToken(): string
    {
        return Str::random(self::TOKEN_LENGTH);
    }
}
