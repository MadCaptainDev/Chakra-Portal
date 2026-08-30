<?php

namespace App\Http\Controllers;

use App\Http\Requests\RecurringInvoiceRequest;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Models\RecurringInvoiceItem;
use App\Support\InvoiceQuantityVariable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class RecurringInvoiceController extends Controller
{
    public function index(): View
    {
        $schedules = RecurringInvoice::with('client')->orderBy('next_run_on')->paginate(20);

        return view('recurring.index', compact('schedules'));
    }

    public function create(Request $request): View
    {
        $clients = Client::orderBy('name')->get();

        // Optionally seeded from an existing invoice ("Make Recurring"). The
        // schedule is built in memory only -- nothing is persisted until the
        // form is submitted, so opening this page can never start billing a
        // client by accident.
        $sourceInvoice = $request->filled('from_invoice')
            ? Invoice::with('items')->find($request->query('from_invoice'))
            : null;

        $schedule = $sourceInvoice ? $this->prefillFrom($sourceInvoice) : null;

        return view('recurring.create', compact('clients', 'schedule', 'sourceInvoice'));
    }

    public function store(RecurringInvoiceRequest $request): RedirectResponse
    {
        $schedule = RecurringInvoice::create([
            ...$request->safe()->except('items'),
            'created_by' => $request->user()->id,
        ]);

        $this->syncItems($schedule, $request->validated('items'));

        return redirect()->route('recurring.index')->with('status', 'Recurring invoice schedule created.');
    }

    public function edit(RecurringInvoice $recurring): View
    {
        $recurring->load('items');
        $clients = Client::orderBy('name')->get();

        return view('recurring.edit', ['schedule' => $recurring, 'clients' => $clients]);
    }

    public function update(RecurringInvoiceRequest $request, RecurringInvoice $recurring): RedirectResponse
    {
        $recurring->update($request->safe()->except('items'));

        $recurring->items()->delete();
        $this->syncItems($recurring, $request->validated('items'));

        return redirect()->route('recurring.index')->with('status', 'Recurring invoice schedule updated.');
    }

    public function destroy(RecurringInvoice $recurring): RedirectResponse
    {
        $recurring->delete();

        return redirect()->route('recurring.index')->with('status', 'Recurring invoice schedule deleted.');
    }

    public function toggle(RecurringInvoice $recurring): RedirectResponse
    {
        $recurring->update(['is_active' => ! $recurring->is_active]);

        return redirect()->route('recurring.index')
            ->with('status', $recurring->is_active ? 'Schedule activated.' : 'Schedule paused.');
    }

    /**
     * Build an unsaved schedule mirroring an existing invoice, so "Make
     * Recurring" opens the normal create form with everything filled in.
     */
    private function prefillFrom(Invoice $invoice): RecurringInvoice
    {
        $anchorDay = (int) $invoice->invoice_date->day;

        $schedule = new RecurringInvoice([
            'client_id' => $invoice->client_id,
            'label' => $invoice->invoice_number ? "From {$invoice->invoice_number}" : null,
            'intro_text' => $invoice->intro_text,
            'discount_label' => $invoice->discount_label,
            'discount_amount' => $invoice->discount_amount,
            'frequency' => RecurringInvoice::FREQUENCY_MONTHLY,
            'day_of_month' => $anchorDay,
            // Preserve the original payment window (issue -> due) if it had one.
            // Cast: Carbon 3 returns a float from diffInDays, and due_days is
            // an integer column.
            'due_days' => $invoice->due_date
                ? (int) $invoice->invoice_date->diffInDays($invoice->due_date)
                : null,
            'next_run_on' => $this->firstOccurrenceOn($anchorDay),
        ]);

        // setRelation, not a DB write: the form reads $schedule->items, and an
        // unsaved model would otherwise fire a query and come back empty.
        $schedule->setRelation('items', $invoice->items->map(fn ($item) => new RecurringInvoiceItem([
            'description' => $item->description,
            'quantity' => $item->quantity,
            'unit_price' => $item->unit_price,
            'sort_order' => $item->sort_order,
        ])));

        return $schedule;
    }

    /**
     * The next time the given day-of-month comes around, clamped to short
     * months. Stays in the current month if that day hasn't passed yet.
     */
    private function firstOccurrenceOn(int $day): Carbon
    {
        $today = today();
        $candidate = $today->copy()->day(min($day, $today->daysInMonth));

        if ($candidate->lt($today)) {
            $nextMonth = $today->copy()->addMonthNoOverflow();
            $candidate = $nextMonth->copy()->day(min($day, $nextMonth->daysInMonth));
        }

        return $candidate;
    }

    /**
     * @param  array<int, array{description: string, quantity: mixed, unit_price: float}>  $items
     */
    private function syncItems(RecurringInvoice $schedule, array $items): void
    {
        foreach ($items as $index => $item) {
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
}
