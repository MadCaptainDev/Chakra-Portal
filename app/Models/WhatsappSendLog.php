<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One "Send via WhatsApp" attempt against an Invoice, a Quotation or a
 * Proposal --
 * see the migration's own doc block for why this is polymorphic.
 * Written by DocumentWhatsappNotifier for every attempt, sent or failed,
 * never only on success -- a log that skips failures cannot answer "did
 * it actually go through" any better than the single timestamp it replaced.
 */
class WhatsappSendLog extends Model
{
    /**
     * Short morph aliases for `loggable_type`, registered into
     * Relation::enforceMorphMap() by AppServiceProvider -- same convention
     * as Routine::SUBJECT_MORPH_MAP, and required for the same reason: the
     * app enforces a morph map globally, so a bare ::class here would
     * fail at write time with "No morph map defined".
     */
    public const LOGGABLE_MORPH_MAP = [
        'invoice' => Invoice::class,
        'quotation' => Quotation::class,
        'proposal' => Proposal::class,
    ];

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'loggable_type',
        'loggable_id',
        'phone',
        'template',
        'status',
        'wamid',
        'error',
        'sent_by',
    ];

    public function loggable(): MorphTo
    {
        return $this->morphTo();
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
