<?php

namespace App\Models;

use App\Services\WhatsappSender;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One person a campaign or a quick reply can reach.
 *
 * phone is always stored normalised (digits only, with a country code) so
 * that import, campaign send and webhook lookups all match on it directly --
 * see the mutator below, which is the one place that guarantee is made.
 */
class WhatsappContact extends Model
{
    protected $fillable = [
        'phone',
        'name',
        'var1',
        'var2',
        'var3',
        'var4',
        'var5',
        'source',
        'opted_out_at',
        'last_interacted_at',
    ];

    protected $casts = [
        'opted_out_at' => 'datetime',
        'last_interacted_at' => 'datetime',
    ];

    public function phonebooks(): BelongsToMany
    {
        return $this->belongsToMany(
            WhatsappPhonebook::class,
            'whatsapp_contact_phonebook',
            'contact_id',
            'phonebook_id'
        );
    }

    public function campaignLogs(): HasMany
    {
        return $this->hasMany(WhatsappCampaignLog::class);
    }

    /**
     * The five positional merge fields, keyed the way a template's body
     * parameters are filled -- var1 first.
     *
     * @return array<string, ?string>
     */
    public function mergeFields(): array
    {
        return [
            'var1' => $this->var1,
            'var2' => $this->var2,
            'var3' => $this->var3,
            'var4' => $this->var4,
            'var5' => $this->var5,
        ];
    }

    /**
     * Every phone that reaches this table goes through the same
     * normalisation WhatsappSender uses to send -- whichever door a number
     * comes in through (a form, a CSV import, a seeder), it is stored the one
     * way that matches what Meta will be asked for later.
     */
    public function setPhoneAttribute(string $value): void
    {
        $this->attributes['phone'] = WhatsappSender::normalise($value);
    }
}
