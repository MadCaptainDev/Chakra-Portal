<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One social account the studio may read on a client's behalf.
 *
 * See the migration for why this is one table for every platform rather than
 * an instagram_accounts table.
 */
class SocialAccount extends Model
{
    public const PLATFORM_INSTAGRAM = 'instagram';

    /** @var array<string, string> */
    public const PLATFORMS = [
        self::PLATFORM_INSTAGRAM => 'Instagram',
    ];

    public const STATUS_CONNECTED = 'connected';

    /** Disconnected by us, or the client revoked us on Instagram's side. */
    public const STATUS_REVOKED = 'revoked';

    /** Still connected as far as we know, but the last call failed. */
    public const STATUS_ERROR = 'error';

    /**
     * How close to expiry counts as "refresh now".
     *
     * Instagram long-lived tokens last ~60 days and are refreshed by being
     * used. Ten days of margin means a fortnight of failed syncs still leaves
     * room to notice before the connection actually dies.
     */
    private const REFRESH_MARGIN_DAYS = 10;

    /*
     * access_token is absent: it is written by the connector from a token
     * exchange, never from a form. Nothing a browser posts should be able to
     * set the credential this row exists to hold.
     */
    protected $fillable = [
        'client_id',
        'platform',
        'platform_user_id',
        'username',
        'account_type',
        'profile_picture_url',
        'followers_count',
        'scopes',
        'status',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'scopes' => 'array',
        'token_expires_at' => 'datetime',
        'connected_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'last_error_at' => 'datetime',
        'followers_count' => 'integer',
    ];

    /**
     * Hidden from array and JSON conversion.
     *
     * Belt and braces. Nothing intentionally serialises this model, but a
     * future toJson() in a debug response would otherwise put a live token in
     * a browser.
     *
     * @var list<string>
     */
    protected $hidden = ['access_token'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Events Meta has pushed about this account.
     *
     * Named in full rather than events() because a social account will
     * eventually have insights too, and "events" would stop meaning anything.
     */
    public function socialWebhookEvents(): HasMany
    {
        return $this->hasMany(SocialWebhookEvent::class);
    }

    public function socialMediaItems(): HasMany
    {
        return $this->hasMany(SocialMediaItem::class);
    }

    public function insights(): HasMany
    {
        return $this->hasMany(SocialInsight::class);
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by_id');
    }

    public function scopeForPlatform(Builder $query, string $platform): Builder
    {
        return $query->where('platform', $platform);
    }

    /**
     * Accounts a sync should actually attempt.
     *
     * NOT `where('status', STATUS_CONNECTED)` -- that excludes STATUS_ERROR,
     * and found in production: a transient failure (a bad value from Meta,
     * a dropped connection, anything not the token itself being dead) sets
     * ERROR, and requiring exactly CONNECTED then means the account is never
     * tried again, ever, until somebody notices and repeats the whole OAuth
     * flow for a problem that was never theirs. A retry loop that only
     * retries once is not a retry loop.
     *
     * REVOKED is excluded, correctly: disconnect() and the deauthorize
     * webhook both null the token when they set that status, so a revoked
     * account has nothing to call Instagram with regardless of this scope --
     * `whereNotNull('access_token')` is what actually does the excluding.
     * The explicit status check exists only to say that intent out loud
     * rather than depend on the token-nulling being a coincidence.
     */
    public function scopeConnected(Builder $query): Builder
    {
        return $query->where('status', '!=', self::STATUS_REVOKED)->whereNotNull('access_token');
    }

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED && filled($this->access_token);
    }

    public function isExpired(): bool
    {
        return $this->token_expires_at !== null && $this->token_expires_at->isPast();
    }

    public function needsRefreshSoon(): bool
    {
        return $this->token_expires_at !== null
            && $this->token_expires_at->isBefore(now()->addDays(self::REFRESH_MARGIN_DAYS));
    }

    /**
     * Whether "Sync now" is allowed to actually call Instagram right now.
     *
     * There was no throttle at all: the button posted straight to the sync
     * service on every click, so a double-click or an impatient refresh fired
     * the same batch of Instagram calls twice for data that cannot have
     * changed in the seconds between clicks. The interval is the studio's
     * own setting (Setup → Instagram), not a constant, so it is a number
     * staff can see and change rather than one buried in a controller.
     */
    public function canSyncNow(): bool
    {
        return $this->nextSyncAllowedAt()->isPast();
    }

    public function nextSyncAllowedAt(): \Illuminate\Support\Carbon
    {
        if (! $this->last_synced_at) {
            return now()->subMinute();
        }

        $minutes = InstagramSetting::current()->sync_throttle_minutes;

        return $this->last_synced_at->copy()->addMinutes($minutes);
    }

    public function platformLabel(): string
    {
        return self::PLATFORMS[$this->platform] ?? ucfirst($this->platform);
    }

    /** "@djthangamaligai", or the account id when the handle is not known yet. */
    public function handle(): string
    {
        return filled($this->username) ? '@'.$this->username : (string) $this->platform_user_id;
    }

    /**
     * Record a failure against the connection.
     *
     * The message is Meta's own and is shown to staff, because theirs are
     * unusually specific -- "The user is not an Instagram Business account" is
     * the whole diagnosis. The token that produced it is never written here.
     */
    public function recordFailure(string $message, bool $fatal = false): void
    {
        $this->forceFill([
            'last_error' => mb_substr($message, 0, 500),
            'last_error_at' => now(),
            'status' => $fatal ? self::STATUS_REVOKED : self::STATUS_ERROR,
        ])->save();
    }

    /**
     * A sync just succeeded: the error is over, and so is whatever status it
     * left behind. Only ever called after a real sync, which scopeConnected()
     * already restricted to non-revoked accounts, so this is never the thing
     * that reactivates a genuinely dead connection.
     */
    public function clearFailure(): void
    {
        $this->forceFill([
            'last_error' => null,
            'last_error_at' => null,
            'status' => self::STATUS_CONNECTED,
        ])->save();
    }

    /**
     * Every portfolio piece mapped to one of this account's cached posts,
     * refreshed with whatever this sync just wrote to social_insights.
     *
     * Failure-isolated on purpose, at two levels: one bad row's exception is
     * caught and reported without stopping the rest, and this method is
     * called from inside the sync's own success path -- a portfolio refresh
     * going wrong must never turn a successful Instagram sync into a failed
     * one, since the sync's own data is good regardless of whether its
     * portfolio mirror updates cleanly.
     */
    public function refreshLinkedPortfolioItems(): void
    {
        PortfolioItem::query()
            ->whereHas('socialMediaItem', fn (Builder $query) => $query->where('social_account_id', $this->id))
            ->with('socialMediaItem.insights')
            ->get()
            ->each(function (PortfolioItem $item) {
                try {
                    $item->refreshFromInstagram();
                } catch (\Throwable $e) {
                    report($e);
                }
            });
    }
}
