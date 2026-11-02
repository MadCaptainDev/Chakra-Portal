<?php

namespace App\Tools\Invoices;

use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Support\InvoiceQuantityVariable;
use App\Tools\ToolException;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * What the invoice tools share: finding an invoice or schedule from what a
 * model passes, checking line items and dates the way the web forms do, and
 * describing the result so the model can read it back to the person.
 */
class InvoiceToolSupport
{
    /** The schema for line items, shared by every tool that takes them. */
    public static function itemsSchema(bool $recurring): array
    {
        return [
            'type' => 'array',
            'minItems' => 1,
            'description' => 'Every line, in order. On an edit this REPLACES all existing lines, so send the full list.',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'description' => ['type' => 'string', 'description' => 'What the line is for, e.g. "Reels Making".'],
                    'quantity' => [
                        'type' => ['number', 'string'],
                        'description' => 'A number (at least 0.01), or a variable counting the client\'s published content that month: '
                            .'"{{published_reels}}", "{{published_posts}}" or "{{published_shorts}}". '
                            .($recurring ? 'On a schedule the variable is counted each time an invoice is generated.' : 'On an invoice it is counted once, now, for the invoice\'s month.'),
                    ],
                    'unit_price' => ['type' => 'number', 'description' => 'Rupees per unit, 0 or more. 0 is fine for a complimentary line.'],
                ],
                'required' => ['description', 'quantity', 'unit_price'],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * @return list<array{description: string, quantity: mixed, unit_price: float}>
     */
    public static function items(mixed $items): array
    {
        if (! is_array($items) || $items === []) {
            throw new ToolException('Give at least one line item.');
        }

        $clean = [];
        foreach (array_values($items) as $i => $item) {
            $n = $i + 1;
            $description = is_array($item) ? trim((string) ($item['description'] ?? '')) : '';
            if ($description === '' || mb_strlen($description) > 255) {
                throw new ToolException("Line {$n} needs a description of up to 255 characters.");
            }
            $quantity = $item['quantity'] ?? null;
            if (! InvoiceQuantityVariable::accepts($quantity)) {
                throw new ToolException("Line {$n}: quantity must be a number of at least 0.01, or {{published_reels}}, {{published_posts}} or {{published_shorts}}.");
            }
            if (! is_numeric($item['unit_price'] ?? null) || (float) $item['unit_price'] < 0) {
                throw new ToolException("Line {$n}: unit_price must be rupees, 0 or more.");
            }
            $clean[] = ['description' => $description, 'quantity' => $quantity, 'unit_price' => (float) $item['unit_price']];
        }

        return $clean;
    }

    public static function date(mixed $value, string $field): string
    {
        $value = trim((string) $value);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new ToolException("{$field} must be YYYY-MM-DD.");
        }
        try {
            return Carbon::createFromFormat('Y-m-d', $value)->format('Y-m-d');
        } catch (Throwable) {
            throw new ToolException("{$field} is not a real date.");
        }
    }

    /** The discount pair, checked: a label needs an amount and the other way round. */
    public static function discount(array $arguments): array
    {
        $label = isset($arguments['discount_label']) ? trim((string) $arguments['discount_label']) : null;
        $amount = $arguments['discount_amount'] ?? null;

        if ($amount !== null && (! is_numeric($amount) || (float) $amount < 0)) {
            throw new ToolException('discount_amount must be rupees, 0 or more.');
        }
        if ($amount !== null && (float) $amount > 0 && blank($label)) {
            throw new ToolException('A discount needs a discount_label the client will read, e.g. "Complimentary: 5 Posts".');
        }
        if (filled($label) && $amount === null) {
            throw new ToolException('Give discount_amount with the discount_label.');
        }

        return ['discount_label' => filled($label) ? mb_substr($label, 0, 255) : null, 'discount_amount' => $amount === null ? null : (float) $amount];
    }

    /** By number ("CP-0044", any case) or id. */
    public static function invoice(mixed $ref): Invoice
    {
        $ref = trim((string) $ref);
        if ($ref === '') {
            throw new ToolException('Say which invoice: its number (e.g. CP-0044) or id.');
        }

        $invoice = Invoice::query()->whereRaw('UPPER(invoice_number) = ?', [mb_strtoupper($ref)])->first()
            ?? (ctype_digit($ref) ? Invoice::find((int) $ref) : null);

        return $invoice ?? throw new ToolException("No invoice \"{$ref}\". Use invoice_lookup to find it.");
    }

    public static function schedule(mixed $id): RecurringInvoice
    {
        return RecurringInvoice::with(['items', 'client'])->find((int) $id)
            ?? throw new ToolException('No recurring invoice with that id. Use list_recurring_invoices.');
    }

    /** @return array<string, mixed> */
    public static function describe(Invoice $invoice): array
    {
        $invoice->loadMissing(['client', 'items', 'payments']);

        return [
            'invoice_id' => $invoice->id,
            'number' => $invoice->invoice_number ?? '(not numbered until approved)',
            'client' => $invoice->client?->name,
            'status' => $invoice->status,
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'intro_text' => $invoice->intro_text,
            'lines' => $invoice->items->sortBy('sort_order')->values()->map(fn ($item) => [
                'description' => $item->description,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->line_total,
            ])->all(),
            'subtotal' => (float) $invoice->subtotal,
            'discount' => $invoice->discount_label ? ['label' => $invoice->discount_label, 'amount' => (float) $invoice->discount_amount] : null,
            'total' => (float) $invoice->total,
            'paid' => round($invoice->paidTotal(), 2),
            'balance_due' => round($invoice->balanceDue(), 2),
            'payments' => $invoice->payments->map(fn ($p) => [
                'amount' => (float) $p->amount,
                'paid_on' => $p->paid_on?->toDateString(),
                'note' => $p->note,
            ])->all(),
            'from_recurring_schedule' => $invoice->recurring_invoice_id,
            'open_in_portal' => route('invoices.show', $invoice),
        ];
    }

    /** @return array<string, mixed> */
    public static function describeSchedule(RecurringInvoice $schedule): array
    {
        $schedule->loadMissing(['client', 'items']);

        return [
            'schedule_id' => $schedule->id,
            'client' => $schedule->client?->name,
            'label' => $schedule->label,
            'active' => (bool) $schedule->is_active,
            'frequency' => $schedule->frequency,
            'day_of_month' => $schedule->day_of_month,
            'next_invoice_on' => $schedule->next_run_on?->toDateString(),
            'last_generated_on' => $schedule->last_generated_on?->toDateString(),
            'due_days' => $schedule->due_days,
            'lines' => $schedule->items->sortBy('sort_order')->values()->map(fn ($item) => [
                'description' => $item->description,
                'quantity' => is_numeric($item->quantity) ? (float) $item->quantity : $item->quantity,
                'unit_price' => (float) $item->unit_price,
            ])->all(),
            'discount' => $schedule->discount_label ? ['label' => $schedule->discount_label, 'amount' => (float) $schedule->discount_amount] : null,
            'note' => 'Generated invoices wait in "pending approval" until someone approves them in the portal.',
        ];
    }
}
