<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Services\ExpenseLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * The combined month overview. Per-type management lives in the EMI, Salaries
 * and Bills modules; this is the one screen that shows total monthly outflow.
 */
class ExpenseController extends Controller
{
    public function __construct(private readonly ExpenseLedger $ledger) {}

    public function index(Request $request): View
    {
        $month = $this->ledger->resolveMonth($request->query('month'));

        $expenses = Expense::dueIn($month);
        $rows = $this->ledger->rowsFor($month, $expenses);

        $totalDue = $rows->sum('due');
        $totalPaid = $rows->sum('paid');

        return view('expenses.index', [
            'month' => $month,
            'rows' => $rows,
            'groups' => $rows->groupBy(fn ($row) => $row['expense']->type),
            'totalDue' => $totalDue,
            'totalPaid' => $totalPaid,
            'outstanding' => max($totalDue - $totalPaid, 0),
        ]);
    }

    public function pay(Request $request, Expense $expense): RedirectResponse
    {
        $validated = $request->validate([
            'month' => ['required', 'date'],
            'amount_paid' => ['required', 'numeric', 'min:0'],
            'paid_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $period = Carbon::parse($validated['month'])->startOfMonth();

        if (! $expense->isDueIn($period)) {
            return redirect()
                ->route('expenses.index', ['month' => $period->format('Y-m')])
                ->with('error', "{$expense->name} is not payable in {$period->format('F Y')}.");
        }

        $amount = (float) $validated['amount_paid'];

        $this->ledger->record($expense, $period, $amount, $validated['paid_on'] ?? null, $validated['note'] ?? null);

        return redirect()
            ->route('expenses.index', ['month' => $period->format('Y-m')])
            ->with('status', $amount > 0
                ? "Recorded {$expense->name} for {$period->format('F Y')}."
                : "Cleared the payment for {$expense->name}.");
    }

    public function payAll(Request $request): RedirectResponse
    {
        $month = $this->ledger->resolveMonth($request->input('month'));

        $filled = $this->ledger->markAllPaid($month, Expense::dueIn($month));

        return redirect()
            ->route('expenses.index', ['month' => $month->format('Y-m')])
            ->with('status', $filled > 0
                ? "Marked {$filled} item(s) paid for {$month->format('F Y')}."
                : 'Everything was already recorded for this month.');
    }
}
