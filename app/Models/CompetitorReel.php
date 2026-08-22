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
    ];

    protected $casts = [
        'play_count' => 'integer',
        'like_count' => 'integer',
        'comment_count' => 'integer',
        'posted_at' => 'datetime',
        'scraped_at' => 'datetime',
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
