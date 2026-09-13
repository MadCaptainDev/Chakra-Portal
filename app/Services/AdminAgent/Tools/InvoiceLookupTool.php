<?php

namespace App\Services\AdminAgent\Tools;

use App\Models\Invoice;
use App\Models\User;
use App\Services\AdminAgent\Tool;

/**
 * One invoice by its number, or a client's recent ones.
 *
 * Answers the question the four canned figures cannot: not "how much is
 * outstanding" but "what about that Pothys invoice". Every row carries its
 * balance rather than only its total, because an invoice half paid is the
 * case somebody is usually asking about.
 */
class InvoiceLookupTool implements Tool
{
    private const LIMIT = 8;

    public function name(): string
    {
        return 'invoice_lookup';
    }

    public function definition(): array
    {
        return [
            'name' => 'invoice_lookup',
            'description' => 'Look up invoices, either by invoice number or by client id. Returns number, client, dates, total, paid, balance and status. Resolve a client name with find_client first.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'invoice_number' => [
                        'type' => 'string',
                        'description' => 'All or part of an invoice number.',
                    ],
                    'client_id' => [
                        'type' => 'integer',
                        'description' => 'Return this client\'s most recent invoices.',
                    ],
                    'unpaid_only' => [
                        'type' => 'boolean',
                        'description' => 'Only invoices with a balance still owing. Defaults to false.',
                    ],
                ],
            ],
        ];
    }

    public function run(User $admin, array $input): string
    {
        $number = trim((string) ($input['invoice_number'] ?? ''));
        $clientId = $input['client_id'] ?? null;

        if ($number === '' && $clientId === null) {
            return 'Give either an invoice number or a client id.';
        }

        $invoices = Invoice::query()
            ->with('client', 'payments')
            ->when($number !== '', fn ($query) => $query->where('invoice_number', 'like', '%'.$number.'%'))
            ->when($clientId !== null, fn ($query) => $query->where('client_id', (int) $clientId))
            ->when((bool) ($input['unpaid_only'] ?? false), fn ($query) => $query->unpaid())
            ->orderByDesc('invoice_date')
            ->limit(self::LIMIT)
            ->get();

        if ($invoices->isEmpty()) {
            return 'No invoice matches that.';
        }

        return $invoices
            ->map(fn (Invoice $invoice) => implode(' | ', array_filter([
                $invoice->invoice_number,
                $invoice->client?->name ?? 'no client',
                'dated '.$invoice->invoice_date?->format('j M Y'),
                $invoice->due_date ? 'due '.$invoice->due_date->format('j M Y') : null,
                'total '.number_format((float) $invoice->total, 0),
                'paid '.number_format($invoice->paidTotal(), 0),
                'balance '.number_format($invoice->balanceDue(), 0),
                $invoice->status,
                $invoice->isOverdue() ? 'OVERDUE' : null,
            ])))
            ->implode("\n");
    }
}
