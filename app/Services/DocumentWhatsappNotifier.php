<?php

namespace App\Services;

use App\Models\WhatsappSendLog;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Sends a "your document is ready" WhatsApp message on behalf of an
 * Invoice, a Quotation or a Proposal, and writes a WhatsappSendLog row for
 * the attempt either way -- the caller only has to decide what to tell the
 * user, not whether to log.
 *
 * $document just needs a whatsappLogs() morphMany relation; it stays
 * untyped against a concrete class so the documents share this without
 * depending on each other.
 */
class DocumentWhatsappNotifier
{
    /** What a free-text send is logged as, where a template name would go. */
    public const TEXT_TEMPLATE = 'text';

    /**
     * @param  array<int, string>  $bodyParameters
     *
     * @throws RuntimeException the same one WhatsappSender threw -- the log
     *                          is already written by the time this rethrows,
     *                          so the caller only needs the message to flash.
     */
    public function send(
        Model $document,
        string $phone,
        string $template,
        array $bodyParameters,
        ?string $buttonUrlParameter,
        ?int $sentByUserId,
    ): void {
        try {
            $result = WhatsappSender::make()->sendTemplate(
                to: $phone,
                template: $template,
                bodyParameters: $bodyParameters,
                buttonUrlParameter: $buttonUrlParameter,
            );

            $document->whatsappLogs()->create([
                'phone' => $phone,
                'template' => $template,
                'status' => WhatsappSendLog::STATUS_SENT,
                'wamid' => $result['wamid'] ?? null,
                'sent_by' => $sentByUserId,
            ]);
        } catch (RuntimeException $e) {
            $document->whatsappLogs()->create([
                'phone' => $phone,
                'template' => $template,
                'status' => WhatsappSendLog::STATUS_FAILED,
                'error' => $e->getMessage(),
                'sent_by' => $sentByUserId,
            ]);

            throw $e;
        }
    }

    /**
     * Free-form text instead of a template -- only deliverable inside the
     * 24-hour window after the recipient last messaged the studio (see
     * WhatsappServiceWindow), so callers check that first. Logged exactly
     * like a template send, under TEXT_TEMPLATE in the template column.
     *
     * @throws RuntimeException
     */
    public function sendText(Model $document, string $phone, string $body, ?int $sentByUserId): void
    {
        try {
            $result = WhatsappSender::make()->sendText($phone, $body);

            $document->whatsappLogs()->create([
                'phone' => $phone,
                'template' => self::TEXT_TEMPLATE,
                'status' => WhatsappSendLog::STATUS_SENT,
                'wamid' => $result['wamid'] ?? null,
                'sent_by' => $sentByUserId,
            ]);
        } catch (RuntimeException $e) {
            $document->whatsappLogs()->create([
                'phone' => $phone,
                'template' => self::TEXT_TEMPLATE,
                'status' => WhatsappSendLog::STATUS_FAILED,
                'error' => $e->getMessage(),
                'sent_by' => $sentByUserId,
            ]);

            throw $e;
        }
    }
}
