<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A named list of contacts a campaign can be sent to.
 */
class WhatsappPhonebook extends Model
{
    protected $fillable = [
        'name',
        'description',
    ];

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(
            WhatsappContact::class,
            'whatsapp_contact_phonebook',
            'phonebook_id',
            'contact_id'
        );
    }

    public function contactsCount(): int
    {
        return $this->contacts()->count();
    }
}
