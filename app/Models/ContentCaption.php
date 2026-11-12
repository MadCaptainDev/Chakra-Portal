<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One set of caption options written for a planner video. See App\Services\Ai\CaptionWriter. */
class ContentCaption extends Model
{
    protected $fillable = ['content_item_id', 'user_id', 'language', 'note', 'options', 'input_hash', 'model', 'cost_usd'];

    protected $casts = ['options' => 'array', 'cost_usd' => 'float'];

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
