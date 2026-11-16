<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One scraped post from a tracked competitor's account.
 */
class CompetitorReel extends Model
{
    protected $fillable = [
        'competitor_account_id',
        'platform_post_id',
        'video_url',
        'thumbnail_url',
        'caption',
        'play_count',
        'like_count',
        'comment_count',
        'posted_at',
        'scraped_at',
        'short_code',
        'duration_seconds',
        'hashtags',
        'music',
        'comments',
    ];

    protected $casts = [
        'play_count' => 'integer',
        'like_count' => 'integer',
        'comment_count' => 'integer',
        'posted_at' => 'datetime',
        'scraped_at' => 'datetime',
        'hashtags' => 'array',
        'music' => 'array',
        'comments' => 'array',
        'duration_seconds' => 'float',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(CompetitorAccount::class, 'competitor_account_id');
    }

    public function analysis(): HasOne
    {
        return $this->hasOne(CompetitorReelAnalysis::class);
    }

    public function scopeNotAnalyzed(Builder $query): Builder
    {
        return $query->whereDoesntHave('analysis');
    }

    /**
     * Instagram's video links are signed and die within days, so only a reel
     * seen by a recent scrape can be downloaded for Gemini. Old reels that
     * fall out of the scrape window keep a dead link.
     */
    public const FRESH_DAYS = 2;

    public function scopeWithFreshVideo(Builder $query): Builder
    {
        return $query->whereNotNull('video_url')->where('scraped_at', '>=', now()->subDays(self::FRESH_DAYS));
    }

    public function hasFreshVideo(): bool
    {
        return $this->video_url !== null && $this->scraped_at?->gte(now()->subDays(self::FRESH_DAYS));
    }

    /** A fresh video whose script has not been taken yet (no analysis, or one from before scripts). */
    public function scopeNeedsScript(Builder $query): Builder
    {
        return $query->withFreshVideo()->where(fn ($q) => $q->whereDoesntHave('analysis')
            ->orWhereHas('analysis', fn ($a) => $a->whereNull('transcript')));
    }

    /** Likes per 100 views. */
    public function likeRate(): ?float
    {
        return $this->play_count ? round($this->like_count / $this->play_count * 100, 2) : null;
    }

    /** Comments per 1,000 views. */
    public function commentRate(): ?float
    {
        return $this->play_count ? round($this->comment_count / $this->play_count * 1000, 2) : null;
    }

    /**
     * Latest comments that tag someone -- a person sending the reel to a
     * friend, the nearest thing to a share count Instagram lets anyone see.
     */
    public function friendTags(): int
    {
        return collect($this->comments ?? [])->filter(fn ($c) => preg_match('/@[A-Za-z0-9._]{2,}/', (string) ($c['text'] ?? '')))->count();
    }

    public function scopeHighestViewsFirst(Builder $query): Builder
    {
        return $query->orderByDesc('play_count');
    }

    public function isAnalyzed(): bool
    {
        return $this->relationLoaded('analysis')
            ? $this->analysis !== null
            : $this->analysis()->exists();
    }

    /**
     * Outperforming this ACCOUNT's own average, not a fixed view-count
     * threshold -- the same self-calibrating shape as
     * App\Support\PortfolioSuggestions's own bar. A competitor with 5M
     * followers and one with 5K need different bars, and this account's own
     * avg_views_30d (from the last scrape) is exactly that bar.
     */
    public function isViral(): bool
    {
        $average = $this->relationLoaded('account')
            ? $this->account?->avg_views_30d
            : $this->account()->value('avg_views_30d');

        return $this->play_count !== null
            && $average !== null
            && $average > 0
            && $this->play_count > $average;
    }
}
