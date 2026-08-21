<?php

namespace App\Models;

use App\Support\Metric;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PortfolioItem extends Model
{
    use HasFactory;

    /** Uploads the admin panel accepts for the video itself. */
    public const VIDEO_EXTENSIONS = ['mp4', 'webm', 'ogg', 'mov', 'm4v'];

    /** Kilobytes. 512 MB -- the host allows 1.5 GB per request. */
    public const VIDEO_MAX_KB = 512000;

    /** Case-study numbers, in the order the detail screen reads them. */
    public const PERFORMANCE_FIELDS = [
        'views', 'reach', 'likes', 'comments', 'shares', 'saves', 'profile_visits', 'enquiries',
        'engagement_rate', 'completion_rate', 'watch_hours', 'avg_watch_seconds',
    ];

    public const BUSINESS_FIELDS = [
        'leads', 'whatsapp_enquiries', 'calls', 'store_visits', 'orders',
        'sales_amount', 'sales_before_amount', 'roi',
    ];

    public const BENCHMARK_FIELDS = [
        'benchmark_views', 'benchmark_reach', 'benchmark_engagements',
        'benchmark_enquiries', 'benchmark_sales_amount',
    ];

    public const CREATIVE_FIELDS = [
        'creative_hook' => 'Hook',
        'creative_concept' => 'Concept',
        'creative_storytelling' => 'Storytelling',
        'creative_cta' => 'Call to action',
        'creative_offer' => 'Offer',
        'creative_audience' => 'Audience',
    ];

    /** Single-value lists a piece points at, as field => taxonomy type. */
    public const TERM_FIELDS = [
        'platform_id' => TaxonomyTerm::TYPE_PLATFORM,
        'format_id' => TaxonomyTerm::TYPE_FORMAT,
        'objective_id' => TaxonomyTerm::TYPE_OBJECTIVE,
        'service_type_id' => TaxonomyTerm::TYPE_SERVICE,
    ];

    protected $fillable = [
        'portfolio_category_id',
        'client_id',
        'social_media_item_id',
        'platform_id',
        'format_id',
        'objective_id',
        'service_type_id',
        'title',
        'client_name',
        'description',
        'video_path',
        'video_url',
        'thumbnail_path',
        'sort_order',
        'is_featured',
        'is_visible',
        'show_business_impact',
        'summary',
        'platform',
        'format',
        'duration_seconds',
        'objective',
        'published_on',
        'before_after',
        ...self::PERFORMANCE_FIELDS,
        ...self::BUSINESS_FIELDS,
        ...self::BENCHMARK_FIELDS,
        'creative_hook',
        'creative_concept',
        'creative_storytelling',
        'creative_cta',
        'creative_offer',
        'creative_audience',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_featured' => 'boolean',
        'is_visible' => 'boolean',
        'show_business_impact' => 'boolean',
        'published_on' => 'date',
        // Not fillable -- written only by refreshFromInstagram() itself,
        // never from request input.
        'instagram_refreshed_at' => 'datetime',
        'duration_seconds' => 'integer',
        'views' => 'integer',
        'reach' => 'integer',
        'likes' => 'integer',
        'comments' => 'integer',
        'shares' => 'integer',
        'saves' => 'integer',
        'profile_visits' => 'integer',
        'enquiries' => 'integer',
        'engagement_rate' => 'float',
        'completion_rate' => 'float',
        'watch_hours' => 'float',
        'avg_watch_seconds' => 'float',
        'leads' => 'integer',
        'whatsapp_enquiries' => 'integer',
        'calls' => 'integer',
        'store_visits' => 'integer',
        'orders' => 'integer',
        'sales_amount' => 'float',
        'sales_before_amount' => 'float',
        'roi' => 'float',
        'benchmark_views' => 'integer',
        'benchmark_reach' => 'integer',
        'benchmark_engagements' => 'integer',
        'benchmark_enquiries' => 'integer',
        'benchmark_sales_amount' => 'float',
        'before_after' => 'array',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(PortfolioCategory::class, 'portfolio_category_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function socialMediaItem(): BelongsTo
    {
        return $this->belongsTo(SocialMediaItem::class);
    }

    /*
     * Named *Term rather than platform()/format()/objective(): the legacy text
     * columns of those names still exist, and Eloquent hands back the column
     * rather than the relation when the two collide -- which reads as null and
     * silently blanks the field on the public page.
     */

    public function platformTerm(): BelongsTo
    {
        return $this->belongsTo(TaxonomyTerm::class, 'platform_id');
    }

    public function formatTerm(): BelongsTo
    {
        return $this->belongsTo(TaxonomyTerm::class, 'format_id');
    }

    public function objectiveTerm(): BelongsTo
    {
        return $this->belongsTo(TaxonomyTerm::class, 'objective_id');
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(TaxonomyTerm::class, 'service_type_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(TaxonomyTerm::class, 'portfolio_item_tag')
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    /**
     * Who the work was for.
     *
     * The linked client wins; a typed name covers the piece that has no CRM
     * record -- a wedding filed as "Private", or work for a business nobody
     * has invoiced yet. Both being empty is legitimate: not every piece is
     * client work.
     */
    public function clientLabel(): ?string
    {
        return $this->client?->name ?: ($this->client_name ?: null);
    }

    /**
     * The list value, falling back to whatever was typed before this field
     * had a list. Anything the backfill could not match still reads.
     */
    public function termLabel(string $field): ?string
    {
        $relation = match ($field) {
            'platform_id' => $this->platformTerm,
            'format_id' => $this->formatTerm,
            'objective_id' => $this->objectiveTerm,
            'service_type_id' => $this->serviceType,
            default => null,
        };

        if ($relation) {
            return $relation->name;
        }

        // The legacy text column, named without the _id.
        $legacy = substr($field, 0, -3);

        return in_array($legacy, ['platform', 'format', 'objective'], true) && filled($this->{$legacy})
            ? $this->{$legacy}
            : null;
    }

    public function platformLabel(): ?string
    {
        return $this->termLabel('platform_id');
    }

    public function formatLabel(): ?string
    {
        return $this->termLabel('format_id');
    }

    /**
     * Shot vertical (a Reel) rather than the usual landscape frame.
     *
     * There is no width/height captured on upload, so this reads it off the
     * format taxonomy term instead -- see TaxonomyTerm::TYPE_FORMAT's own
     * hint, which spells out that a format containing "9:16" is what
     * switches a piece's cover to a vertical frame. One place for that
     * string match: every thumbnail (admin list, public grid, case study
     * cover, related items) calls this rather than repeating it.
     */
    public function isVertical(): bool
    {
        return str_contains((string) $this->formatLabel(), '9:16');
    }

    public function objectiveLabel(): ?string
    {
        return $this->termLabel('objective_id');
    }

    public function scopeVisible(Builder $query): void
    {
        $query->where('is_visible', true);
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderByDesc('created_at');
    }

    /**
     * Everything a visitor can actually reach: visible, and not filed under a
     * category that has been hidden.
     */
    public function scopePublished(Builder $query): void
    {
        $query->visible()->where(function (Builder $query) {
            $query->whereNull('portfolio_category_id')
                ->orWhereHas('category', fn (Builder $query) => $query->where('is_visible', true));
        });
    }

    /**
     * True when the video plays inline, from a file this app stores.
     */
    public function isUploaded(): bool
    {
        return (bool) $this->video_path;
    }

    /**
     * Where the video actually is -- the uploaded file if there is one,
     * otherwise whatever link was given. Null means "nothing to play".
     */
    public function playbackUrl(): ?string
    {
        if ($this->video_path) {
            return asset($this->video_path);
        }

        return $this->video_url ?: null;
    }

    public function thumbnailUrl(): ?string
    {
        return $this->thumbnail_path ? asset($this->thumbnail_path) : null;
    }

    /**
     * Total interactions -- the figure the platforms call "engagements".
     */
    public function engagements(): ?int
    {
        $parts = array_filter([$this->likes, $this->comments, $this->shares, $this->saves]);

        return $parts === [] ? null : (int) array_sum($parts);
    }

    /**
     * How much monthly sales moved, as a percentage. Null unless both the
     * before and after figures are on record.
     */
    public function salesGrowth(): ?float
    {
        if (! $this->hasBusinessImpact() || ! $this->sales_amount || ! $this->sales_before_amount) {
            return null;
        }

        return ($this->sales_amount - $this->sales_before_amount) / $this->sales_before_amount * 100;
    }

    /**
     * The comparison table: this piece against the client's average one.
     * Rows without a benchmark are dropped rather than shown as blanks.
     *
     * @return array<int, array{label: string, actual: string, benchmark: string, multiple: string}>
     */
    public function benchmarkRows(): array
    {
        $rows = [
            ['Views', $this->views, $this->benchmark_views, false],
            ['Reach', $this->reach, $this->benchmark_reach, false],
            ['Engagement', $this->engagements(), $this->benchmark_engagements, false],
            ['Enquiries', $this->enquiries, $this->benchmark_enquiries, false],
        ];

        // Money only joins the comparison when the money side is public.
        if ($this->hasBusinessImpact()) {
            $rows[] = ['Sales', $this->sales_amount, $this->benchmark_sales_amount, true];
        }

        $out = [];

        foreach ($rows as [$label, $actual, $benchmark, $isMoney]) {
            $multiple = Metric::multiple($actual, $benchmark);

            if ($multiple === null) {
                continue;
            }

            $out[] = [
                'label' => $label,
                'actual' => $isMoney ? Metric::money($actual) : Metric::count($actual),
                'benchmark' => $isMoney ? Metric::money($benchmark) : Metric::count($benchmark),
                'multiple' => $multiple,
            ];
        }

        return $out;
    }

    /**
     * The before/after strip, with any half-filled row left out.
     *
     * @return array<int, array{label: string, before: string, after: string}>
     */
    public function beforeAfterRows(): array
    {
        return collect($this->before_after ?? [])
            ->filter(fn ($row) => filled($row['label'] ?? null)
                && filled($row['before'] ?? null)
                && filled($row['after'] ?? null))
            ->map(fn ($row) => [
                'label' => (string) $row['label'],
                'before' => (string) $row['before'],
                'after' => (string) $row['after'],
            ])
            ->values()
            ->all();
    }

    /**
     * The creative thinking, keyed by its display label, blanks removed.
     *
     * @return array<string, string>
     */
    public function creativeNotes(): array
    {
        $notes = [];

        foreach (self::CREATIVE_FIELDS as $field => $label) {
            if (filled($this->{$field})) {
                $notes[$label] = (string) $this->{$field};
            }
        }

        return $notes;
    }

    /** True once this piece is tied to a specific cached Instagram post/reel. */
    public function isMappedToInstagram(): bool
    {
        return $this->social_media_item_id !== null;
    }

    /**
     * First-time mapping: the fields staff would otherwise have typed by
     * hand. Called once, from the controller, only when a NEW
     * social_media_item_id is being attached to this piece -- never from an
     * unattended sync (see refreshFromInstagram()), which must not clobber a
     * link or thumbnail staff have since replaced by hand while keeping the
     * piece linked.
     *
     * thumbnail_path is set by the caller: downloading it needs PublicUpload,
     * a concern this model does not have.
     */
    public function mapToInstagram(SocialMediaItem $media): void
    {
        $this->social_media_item_id = $media->id;
        $this->video_url = $media->permalink;

        if ($this->published_on === null && $media->posted_at) {
            $this->published_on = $media->posted_at->toDateString();
        }

        $this->refreshFromInstagram($media);
    }

    /**
     * Performance numbers only, from the media's cached insights. Safe to
     * call on every Instagram sync -- touches nothing staff typed by hand.
     *
     * Never touches: any BUSINESS_FIELDS (no Instagram equivalent),
     * profile_visits, enquiries, completion_rate, watch_hours (no cached
     * source for any of these), or title/description/is_visible/is_featured/
     * category/tags/client_name/creative fields (all staff-authored).
     *
     * engagement_rate is deliberately not computed here: it would need a
     * follower/reach count as of the post date, and only a CURRENT snapshot
     * (SocialAccount::followers_count) exists -- computing against today's
     * count would misrepresent an older post.
     */
    public function refreshFromInstagram(?SocialMediaItem $media = null): void
    {
        $media ??= $this->socialMediaItem;

        if (! $media) {
            return;
        }

        $map = [
            'views' => 'views',
            'reach' => 'reach',
            'likes' => 'likes',
            'comments' => 'comments',
            'shares' => 'shares',
            // Instagram's own metric key is "saved" (singular, past tense);
            // this column is "saves" -- the one name translation needed.
            'saves' => 'saved',
        ];

        foreach ($map as $field => $metric) {
            $value = $media->metricValue($metric);

            if ($value !== null) {
                $this->{$field} = $value;
            }
        }

        if ($media->isReel()) {
            $avgWatch = $media->metricValue('ig_reels_avg_watch_time');

            // Confirmed empirically against a real synced reel (11446 against
            // 112,659 views -- 11.4 seconds of average watch time, not 11446
            // seconds): Meta returns this metric in milliseconds despite the
            // name, so it is converted here rather than stored raw.
            if ($avgWatch !== null) {
                $this->avg_watch_seconds = round($avgWatch / 1000, 1);
            }
        }

        $this->instagram_refreshed_at = now();
        $this->save();
    }

    /**
     * True once there is enough on record to be worth a case-study screen --
     * otherwise the grid keeps opening the player, as it always has.
     */
    public function hasCaseStudy(): bool
    {
        return $this->hasPerformance()
            || $this->hasBusinessImpact()
            || $this->creativeNotes() !== []
            || filled($this->summary);
    }

    public function hasPerformance(): bool
    {
        return $this->anyOf(self::PERFORMANCE_FIELDS);
    }

    /**
     * Business figures exist AND we are allowed to print them. Sales and
     * return on spend are often the client's private numbers, so recording
     * them and publishing them are two separate decisions.
     */
    public function hasBusinessImpact(): bool
    {
        return $this->show_business_impact !== false && $this->anyOf(self::BUSINESS_FIELDS);
    }

    /**
     * @param  array<int, string>  $fields
     */
    private function anyOf(array $fields): bool
    {
        foreach ($fields as $field) {
            if ($this->{$field} !== null) {
                return true;
            }
        }

        return false;
    }
}
