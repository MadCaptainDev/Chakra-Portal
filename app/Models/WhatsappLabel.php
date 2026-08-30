<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A tag a conversation can be filed under -- "VIP", "Needs reply", and so on. */
class WhatsappLabel extends Model
{
    protected $fillable = [
        'name',
    ];

    public function conversations(): BelongsToMany
    {
        // Explicit FK, matching the fix on the inverse side in
        // WhatsappConversation::labels() -- the pivot's columns are
        // label_id/conversation_id, not the whatsapp_-prefixed defaults.
        return $this->belongsToMany(WhatsappConversation::class, 'whatsapp_conversation_label', 'label_id', 'conversation_id');
    }
}
