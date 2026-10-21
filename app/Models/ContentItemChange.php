<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to one planner item: who, which field, from what to what, and
 * whether it was made in Chakra Planner or arrived from Notion.
 *
 * Nothing fillable -- written only through record(), the same split as
 * TodoUpdate, so a request can never claim a change was somebody else's.
 */
class ContentItemChange extends Model
{
    public const ORIGIN_PLANNER = 'planner';

    public const ORIGIN_NOTION = 'notion';

    protected $fillable = [];

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(ContentItem $item, ?User $actor, string $field, ?string $from, ?string $to, string $origin): self
    {
        $change = new self;
        $change->forceFill([
            'content_item_id' => $item->id,
            'user_id' => $actor?->id,
            'field' => $field,
            'from_value' => $from,
            'to_value' => $to,
            'origin' => $origin,
        ])->save();

        return $change;
    }
}
