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

        $totalDue = (float) $rows->sum('due');
        $totalPaid = (float) $rows->sum('paid');
        $outstanding = max($totalDue - $totalPaid, 0);
        $paidPercent = $totalDue > 0 ? min(100, (int) round(($totalPaid / $totalDue) * 100)) : 0;

        $typeOrder = [Expense::TYPE_EMI, Expense::TYPE_SALARY, Expense::TYPE_BILL, Expense::TYPE_OTHER];
        $grouped = $rows->groupBy(fn ($row) => $row['expense']->type);

        $byType = collect($typeOrder)->map(function (string $type) use ($grouped) {
            $group = $grouped->get($type, collect());
            $due = (float) $group->sum('due');
            $paid = (float) $group->sum('paid');

            return [
                'type' => $type,
                'label' => Expense::TYPES[$type] ?? ucfirst($type),
                'due' => $due,
                'paid' => $paid,
                'outstanding' => max($due - $paid, 0),
                'count' => $group->count(),
                'unpaid_count' => $group->filter(fn ($row) => $row['paid'] + 0.001 < $row['due'])->count(),
                'percent' => $due > 0 ? min(100, (int) round(($paid / $due) * 100)) : 0,
            ];
        })->keyBy('type');

        $attentionRows = $rows
            ->filter(fn ($row) => $row['paid'] + 0.001 < $row['due'])
            ->map(function (array $row) {
                $row['shortfall'] = max($row['due'] - $row['paid'], 0);

                return $row;
            })
            ->sortByDesc('shortfall')
            ->values();

        $clearedRows = $rows->filter(fn ($row) => $row['paid'] + 0.001 >= $row['due'] && $row['paid'] > 0);
        $salary = $byType->get(Expense::TYPE_SALARY);
        $emi = $byType->get(Expense::TYPE_EMI);
        $bill = $byType->get(Expense::TYPE_BILL);
        $other = $byType->get(Expense::TYPE_OTHER);

        return view('expenses.index', [
            'month' => $month,
            'rows' => $rows,
            'totalDue' => $totalDue,
            'totalPaid' => $totalPaid,
            'outstanding' => $outstanding,
            'paidPercent' => $paidPercent,
            'byType' => $byType,
            'attentionRows' => $attentionRows,
            'glance' => [
                'payroll_pending' => $salary['outstanding'] ?? 0,
                'payroll_unpaid_count' => $salary['unpaid_count'] ?? 0,
                'emi_load' => $emi['due'] ?? 0,
                'emi_unpaid_count' => $emi['unpaid_count'] ?? 0,
                'bills_pending' => $bill['outstanding'] ?? 0,
                'bills_unpaid_count' => $bill['unpaid_count'] ?? 0,
                'other_spent' => $other['due'] ?? 0,
                'other_count' => $other['count'] ?? 0,
                'cleared_count' => $clearedRows->count(),
                'cleared_amount' => (float) $clearedRows->sum('paid'),
            ],
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
