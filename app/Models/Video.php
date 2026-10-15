<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One video, tying its script, shoot, footage, editing, post and numbers
 * together. See VideoLinker for how the links are made and kept.
 *
 * Deliberately holds no Notion field: the title, status and dates are read
 * from the content item, so Notion stays the owner.
 */
class Video extends Model
{
    protected $guarded = [];

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function script(): BelongsTo
    {
        return $this->belongsTo(Script::class);
    }

    public function shoot(): BelongsTo
    {
        return $this->belongsTo(Shoot::class);
    }

    public function shootVideo(): BelongsTo
    {
        return $this->belongsTo(ShootVideo::class);
    }

    public function todo(): BelongsTo
    {
        return $this->belongsTo(Todo::class);
    }

    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(SocialMediaItem::class, 'social_media_item_id');
    }

    /** What it was planned as, from Notion: reel, post, story or youtube. */
    public function plannedAs(): ?string
    {
        return $this->contentItem?->source;
    }

    /** Planned as a reel but went out as something else. */
    public function hasFormatMismatch(): bool
    {
        return $this->plannedAs() === ContentItem::SOURCE_REEL
            && $this->posted_as !== null
            && $this->posted_as !== 'reel';
    }
}
