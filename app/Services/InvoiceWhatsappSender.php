<?php

namespace App\Services;

use App\Models\Invoice;
use RuntimeException;

/**
 * Sending an invoice to a client on WhatsApp: the invoice_ready template with
 * a button to the no-login PDF link. One path for both the invoice page and
 * the Studio Map's "Re-send invoice", so the two cannot drift.
 *
 * Always the template, never free text -- unlike proposals and briefs -- because
 * the button carrying the invoice link only exists on the template.
 */
class InvoiceWhatsappSender
{
    public function __construct(private DocumentWhatsappNotifier $notifier) {}

    /**
     * @throws RuntimeException with Meta's own reason when it refuses
     */
    public function send(Invoice $invoice, string $phone, ?int $userId): void
    {
        $invoice->loadMissing('client');

        if (! $invoice->isSendableViaWhatsapp()) {
            throw new RuntimeException('This invoice cannot be sent over WhatsApp until it is approved.');
        }

        $this->notifier->send(
            document: $invoice,
            phone: $phone,
            template: Invoice::WHATSAPP_TEMPLATE,
            bodyParameters: self::parameters($invoice),
            // The button's dynamic segment is just the token -- the template's
            // own static base URL supplies the rest (see SeedInvoiceReadyTemplate),
            // so this must not be the full invoice->publicUrl().
            buttonUrlParameter: $invoice->ensurePublicToken(),
            sentByUserId: $userId,
        );

        $invoice->update(['whatsapp_sent_at' => now()]);
    }

    /** What the client will read -- SeedInvoiceReadyTemplate's body, filled in. */
    public static function previewText(Invoice $invoice): string
    {
        [$name, $number, $total] = self::parameters($invoice);

        return "Hi {$name}, your invoice {$number} for Rs. {$total} is ready. Tap below to view or download it.";
    }

    /** @return array{0: string, 1: string, 2: string} */
    private static function parameters(Invoice $invoice): array
    {
        return [$invoice->client->name, $invoice->invoice_number, number_format((float) $invoice->total, 2)];
    }
}
