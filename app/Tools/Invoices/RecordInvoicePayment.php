<?php

namespace App\Tools\Invoices;

use App\Models\Invoice;
use App\Models\User;
use App\Tools\Tool;
use App\Tools\ToolException;

/**
 * Money received against an invoice: the invoice page's "Record payment"
 * form. The invoice turns paid by itself once payments cover the total
 * (PaymentObserver). Sends nothing to the client.
 */
class RecordInvoicePayment extends Tool
{
    public function name(): string
    {
        return 'record_invoice_payment';
    }

    public function title(): string
    {
        return 'Record a payment received on an invoice';
    }

    public function group(): string
    {
        return 'Invoices';
    }

    public function description(): string
    {
        return 'Record money a client paid against one of the studio\'s invoices (by number like CP-0044, or id). '
            .'amount defaults to the whole balance due, so "mark CP-0044 paid" needs only the invoice. '
            .'Refuses more than the balance, and invoices still pending approval. The invoice becomes paid '
            .'automatically when fully covered. Does not message the client.';
    }

    public function permission(): ?string
    {
        return 'invoices.edit';
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
            'invoice' => ['type' => 'string', 'description' => 'Invoice number (CP-0044) or id.'],
            'amount' => ['type' => 'number', 'description' => 'Rupees received. Defaults to the full balance due.'],
            'paid_on' => ['type' => 'string', 'format' => 'date', 'description' => 'YYYY-MM-DD. Defaults to today.'],
            'method' => ['type' => 'string', 'description' => 'Optional: UPI, bank transfer, cash, cheque…'],
            'note' => ['type' => 'string', 'description' => 'Optional, e.g. the UTR / reference.'],
        ], ['invoice']);
    }

    public function handle(array $arguments, User $user): array
    {
        $invoice = InvoiceToolSupport::invoice($arguments['invoice'] ?? '');

        if ($invoice->isPendingApproval()) {
            throw new ToolException('Approve this invoice before recording a payment (approve_invoice).');
        }

        $balance = round($invoice->balanceDue(), 2);
        if ($balance <= Invoice::AMOUNT_EPSILON) {
            throw new ToolException("{$invoice->invoice_number} is already fully paid.");
        }

        $amount = array_key_exists('amount', $arguments) && $arguments['amount'] !== null
            ? round((float) $arguments['amount'], 2)
            : $balance;
        if ($amount <= 0) {
            throw new ToolException('The amount must be more than zero.');
        }
        if ($amount > $balance + Invoice::AMOUNT_EPSILON) {
            throw new ToolException('That is more than the balance due of ₹'.number_format($balance, 2).'.');
        }

        $paidOn = filled($arguments['paid_on'] ?? null)
            ? InvoiceToolSupport::date($arguments['paid_on'], 'paid_on')
            : now()->toDateString();

        $invoice->payments()->create([
            'amount' => $amount,
            'paid_on' => $paidOn,
            'method' => filled($arguments['method'] ?? null) ? mb_substr((string) $arguments['method'], 0, 255) : null,
            'note' => filled($arguments['note'] ?? null) ? mb_substr((string) $arguments['note'], 0, 255) : null,
            'recorded_by' => $user->id,
        ]);

        return [
            'recorded' => $amount,
            'paid_on' => $paidOn,
            'invoice' => InvoiceToolSupport::describe($invoice->refresh()->load('payments')),
        ];
    }
}
