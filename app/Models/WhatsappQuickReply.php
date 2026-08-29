<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved reply someone on the team can drop into a conversation without
 * retyping it.
 */
class WhatsappQuickReply extends Model
{
    protected $fillable = [
        'title',
        'type',
        'content',
        'created_by_id',
    ];

    public function created_by(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
