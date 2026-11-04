<?php

namespace App\Tools\Invoices;

use App\Models\Invoice;
use App\Models\User;
use App\Tools\ClientResolver;
use App\Tools\Tool;

/** The Invoices screen as data: filterable rows with totals, for the mobile admin area and anyone else. */
class ListInvoices extends Tool
{
    private const STATUSES = ['open', 'overdue', 'partial', 'unpaid', 'pending_approval', 'paid', 'all'];

    public function name(): string
    {
        return 'list_invoices';
    }

    public function title(): string
    {
        return 'List invoices';
    }

    public function group(): string
    {
        return 'Invoices';
    }

    public function description(): string
    {
        return 'The studio\'s invoices as rows: number, client, status (pending_approval / unpaid / partial / '
            .'overdue / paid), dates, total, paid and balance. status filter: open (default: everything still '
            .'owed, overdue included), overdue, partial, unpaid, pending_approval, paid, all. Newest first. '
            .'Use get_invoice for the lines of one.';
    }

    public function permission(): ?string
    {
        return 'invoices.view';
    }

    public function mcpOnly(): bool
    {
        return true;
    }

    public function schema(): array
    {
        return $this->object([
            'status' => ['type' => 'string', 'enum' => self::STATUSES, 'description' => 'Default open.'],
            'client' => ['type' => 'string', 'description' => 'Optional. Client id or portal name.'],
            'from' => ['type' => 'string', 'format' => 'date', 'description' => 'Optional. Invoice date on or after, YYYY-MM-DD.'],
            'to' => ['type' => 'string', 'format' => 'date', 'description' => 'Optional. Invoice date on or before, YYYY-MM-DD.'],
            'search' => ['type' => 'string', 'description' => 'Optional. Part of the invoice number.'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Default 30.'],
        ]);
    }

    public function handle(array $arguments, User $user): array
    {
        $status = in_array($arguments['status'] ?? null, self::STATUSES, true) ? $arguments['status'] : 'open';
        $limit = max(1, min(100, (int) ($arguments['limit'] ?? 30)));

        $query = Invoice::query()->with(['client:id,name,logo_path', 'payments:id,invoice_id,amount']);

        match ($status) {
            'open' => $query->unpaid(),
            'overdue' => $query->overdue(),
            'partial' => $query->partiallyPaid(),
            'unpaid' => $query->unpaid(),
            'pending_approval' => $query->pendingApproval(),
            'paid' => $query->paid(),
            'all' => null,
        };

        if (filled($arguments['client'] ?? null)) {
            $query->where('client_id', ClientResolver::resolve($arguments['client'])->id);
        }
        if (filled($arguments['from'] ?? null)) {
            $query->whereDate('invoice_date', '>=', InvoiceToolSupport::date($arguments['from'], 'from'));
        }
        if (filled($arguments['to'] ?? null)) {
            $query->whereDate('invoice_date', '<=', InvoiceToolSupport::date($arguments['to'], 'to'));
        }
        if (filled($arguments['search'] ?? null)) {
            $query->where('invoice_number', 'like', '%'.trim((string) $arguments['search']).'%');
        }

        $matching = (clone $query)->count();
        $rows = $query->orderByDesc('invoice_date')->orderByDesc('id')->limit($limit)->get();

        $items = $rows->map(fn (Invoice $invoice) => [
            'invoice_id' => $invoice->id,
            'number' => $invoice->invoice_number,
            'client' => $invoice->client?->name,
            'client_id' => $invoice->client_id,
            'client_logo_url' => $invoice->client?->logoUrl(),
            'status' => $invoice->displayStatus(),
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'days_overdue' => $invoice->isOverdue() ? (int) $invoice->due_date->diffInDays(today()) : 0,
            'total' => (float) $invoice->total,
            'paid' => round($invoice->paidTotal(), 2),
            'balance_due' => round($invoice->balanceDue(), 2),
        ])->values();

        return [
            'status' => $status,
            'count' => $matching,
            'shown' => $items->count(),
            'balance_due_shown' => round($items->sum('balance_due'), 2),
            'invoices' => $items->all(),
        ];
    }
}
