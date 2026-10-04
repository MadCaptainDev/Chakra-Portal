<?php

namespace App\Tools\Quotations;

use App\Models\User;
use App\Services\Quotations\QuotationCreator;
use App\Tools\ClientResolver;
use App\Tools\Tool;
use App\Tools\ToolException;

class CreateQuotation extends Tool
{
    public function name(): string
    {
        return 'create_quotation';
    }

    public function title(): string
    {
        return 'Create a draft quotation';
    }

    public function group(): string
    {
        return 'Quotations';
    }

    public function description(): string
    {
        return 'Create a DRAFT quotation for a client from line items (description, quantity, unit price '
            .'in rupees). The portal numbers it, works out every line total and the grand total, and '
            .'returns them -- read them back to the person. It is not sent to anyone; use '
            .'send_quotation_whatsapp for that, only when asked. '
            .'Never invent prices: every unit_price must come from the person or from a price they '
            .'gave earlier. notes = one point per line (scope, timeline, payment terms).';
    }

    public function permission(): ?string
    {
        return 'quotations.create';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function mcpOnly(): bool
    {
        return true;
    }

    public function schema(): array
    {
        return $this->object([
            'client' => ['type' => 'string', 'description' => 'Client id or portal name.'],
            'items' => [
                'type' => 'array',
                'minItems' => 1,
                'description' => 'Line items, in order.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'description' => ['type' => 'string'],
                        'quantity' => ['type' => 'number', 'description' => 'More than 0.'],
                        'unit_price' => ['type' => 'number', 'description' => 'Rupees, 0 or more.'],
                    ],
                    'required' => ['description', 'quantity', 'unit_price'],
                    'additionalProperties' => false,
                ],
            ],
            'quotation_date' => ['type' => 'string', 'description' => 'Optional. YYYY-MM-DD; defaults to today.'],
            'valid_until' => ['type' => 'string', 'description' => 'Optional. YYYY-MM-DD, on or after the quotation date.'],
            'intro_text' => ['type' => 'string', 'description' => 'Optional. Opening line on the quotation.'],
            'notes' => ['type' => 'string', 'description' => 'Optional. One point per line.'],
            'discount_label' => ['type' => 'string', 'description' => 'Optional; required if discount_amount is given.'],
            'discount_amount' => ['type' => 'number', 'description' => 'Optional. Rupees off the subtotal.'],
            'app_studio' => ['type' => 'boolean', 'description' => 'Optional. true if this is Chakra App Studio (software) work rather than Chakra Productions.'],
        ], ['client', 'items']);
    }

    public function handle(array $arguments, User $user): array
    {
        $client = ClientResolver::resolve($arguments['client'] ?? null);

        $items = $arguments['items'] ?? [];
        if (! is_array($items) || $items === []) {
            throw new ToolException('A quotation needs at least one line item.');
        }

        foreach (array_values($items) as $i => $item) {
            $n = $i + 1;
            if (! is_array($item) || blank($item['description'] ?? null)) {
                throw new ToolException("Line {$n} needs a description.");
            }
            if (! is_numeric($item['quantity'] ?? null) || (float) $item['quantity'] <= 0) {
                throw new ToolException("Line {$n}: quantity must be more than 0.");
            }
            if (! is_numeric($item['unit_price'] ?? null) || (float) $item['unit_price'] < 0) {
                throw new ToolException("Line {$n}: unit_price must be a number of rupees.");
            }
        }

        $date = (string) ($arguments['quotation_date'] ?? today()->toDateString());
        $validUntil = $arguments['valid_until'] ?? null;

        foreach (['quotation_date' => $date, 'valid_until' => $validUntil] as $field => $value) {
            if ($value !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value)) {
                throw new ToolException("{$field} must be YYYY-MM-DD.");
            }
        }
        if ($validUntil !== null && $validUntil < $date) {
            throw new ToolException('valid_until is before the quotation date.');
        }

        $discount = $arguments['discount_amount'] ?? null;
        if ($discount !== null && (! is_numeric($discount) || (float) $discount < 0)) {
            throw new ToolException('discount_amount must be a number of rupees.');
        }
        if ($discount !== null && blank($arguments['discount_label'] ?? null)) {
            throw new ToolException('Give a discount_label to go with the discount, e.g. "Launch discount".');
        }

        $quotation = app(QuotationCreator::class)->create([
            'client_id' => $client->id,
            'is_app_studio' => ($arguments['app_studio'] ?? false) === true,
            'quotation_date' => $date,
            'valid_until' => $validUntil,
            'intro_text' => $arguments['intro_text'] ?? null,
            'notes' => $arguments['notes'] ?? null,
            'discount_label' => $arguments['discount_label'] ?? null,
            'discount_amount' => $discount,
            'items' => array_values($items),
        ], $user->id);

        return [
            'created' => true,
            'status' => 'draft (not sent)',
            'quotation_id' => $quotation->id,
            'number' => $quotation->quotation_number,
            'client' => $client->name,
            'lines' => $quotation->items->map(fn ($l) => [
                'description' => $l->description,
                'quantity' => (float) $l->quantity,
                'unit_price' => (float) $l->unit_price,
                'line_total' => (float) $l->line_total,
            ])->all(),
            'subtotal' => (float) $quotation->subtotal,
            'discount' => $discount === null ? null : (float) $discount,
            'total' => (float) $quotation->total,
            'review_in_portal' => route('quotations.show', $quotation),
        ];
    }
}
