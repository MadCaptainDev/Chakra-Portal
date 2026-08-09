<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortfolioItem extends Model
{
    use HasFactory;

    /** Uploads the admin panel accepts for the video itself. */
    public const VIDEO_EXTENSIONS = ['mp4', 'webm', 'ogg', 'mov', 'm4v'];

    /** Kilobytes. 512 MB -- the host allows 1.5 GB per request. */
    public const VIDEO_MAX_KB = 512000;

    protected $fillable = [
        'portfolio_category_id',
        'title',
        'client_name',
        'description',
        'video_path',
        'video_url',
        'thumbnail_path',
        'sort_order',
        'is_featured',
        'is_visible',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_featured' => 'boolean',
        'is_visible' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(PortfolioCategory::class, 'portfolio_category_id');
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
}
