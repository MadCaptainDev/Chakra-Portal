<?php

namespace App\Tools\Invoices;

use App\Models\User;
use App\Tools\Tool;
use App\Tools\ToolException;

/** The invoice page's Approve button: a pending draft gets its number and becomes unpaid. */
class ApproveInvoice extends Tool
{
    public function name(): string
    {
        return 'approve_invoice';
    }

    public function title(): string
    {
        return 'Approve an invoice';
    }

    public function group(): string
    {
        return 'Invoices';
    }

    public function description(): string
    {
        return 'Approve an invoice that is pending approval (e.g. one a recurring schedule drafted): it gets its '
            .'invoice number and becomes unpaid, ready to send. Check it with get_invoice first. Sends nothing.';
    }

    public function permission(): ?string
    {
        return 'invoices.approve';
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
            'invoice' => ['type' => 'string', 'description' => 'Invoice id (a pending one has no number yet).'],
        ], ['invoice']);
    }

    public function handle(array $arguments, User $user): array
    {
        $invoice = InvoiceToolSupport::invoice($arguments['invoice'] ?? '');

        if (! $invoice->isPendingApproval()) {
            throw new ToolException('That invoice is not pending approval.');
        }

        $invoice->approve();

        return ['approved' => true, 'invoice' => InvoiceToolSupport::describe($invoice->refresh())];
    }
}
