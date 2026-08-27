<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One post, reel or carousel cached from a connected social account.
 *
 * See the migration for why this is cached rather than fetched live, and why
 * it is platform-agnostic rather than an instagram_media table.
 */
class SocialMediaItem extends Model
{
    public const TYPE_IMAGE = 'IMAGE';

    public const TYPE_VIDEO = 'VIDEO';

    public const TYPE_CAROUSEL = 'CAROUSEL_ALBUM';

    public const PRODUCT_REELS = 'REELS';

    public const PRODUCT_STORY = 'STORY';

    public const PRODUCT_FEED = 'FEED';

    protected $guarded = [];

    protected $casts = [
        'posted_at' => 'datetime',
        'cached_at' => 'datetime',
    ];

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function insights(): HasMany
    {
        return $this->hasMany(SocialInsight::class);
    }

    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('posted_at');
    }

    public function isReel(): bool
    {
        return $this->media_product_type === self::PRODUCT_REELS;
    }

    /** "Reel", "Carousel", "Photo" -- what a person calls it, not what the API calls it. */
    public function typeLabel(): string
    {
        if ($this->isReel()) {
            return 'Reel';
        }

        return match ($this->media_type) {
            self::TYPE_CAROUSEL => 'Carousel',
            self::TYPE_VIDEO => 'Video',
            default => 'Photo',
        };
    }

    /** The caption, cut to a line -- the table this feeds has five other columns. */
    public function shortCaption(int $length = 60): string
    {
        return $this->caption ? Str::limit(trim($this->caption), $length) : '(no caption)';
    }

    /** One insight's value for this item, or null if it was never captured. */
    public function metricValue(string $metric): ?int
    {
        return $this->relationLoaded('insights')
            ? $this->insights->firstWhere('metric', $metric)?->value
            : $this->insights()->where('metric', $metric)->value('value');
    }

    /**
     * A reel's average per-view watch time, in seconds. Null for anything
     * that isn't a reel, or if the metric was never cached.
     *
     * Meta returns ig_reels_avg_watch_time in milliseconds despite the name
     * -- confirmed empirically against a real synced reel (11446 against
     * 112,659 views, i.e. 11.4 seconds, not 11,446 seconds). Same
     * conversion PortfolioItem::refreshFromInstagram already applies when
     * copying this onto a published case study.
     */
    public function reelAvgWatchSeconds(): ?float
    {
        if (! $this->isReel()) {
            return null;
        }

        $ms = $this->metricValue('ig_reels_avg_watch_time');

        return $ms !== null ? round($ms / 1000, 1) : null;
    }

    /**
     * Total time every viewer combined spent watching this reel, in seconds.
     *
     * Unlike avg watch time above, the millisecond unit here is INFERRED,
     * not independently confirmed against a real value -- ig_reels_video_view
     * _total_time is the same metric family (Meta's reels-only "watch time"
     * group) and follows the same "_time" naming as the confirmed one, but
     * has not itself been checked against a real synced reel. Revisit if a
     * displayed value looks implausible (e.g. a total time shorter than the
     * reel's own average watch time times its view count).
     */
    public function reelTotalWatchSeconds(): ?float
    {
        if (! $this->isReel()) {
            return null;
        }

        $ms = $this->metricValue('ig_reels_video_view_total_time');

        return $ms !== null ? round($ms / 1000, 1) : null;
    }
}
