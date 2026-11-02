<?php

namespace App\Tools\Invoices;

use App\Models\User;
use App\Tools\Tool;

class GetInvoice extends Tool
{
    public function name(): string
    {
        return 'get_invoice';
    }

    public function title(): string
    {
        return 'Read one invoice in full';
    }

    public function group(): string
    {
        return 'Invoices';
    }

    public function description(): string
    {
        return 'One invoice in full: every line (description, quantity, rate, amount), discount, total, '
            .'payments, balance due, status and dates. Find it by number (e.g. "CP-0044") or id. '
            .'USE BEFORE update_invoice, so you edit from what is really there, and to answer "what is on '
            .'Riya\'s November invoice". To search by client or find unpaid ones use invoice_lookup. Read-only.';
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
            'invoice' => ['type' => 'string', 'description' => 'Invoice number like "CP-0044", or its id.'],
        ], ['invoice']);
    }

    public function handle(array $arguments, User $user): array
    {
        return InvoiceToolSupport::describe(InvoiceToolSupport::invoice($arguments['invoice'] ?? null));
    }
}
