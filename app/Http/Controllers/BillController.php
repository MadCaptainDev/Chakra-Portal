<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Services\ExpenseLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class BillController extends Controller
{
    public function __construct(private readonly ExpenseLedger $ledger) {}

    public function index(Request $request): View
    {
        $month = $this->ledger->resolveMonth($request->query('month'));

        $bills = Expense::where('type', Expense::TYPE_BILL)->orderBy('name')->get();
        [$active, $paused] = $bills->partition(fn (Expense $e) => (bool) $e->is_active);

        $rows = $this->ledger->rowsFor($month, $active);

        return view('bills.index', [
            'month' => $month,
            'rows' => $rows,
            'paused' => $paused->values(),
            'totalDue' => $rows->sum('due'),
            'totalPaid' => $rows->sum('paid'),
            'outstanding' => max($rows->sum('due') - $rows->sum('paid'), 0),
        ]);
    }

    public function pay(Request $request, Expense $bill): RedirectResponse
    {
        abort_unless($bill->type === Expense::TYPE_BILL, 404);

        $validated = $request->validate([
            'month' => ['required', 'date'],
            'amount_paid' => ['required', 'numeric', 'min:0'],
        ]);

        $month = Carbon::parse($validated['month'])->startOfMonth();

        $this->ledger->record($bill, $month, (float) $validated['amount_paid']);

        return redirect()
            ->route('bills.index', ['month' => $month->format('Y-m')])
            ->with('status', "Updated {$bill->name} for {$month->format('F Y')}.");
    }

    public function store(Request $request): RedirectResponse
    {
        Expense::create($this->validated($request));

        return redirect()->route('bills.index')->with('status', 'Bill added.');
    }

    public function update(Request $request, Expense $bill): RedirectResponse
    {
        abort_unless($bill->type === Expense::TYPE_BILL, 404);

        $bill->update($this->validated($request));

        return redirect()->route('bills.index')->with('status', 'Bill updated.');
    }

    public function destroy(Expense $bill): RedirectResponse
    {
        abort_unless($bill->type === Expense::TYPE_BILL, 404);

        $bill->delete();

        return redirect()->route('bills.index')->with('status', 'Bill deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'payee' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $data['type'] = Expense::TYPE_BILL;
        $data['is_active'] = $request->boolean('is_active', true);
        $data['start_month'] = null;
        $data['installments'] = null;

        return $data;
    }
}
