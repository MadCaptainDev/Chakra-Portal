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
}
