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
        'monthly_report' => MonthlyReportNote::class,
    ];

    /*
     * "sent" is only Meta accepting the message. What happened next arrives
     * seconds later on the webhook -- delivered, read, or failed (most often
     * because the 24-hour window was closed) -- and applyStatus() moves the
     * row on, so a send history never says "sent" about a message that never
     * arrived.
     */
    public const STATUS_SENT = 'sent';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_READ = 'read';

    public const STATUS_FAILED = 'failed';

    /** How far along each status is; a row only ever moves forward. */
    private const PROGRESS = ['sent' => 1, 'delivered' => 2, 'read' => 3];

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

    /**
     * A delivery status from the webhook, applied to the send it is about.
     *
     * Statuses can arrive out of order, so sent/delivered/read only ever
     * move forward; failed always wins, with Meta's reason, because it is
     * the one that changes what the studio has to do.
     */
    public static function applyStatus(?string $wamid, ?string $status, ?string $error = null): void
    {
        if (! $wamid || ! $status) {
            return;
        }

        foreach (self::where('wamid', $wamid)->get() as $log) {
            if ($status === self::STATUS_FAILED) {
                $log->forceFill(['status' => self::STATUS_FAILED, 'error' => $error ?: 'WhatsApp could not deliver it.'])->save();

                continue;
            }

            $next = self::PROGRESS[$status] ?? null;
            $now = self::PROGRESS[$log->status] ?? null;

            if ($next !== null && $now !== null && $next > $now) {
                $log->forceFill(['status' => $status])->save();
            }
        }
    }

    /**
     * Meta's failure, in words the person who pressed Send can act on.
     *
     * @param  array<int, array<string, mixed>>  $errors
     */
    public static function explain(array $errors): ?string
    {
        if ($errors === []) {
            return null;
        }

        $error = $errors[0];
        $code = (int) ($error['code'] ?? 0);

        return match ($code) {
            131047 => 'Not delivered: this number has not messaged the studio in the last 24 hours, so WhatsApp only allows an approved template, not a file or free text.',
            131026 => 'Not delivered: the number is not on WhatsApp, or cannot receive this message.',
            131049 => 'Not delivered: WhatsApp held it back to protect the recipient from too many business messages. Try again later.',
            131050 => 'Not delivered: the recipient has stopped marketing messages from the studio.',
            131051 => 'Not delivered: WhatsApp does not support this message type.',
            default => trim($code.' '.($error['error_data']['details'] ?? $error['title'] ?? $error['message'] ?? 'Not delivered.')),
        };
    }
}
