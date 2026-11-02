<?php

namespace App\Tools\Invoices;

use App\Models\User;
use App\Services\Invoices\InvoiceWriter;
use App\Tools\ClientResolver;
use App\Tools\Tool;
use App\Tools\ToolException;
use Illuminate\Support\Facades\DB;

class UpdateInvoice extends Tool
{
    public function name(): string
    {
        return 'update_invoice';
    }

    public function title(): string
    {
        return 'Edit an invoice';
    }

    public function group(): string
    {
        return 'Invoices';
    }

    public function description(): string
    {
        return 'Change an existing invoice: its lines, discount, dates, intro text or client. Only what you '
            .'pass changes. "items" REPLACES every line, so call get_invoice first and send the full list '
            .'with your change in it. To remove the discount pass discount_amount 0 and discount_label "". '
            .'Refuses to bring the total below what the client has already paid, and to move an invoice '
            .'with payments to another client. Returns the invoice before and after -- read the new total '
            .'back. Nothing is sent to the client.';
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
            'invoice' => ['type' => 'string', 'description' => 'Invoice number like "CP-0044", or its id.'],
            'items' => InvoiceToolSupport::itemsSchema(recurring: false),
            'invoice_date' => ['type' => 'string', 'description' => 'Optional. YYYY-MM-DD.'],
            'due_date' => ['type' => 'string', 'description' => 'Optional. YYYY-MM-DD, or "" to clear it.'],
            'intro_text' => ['type' => 'string', 'description' => 'Optional.'],
            'discount_label' => ['type' => 'string', 'description' => 'Optional. "" with discount_amount 0 removes the discount.'],
            'discount_amount' => ['type' => 'number', 'description' => 'Optional. Rupees off the subtotal.'],
            'client' => ['type' => 'string', 'description' => 'Optional. Only to move the invoice to another client.'],
        ], ['invoice']);
    }

    public function handle(array $arguments, User $user): array
    {
        $invoice = InvoiceToolSupport::invoice($arguments['invoice'] ?? null);
        $before = InvoiceToolSupport::describe($invoice);
        $data = [];

        if (isset($arguments['client'])) {
            $client = ClientResolver::resolve($arguments['client']);
            if ($client->id !== $invoice->client_id && $invoice->payments()->exists()) {
                throw new ToolException('This invoice has payments recorded, so it cannot move to another client. Ask the person to sort it out in the portal.');
            }
            $data['client_id'] = $client->id;
        }

        if (isset($arguments['invoice_date'])) {
            $data['invoice_date'] = InvoiceToolSupport::date($arguments['invoice_date'], 'invoice_date');
        }
        if (array_key_exists('due_date', $arguments)) {
            $data['due_date'] = $arguments['due_date'] === '' || $arguments['due_date'] === null ? null : InvoiceToolSupport::date($arguments['due_date'], 'due_date');
        }
        $date = $data['invoice_date'] ?? $invoice->invoice_date->toDateString();
        $due = array_key_exists('due_date', $data) ? $data['due_date'] : $invoice->due_date?->toDateString();
        if ($due !== null && $due < $date) {
            throw new ToolException('due_date would be before invoice_date.');
        }

        if (array_key_exists('intro_text', $arguments)) {
            $data['intro_text'] = $arguments['intro_text'];
        }

        if (array_key_exists('discount_label', $arguments) || array_key_exists('discount_amount', $arguments)) {
            $removing = ($arguments['discount_label'] ?? null) === '' && (float) ($arguments['discount_amount'] ?? 0) === 0.0;
            $data += $removing
                ? ['discount_label' => null, 'discount_amount' => null]
                : InvoiceToolSupport::discount([
                    'discount_label' => $arguments['discount_label'] ?? $invoice->discount_label,
                    'discount_amount' => $arguments['discount_amount'] ?? $invoice->discount_amount,
                ]);
        }

        if (array_key_exists('items', $arguments)) {
            $data['items'] = InvoiceToolSupport::items($arguments['items']);
        }

        if ($data === []) {
            throw new ToolException('Nothing to change: pass at least one field.');
        }

        $paid = $invoice->paidTotal();
        $writer = app(InvoiceWriter::class);

        // Work it through inside a transaction we can abandon, so a total
        // below what was paid is refused without leaving a half-edit.
        DB::beginTransaction();
        $writer->update($invoice, $data);
        $invoice->refresh();
        if ((float) $invoice->total + 0.001 < $paid) {
            DB::rollBack();
            throw new ToolException('That would make the total '.number_format((float) $invoice->total, 2).', below the '.number_format($paid, 2).' already paid. Not changed.');
        }
        DB::commit();

        return ['updated' => true, 'sent_to_client' => false, 'before' => $before, 'after' => InvoiceToolSupport::describe($invoice->refresh())];
    }
}
