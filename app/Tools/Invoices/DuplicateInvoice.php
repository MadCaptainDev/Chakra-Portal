<?php

namespace App\Tools\Invoices;

use App\Models\Invoice;
use App\Models\User;
use App\Services\Invoices\InvoiceWriter;
use App\Tools\Tool;
use App\Tools\ToolException;
use Illuminate\Support\Carbon;

class DuplicateInvoice extends Tool
{
    public function name(): string
    {
        return 'duplicate_invoice';
    }

    public function title(): string
    {
        return 'Duplicate an invoice';
    }

    public function group(): string
    {
        return 'Invoices';
    }

    public function description(): string
    {
        return 'Copy an invoice -- same client, lines, rates, discount and intro -- as a new numbered '
            .'invoice dated invoice_date (default today) with no due date and no payments. The usual way '
            .'to bill a client "same as last month"; then use update_invoice to change counts. Refuses if '
            .'the client already has an invoice in the new date\'s month, unless allow_second_this_month. '
            .'Nothing is sent to the client.';
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
            'invoice' => ['type' => 'string', 'description' => 'The invoice to copy: number like "CP-0044", or id.'],
            'invoice_date' => ['type' => 'string', 'description' => 'Optional. YYYY-MM-DD for the copy; defaults to today.'],
            'allow_second_this_month' => ['type' => 'boolean', 'description' => 'Only true when the person confirmed a second invoice that month.'],
        ], ['invoice']);
    }

    public function handle(array $arguments, User $user): array
    {
        $source = InvoiceToolSupport::invoice($arguments['invoice'] ?? null);
        $date = isset($arguments['invoice_date']) ? InvoiceToolSupport::date($arguments['invoice_date'], 'invoice_date') : today()->toDateString();
        $month = Carbon::parse($date);

        $existing = Invoice::where('client_id', $source->client_id)
            ->whereYear('invoice_date', $month->year)->whereMonth('invoice_date', $month->month)
            ->pluck('invoice_number')->filter()->all();

        if ($existing && ($arguments['allow_second_this_month'] ?? false) !== true) {
            throw new ToolException($source->client?->name.' already has an invoice for '.$month->format('F Y').' ('.implode(', ', $existing).'). '
                .'Ask the person before making another; call again with allow_second_this_month: true only if they want it.');
        }

        $copy = app(InvoiceWriter::class)->duplicate($source, $user->id, $date);

        return ['created' => true, 'copied_from' => $source->invoice_number, 'sent_to_client' => false, ...InvoiceToolSupport::describe($copy->refresh())];
    }
}
