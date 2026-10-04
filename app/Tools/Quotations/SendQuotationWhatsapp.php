<?php

namespace App\Tools\Quotations;

use App\Models\Quotation;
use App\Models\User;
use App\Services\DocumentWhatsappNotifier;
use App\Tools\Tool;
use App\Tools\ToolException;
use RuntimeException;

class SendQuotationWhatsapp extends Tool
{
    public function name(): string
    {
        return 'send_quotation_whatsapp';
    }

    public function title(): string
    {
        return 'Send a quotation to the client on WhatsApp';
    }

    public function group(): string
    {
        return 'Quotations';
    }

    public function description(): string
    {
        return 'Send a quotation\'s PDF link to a client on WhatsApp from the studio number, using the '
            .'approved quotation_ready template (number, client name and total in the message). '
            .'THIS MESSAGES A REAL CLIENT. Only call it when the person has explicitly asked to send this '
            .'quotation, after they have checked its lines and total. Phone defaults to the client\'s on file.';
    }

    public function permission(): ?string
    {
        return 'quotations.edit';
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
            'quotation_id' => ['type' => 'integer', 'description' => 'From create_quotation or list_quotations.'],
            'phone' => ['type' => 'string', 'description' => 'Optional. Only if the person gave a different number than the client\'s on file.'],
        ], ['quotation_id']);
    }

    public function handle(array $arguments, User $user): array
    {
        $quotation = Quotation::with('client')->find((int) ($arguments['quotation_id'] ?? 0))
            ?? throw new ToolException('There is no quotation with that id. Use list_quotations.');

        $phone = trim((string) ($arguments['phone'] ?? '')) ?: (string) $quotation->client?->phone;

        if (! preg_match('/^[0-9+\-\s()]{10,20}$/', $phone)) {
            throw new ToolException($phone === ''
                ? 'The client has no phone number on file — ask the person for one.'
                : '"'.$phone.'" does not look like a phone number.');
        }

        try {
            app(DocumentWhatsappNotifier::class)->send(
                document: $quotation,
                phone: $phone,
                template: Quotation::WHATSAPP_TEMPLATE,
                bodyParameters: [
                    $quotation->client->name,
                    $quotation->quotation_number ?? '',
                    number_format((float) $quotation->total, 2),
                ],
                buttonUrlParameter: $quotation->ensurePublicToken(),
                sentByUserId: $user->id,
            );
        } catch (RuntimeException $e) {
            throw new ToolException('WhatsApp did not send it: '.$e->getMessage());
        }

        $quotation->update(['whatsapp_sent_at' => now()]);

        return [
            'sent' => true,
            'to' => $phone,
            'quotation' => $quotation->quotation_number,
            'total' => (float) $quotation->total,
            'note' => '"sent" means WhatsApp accepted it; delivery is confirmed separately.',
            'client_link' => $quotation->publicUrl(),
        ];
    }
}
