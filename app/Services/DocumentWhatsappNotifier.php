<?php

namespace App\Services;

use App\Models\WhatsappSendLog;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Sends a "your document is ready" WhatsApp template on behalf of an
 * Invoice or a Quotation, and writes a WhatsappSendLog row for the
 * attempt either way -- the caller only has to decide what to tell the
 * user, not whether to log.
 *
 * $document just needs a whatsappLogs() morphMany relation; it stays
 * untyped against a concrete class so Invoice and Quotation share this
 * without either depending on the other.
 */
class DocumentWhatsappNotifier
{
    /**
     * @param  array<int, string>  $bodyParameters
     *
     * @throws RuntimeException  the same one WhatsappSender threw -- the log
     *                           is already written by the time this rethrows,
     *                           so the caller only needs the message to flash.
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
}
