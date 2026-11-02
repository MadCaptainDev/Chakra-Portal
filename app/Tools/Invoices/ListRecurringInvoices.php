<?php

namespace App\Tools\Invoices;

use App\Models\RecurringInvoice;
use App\Models\User;
use App\Tools\ClientResolver;
use App\Tools\Tool;

class ListRecurringInvoices extends Tool
{
    public function name(): string
    {
        return 'list_recurring_invoices';
    }

    public function title(): string
    {
        return 'List recurring invoices';
    }

    public function group(): string
    {
        return 'Invoices';
    }

    public function description(): string
    {
        return 'The standing monthly (or weekly / quarterly / yearly) invoice schedules: client, lines with '
            .'their rates and quantity variables, discount, next date an invoice will be generated, and '
            .'whether it is active or paused. Optionally for one client. USE BEFORE '
            .'update_recurring_invoice, and before create_recurring_invoice to avoid a duplicate schedule. Read-only.';
    }

    public function permission(): ?string
    {
        return 'invoices.manage';
    }

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
        $query = RecurringInvoice::query()->with(['client', 'items'])->orderByDesc('is_active')->orderBy('next_run_on');

        if (filled($arguments['client'] ?? null)) {
            $query->where('client_id', ClientResolver::resolve($arguments['client'])->id);
        }

        return ['schedules' => $query->get()->map(fn (RecurringInvoice $s) => InvoiceToolSupport::describeSchedule($s))->all()];
    }
}
