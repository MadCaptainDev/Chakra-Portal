<?php

namespace App\Tools\Quotations;

use App\Models\Quotation;
use App\Models\User;
use App\Tools\ClientResolver;
use App\Tools\Tool;

class ListQuotations extends Tool
{
    public function name(): string
    {
        return 'list_quotations';
    }

    public function title(): string
    {
        return 'List quotations';
    }

    public function group(): string
    {
        return 'Quotations';
    }

    public function description(): string
    {
        return 'Recent quotations with number, client, total, status (draft / accepted / rejected / '
            .'expired), validity and whether it was sent on WhatsApp. Optionally for one client. '
            .'USE WHEN asked "what quotes are open", "did we quote Riya", or to find a quotation id '
            .'before sending it. Read-only.';
    }

    public function permission(): ?string
    {
        return 'quotations.view';
    }

    // Kept off the WhatsApp assistant: its token budget is spent on every message.
    public function mcpOnly(): bool
    {
        return true;
    }

    public function schema(): array
    {
        return $this->object([
            'client' => ['type' => 'string', 'description' => 'Optional. Client id or portal name.'],
        ]);
    }

    public function handle(array $arguments, User $user): array
    {
        $query = Quotation::query()->with('client')->latest('quotation_date')->latest('id');

        if (filled($arguments['client'] ?? null)) {
            $query->where('client_id', ClientResolver::resolve($arguments['client'])->id);
        }

        return [
            'quotations' => $query->limit(30)->get()->map(fn (Quotation $q) => [
                'quotation_id' => $q->id,
                'number' => $q->quotation_number,
                'client' => $q->client?->name,
                'date' => $q->quotation_date?->toDateString(),
                'valid_until' => $q->valid_until?->toDateString(),
                'total' => (float) $q->total,
                'status' => $q->displayStatus(),
                'whatsapp_sent_at' => $q->whatsapp_sent_at?->format('Y-m-d H:i'),
            ])->all(),
        ];
    }
}
