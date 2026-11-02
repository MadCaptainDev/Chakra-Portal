<?php

namespace App\Tools\Invoices;

use App\Models\Invoice;
use App\Models\User;
use App\Services\Invoices\InvoiceWriter;
use App\Tools\ClientResolver;
use App\Tools\Tool;
use App\Tools\ToolException;
use Illuminate\Support\Carbon;

class CreateInvoice extends Tool
{
    public function name(): string
    {
        return 'create_invoice';
    }

    public function title(): string
    {
        return 'Create an invoice';
    }

    public function group(): string
    {
        return 'Invoices';
    }

    public function description(): string
    {
        return 'Create a numbered invoice for a client from line items (description, quantity, rate in '
            .'rupees), with an optional discount line. The portal numbers it and works out every amount '
            .'and the total -- read them back. It is NOT sent to the client and records no payment. '
            .'Never invent prices: rates must come from the person or a past invoice (use get_invoice / '
            .'invoice_lookup to copy a client\'s usual rates, or duplicate_invoice to repeat last month). '
            .'Refuses a second invoice for the same client in the same month unless '
            .'allow_second_this_month is true -- check with the person first.';
    }

    public function permission(): ?string
    {
        return 'invoices.create';
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
            'items' => InvoiceToolSupport::itemsSchema(recurring: false),
            'invoice_date' => ['type' => 'string', 'description' => 'Optional. YYYY-MM-DD; defaults to today. The studio usually dates monthly invoices the 1st.'],
            'due_date' => ['type' => 'string', 'description' => 'Optional. YYYY-MM-DD, on or after invoice_date.'],
            'intro_text' => ['type' => 'string', 'description' => 'Optional. The "Dear Client" paragraph; defaults to the studio\'s standard one.'],
            'discount_label' => ['type' => 'string', 'description' => 'Optional. What the client reads on the discount line, e.g. "Complimentary: 5 Posts".'],
            'discount_amount' => ['type' => 'number', 'description' => 'Optional. Rupees off the subtotal.'],
            'allow_second_this_month' => ['type' => 'boolean', 'description' => 'Only true when the person confirmed the client really needs another invoice this month.'],
        ], ['client', 'items']);
    }

    public function handle(array $arguments, User $user): array
    {
        $client = ClientResolver::resolve($arguments['client'] ?? null);
        $items = InvoiceToolSupport::items($arguments['items'] ?? null);
        $date = isset($arguments['invoice_date']) ? InvoiceToolSupport::date($arguments['invoice_date'], 'invoice_date') : today()->toDateString();
        $due = isset($arguments['due_date']) ? InvoiceToolSupport::date($arguments['due_date'], 'due_date') : null;

        if ($due !== null && $due < $date) {
            throw new ToolException('due_date is before invoice_date.');
        }

        $month = Carbon::parse($date);
        $existing = Invoice::where('client_id', $client->id)
            ->whereYear('invoice_date', $month->year)->whereMonth('invoice_date', $month->month)
            ->pluck('invoice_number')->filter()->all();

        if ($existing && ($arguments['allow_second_this_month'] ?? false) !== true) {
            throw new ToolException("{$client->name} already has an invoice for ".$month->format('F Y').' ('.implode(', ', $existing).'). '
                .'Ask the person whether to edit that one (update_invoice) instead. Only if they want a second, call again with allow_second_this_month: true.');
        }

        $invoice = app(InvoiceWriter::class)->create([
            'client_id' => $client->id,
            'invoice_date' => $date,
            'due_date' => $due,
            'intro_text' => $arguments['intro_text'] ?? InvoiceWriter::DEFAULT_INTRO,
            ...InvoiceToolSupport::discount($arguments),
            'items' => $items,
        ], $user->id);

        return ['created' => true, 'sent_to_client' => false, ...InvoiceToolSupport::describe($invoice->refresh())];
    }
}
