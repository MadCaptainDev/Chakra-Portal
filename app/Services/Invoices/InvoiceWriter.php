<?php

namespace App\Services\Invoices;

use App\Models\Client;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Support\InvoiceQuantityVariable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Writing invoices and recurring schedules -- one path for the web screens
 * (InvoiceController, RecurringInvoiceController) and the MCP invoice tools,
 * so a bill made by Claude is numbered, totalled and itemised exactly like
 * one made by hand.
 *
 * Validation is the caller's: the controllers have their FormRequests, the
 * tools their own model-readable checks.
 */
class InvoiceWriter
{
    public const DEFAULT_INTRO = 'Professional services rendered as per our agreement. Kindly settle the invoice by the due date indicated.';

    /** The invoice fields a caller may set, besides items. */
    private const FIELDS = ['client_id', 'saas_product_id', 'saas_invoice_type', 'invoice_date', 'due_date', 'intro_text', 'discount_label', 'discount_amount'];

    /**
     * A new, numbered, unpaid invoice.
     *
     * @param  array<string, mixed>  $data  FIELDS plus items: list<{description, quantity, unit_price}>
     */
    public function create(array $data, int $userId): Invoice
    {
        return DB::transaction(function () use ($data, $userId) {
            $invoice = Invoice::create([
                ...$this->fields($data),
                'invoice_number' => Invoice::nextInvoiceNumber(CompanySetting::current()->invoice_prefix),
                'status' => Invoice::STATUS_UNPAID,
                'created_by' => $userId,
            ]);

            $this->replaceItems($invoice, $data['items']);

            return $invoice;
        });
    }

    /**
     * Change an invoice. Fields left out of $data stay as they are; items,
     * when given, replace every line. Status follows the new total, so an
     * invoice raised above what was paid goes back to unpaid.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Invoice $invoice, array $data): Invoice
    {
        return DB::transaction(function () use ($invoice, $data) {
            $invoice->update($this->fields($data, partial: true));

            if (array_key_exists('items', $data)) {
                $invoice->items()->delete();
                $this->replaceItems($invoice, $data['items']);
            } else {
                $invoice->load('items');
                $invoice->recalculateTotals();
                $invoice->save();
            }

            $invoice->recalculateStatus();

            return $invoice;
        });
    }

    /** A fresh, numbered copy dated $on (default today), with no due date. */
    public function duplicate(Invoice $invoice, int $userId, ?string $on = null): Invoice
    {
        $invoice->loadMissing('items');

        return DB::transaction(function () use ($invoice, $userId, $on) {
            $copy = Invoice::create([
                'invoice_number' => Invoice::nextInvoiceNumber(CompanySetting::current()->invoice_prefix),
                'client_id' => $invoice->client_id,
                'saas_product_id' => $invoice->saas_product_id,
                'saas_invoice_type' => $invoice->saas_invoice_type,
                'invoice_date' => $on ?? now()->format('Y-m-d'),
                'due_date' => null,
                'intro_text' => $invoice->intro_text,
                'discount_label' => $invoice->discount_label,
                'discount_amount' => $invoice->discount_amount,
                'status' => Invoice::STATUS_UNPAID,
                'created_by' => $userId,
            ]);

            foreach ($invoice->items as $item) {
                $copy->items()->create($item->only(['description', 'quantity', 'unit_price', 'line_total', 'sort_order']));
            }

            $copy->load('items');
            $copy->recalculateTotals();
            $copy->save();

            return $copy;
        });
    }

    /**
     * Every line of $invoice replaced by $items, quantity variables resolved
     * against the client's published content in the invoice's month.
     *
     * @param  list<array{description: string, quantity: mixed, unit_price: mixed}>  $items
     */
    public function replaceItems(Invoice $invoice, array $items): void
    {
        $client = Client::findOrFail($invoice->client_id);
        $month = Carbon::parse($invoice->invoice_date)->startOfMonth();

        foreach (array_values($items) as $index => $item) {
            $quantity = InvoiceQuantityVariable::resolve($item['quantity'], $client, $month);

            $invoice->items()->create([
                'description' => $item['description'],
                'quantity' => $quantity,
                'unit_price' => $item['unit_price'],
                'line_total' => $quantity * (float) $item['unit_price'],
                'sort_order' => $index,
            ]);
        }

        $invoice->load('items');
        $invoice->recalculateTotals();
        $invoice->save();
    }

    /**
     * A schedule's lines. Quantities stay as typed -- a variable such as
     * {{published_reels}} is resolved each time an invoice is generated.
     *
     * @param  list<array{description: string, quantity: mixed, unit_price: mixed}>  $items
     */
    public function replaceScheduleItems(RecurringInvoice $schedule, array $items): void
    {
        $schedule->items()->delete();

        foreach (array_values($items) as $index => $item) {
            $quantity = $item['quantity'];

            if (InvoiceQuantityVariable::isVariable($quantity)) {
                $quantity = InvoiceQuantityVariable::normalizeToken((string) $quantity);
            }

            $schedule->items()->create([
                'description' => $item['description'],
                'quantity' => (string) $quantity,
                'unit_price' => $item['unit_price'],
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * The next time a day of the month comes round, clamped to short months;
     * this month if the day has not passed yet.
     */
    public static function firstOccurrenceOn(int $day): Carbon
    {
        $today = today();
        $candidate = $today->copy()->day(min($day, $today->daysInMonth));

        if ($candidate->lt($today)) {
            $next = $today->copy()->addMonthNoOverflow();
            $candidate = $next->copy()->day(min($day, $next->daysInMonth));
        }

        return $candidate;
    }

    /** @return array<string, mixed> */
    private function fields(array $data, bool $partial = false): array
    {
        $fields = [];

        foreach (self::FIELDS as $key) {
            if ($partial && ! array_key_exists($key, $data)) {
                continue;
            }
            $fields[$key] = $data[$key] ?? null;
        }

        if (array_key_exists('saas_product_id', $fields) && ! $fields['saas_product_id']) {
            $fields['saas_invoice_type'] = null;
        }

        return $fields;
    }
}
