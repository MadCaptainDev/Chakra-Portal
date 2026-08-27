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

    /**
     * One insight's CURRENT value for this item, or null if it was never
     * captured.
     *
     * A media item's insights are not one row per metric -- every daily sync
     * (see InstagramInsights::storeMediaResponse) writes a fresh row keyed to
     * that sync's own date, since Meta only ever answers with the post's
     * current lifetime total, not a history. A post synced on ten different
     * days therefore has ten rows for "reach", each an honest reading of what
     * reach was as of that sync -- which is also, deliberately, the raw
     * material for a per-post growth graph (see metricHistory() below and
     * InstagramReportData::mediaGrowth()).
     *
     * This method answers "what is it right now", so it wants the newest of
     * those rows. Before this fix it took whichever row the query or the
     * eager-loaded collection happened to return first -- unordered on both
     * paths, which in practice meant the OLDEST synced value on a plain
     * MySQL id-ordered read, not the current one. Every caller of this
     * method (Content Performance, Monthly Report, portfolio suggestions,
     * the creative generator) was reading a number frozen at whatever it was
     * the first time the post was ever synced.
     */
    public function metricValue(string $metric): ?int
    {
        if ($this->relationLoaded('insights')) {
            return $this->insights
                ->where('metric', $metric)
                ->sortBy([
                    fn (SocialInsight $a, SocialInsight $b) => $b->period_start <=> $a->period_start,
                    fn (SocialInsight $a, SocialInsight $b) => $b->id <=> $a->id,
                ])
                ->first()?->value;
        }

        return $this->insights()
            ->where('metric', $metric)
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->value('value');
    }

    /**
     * One metric's full synced history for this item, oldest first -- the
     * day-by-day growth a graph draws. Same rows metricValue() reads, just
     * every one of them instead of only the newest.
     *
     * Unlike an account-level trend (InstagramReportData::trend(), one row
     * guaranteed per calendar day because the generator asks Instagram for a
     * time series), this has a gap for any day the account was not synced --
     * deliberately not filled in with a repeated flat value, since that would
     * draw a growth line implying data that was never actually fetched.
     *
     * Reads the eager-loaded 'insights' relation when it's already there
     * rather than always issuing its own query, for exactly the reason
     * PortfolioSuggestions and Content Performance already eager-load it for
     * metricValue(): Content Performance calls this per metric, per item,
     * for up to 200 items to build every post's growth charts -- without
     * this branch, that is up to 1,600 extra queries on a single page load
     * instead of the one query ->with('insights') already paid for.
     *
     * @return list<array{date: string, value: int}>
     */
    public function metricHistory(string $metric): array
    {
        $rows = $this->relationLoaded('insights')
            ? $this->insights->where('metric', $metric)->sortBy('period_start')->values()
            : $this->insights()->where('metric', $metric)->orderBy('period_start')->get(['value', 'period_start']);

        return $rows
            ->map(fn (SocialInsight $row) => [
                'date' => $row->period_start->toDateString(),
                'value' => (int) $row->value,
            ])
            ->all();
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
