<?php

namespace App\Http\Controllers;

use App\Http\Requests\RecurringInvoiceRequest;
use App\Models\Client;
use App\Models\RecurringInvoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class RecurringInvoiceController extends Controller
{
    public function index(): View
    {
        $schedules = RecurringInvoice::with('client')->orderBy('next_run_on')->paginate(20);

        return view('recurring.index', compact('schedules'));
    }

    public function create(): View
    {
        $clients = Client::orderBy('name')->get();

        return view('recurring.create', compact('clients'));
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
     * @param  array<int, array{description: string, quantity: float, unit_price: float}>  $items
     */
    private function syncItems(RecurringInvoice $schedule, array $items): void
    {
        foreach ($items as $index => $item) {
            $schedule->items()->create([
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'sort_order' => $index,
            ]);
        }
    }
}
