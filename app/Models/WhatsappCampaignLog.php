<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per contact a campaign sent (or tried to send) to.
 *
 * status climbs pending -> sent -> delivered -> read the same way the
 * webhook's own statuses do, so a campaign's log and WhatsappWebhookEvent
 * share one badge vocabulary.
 */
class WhatsappCampaignLog extends Model
{
    protected $fillable = [
        'campaign_id',
        'contact_id',
        'phone',
        'status',
        'wamid',
        'error',
        'sent_at',
        'delivered_at',
        'read_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(WhatsappCampaign::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(WhatsappContact::class);
    }
}
