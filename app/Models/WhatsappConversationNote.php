<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An internal note left on a conversation -- never sent to the contact, just
 * context one teammate leaves for whoever picks the thread up next.
 */
class WhatsappConversationNote extends Model
{
    protected $fillable = [
        'conversation_id',
        'author_id',
        'body',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsappConversation::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
