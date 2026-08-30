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
        return $this->belongsToMany(WhatsappConversation::class, 'whatsapp_conversation_label');
    }
}
