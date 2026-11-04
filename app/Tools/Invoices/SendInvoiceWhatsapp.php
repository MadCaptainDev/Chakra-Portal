<?php

namespace App\Tools\Invoices;

use App\Models\User;
use App\Services\InvoiceWhatsappSender;
use App\Tools\Tool;
use App\Tools\ToolException;
use RuntimeException;

/** The invoice page's "Send on WhatsApp": the approved invoice_ready template with a no-login PDF link. */
class SendInvoiceWhatsapp extends Tool
{
    public function name(): string
    {
        return 'send_invoice_whatsapp';
    }

    public function title(): string
    {
        return 'Send an invoice to the client on WhatsApp';
    }

    public function group(): string
    {
        return 'Invoices';
    }

    public function description(): string
    {
        return 'Send an approved invoice to a client on WhatsApp from the studio number (template message with '
            .'a PDF link). THIS MESSAGES A REAL CLIENT. Only when the person has explicitly asked to send this '
            .'invoice. Phone defaults to the client\'s number on file.';
    }

    public function permission(): ?string
    {
        return 'invoices.approve';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function messagesClient(): bool
    {
        return true;
    }

    public function mcpOnly(): bool
    {
        return true;
    }

    public function schema(): array
    {
        return $this->object([
            'invoice' => ['type' => 'string', 'description' => 'Invoice number (CP-0044) or id.'],
            'phone' => ['type' => 'string', 'description' => 'Optional. Only if the person gave a different number than the client\'s on file.'],
        ], ['invoice']);
    }

    public function handle(array $arguments, User $user): array
    {
        $invoice = InvoiceToolSupport::invoice($arguments['invoice'] ?? '');
        $invoice->loadMissing('client');

        if (! $invoice->isSendableViaWhatsapp()) {
            throw new ToolException('This invoice cannot be sent over WhatsApp until it is approved.');
        }

        $phone = trim((string) ($arguments['phone'] ?? '')) ?: (string) $invoice->client?->phone;
        if (! preg_match('/^[0-9+\-\s()]{10,20}$/', $phone)) {
            throw new ToolException($phone === ''
                ? 'The client has no phone number on file — ask the person for one.'
                : '"'.$phone.'" does not look like a phone number.');
        }

        try {
            app(InvoiceWhatsappSender::class)->send($invoice, $phone, $user->id);
        } catch (RuntimeException $e) {
            throw new ToolException('WhatsApp did not send it: '.$e->getMessage());
        }

        return [
            'sent' => true,
            'to' => $phone,
            'client' => $invoice->client?->name,
            'invoice' => $invoice->invoice_number,
            'total' => (float) $invoice->total,
            'note' => '"sent" means WhatsApp accepted it; delivery is confirmed separately.',
        ];
    }
}
