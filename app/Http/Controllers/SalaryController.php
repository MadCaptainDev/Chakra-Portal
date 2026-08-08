<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Services\ExpenseLedger;
use App\Support\LocksExpenseAmount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class SalaryController extends Controller
{
    use LocksExpenseAmount;

    public function __construct(private readonly ExpenseLedger $ledger) {}

    /**
     * The month's payroll run.
     */
    public function index(Request $request): View
    {
        $month = $this->ledger->resolveMonth($request->query('month'));

        $employees = Expense::where('type', Expense::TYPE_SALARY)->orderBy('name')->get();
        [$active, $left] = $employees->partition(fn (Expense $e) => (bool) $e->is_active);

        $rows = $this->ledger->rowsFor($month, $active);

        return view('salaries.index', [
            'month' => $month,
            'rows' => $rows,
            'left' => $left->values(),
            'totalDue' => $rows->sum('due'),
            'totalPaid' => $rows->sum('paid'),
            'outstanding' => max($rows->sum('due') - $rows->sum('paid'), 0),
        ]);
    }

    public function show(Expense $salary): View
    {
        abort_unless($salary->type === Expense::TYPE_SALARY, 404);

        $history = $salary->payments()->orderByDesc('period')->get();

        return view('salaries.show', [
            'employee' => $salary,
            'history' => $history,
            'totalPaid' => (float) $history->sum('amount_paid'),
        ]);
    }

    public function pay(Request $request, Expense $salary): RedirectResponse
    {
        abort_unless($salary->type === Expense::TYPE_SALARY, 404);

        $validated = $request->validate([
            'month' => ['required', 'date'],
            'amount_paid' => ['required', 'numeric', 'min:0'],
        ]);

        $month = Carbon::parse($validated['month'])->startOfMonth();

        $this->ledger->record($salary, $month, (float) $validated['amount_paid']);

        return redirect()
            ->route('salaries.index', ['month' => $month->format('Y-m')])
            ->with('status', (float) $validated['amount_paid'] > 0
                ? "Recorded {$salary->name} for {$month->format('F Y')}."
                : "Cleared {$salary->name} for {$month->format('F Y')}.");
    }

    public function payAll(Request $request): RedirectResponse
    {
        $month = $this->ledger->resolveMonth($request->input('month'));

        $active = Expense::where('type', Expense::TYPE_SALARY)->where('is_active', true)->get();
        $filled = $this->ledger->markAllPaid($month, $active);

        return redirect()
            ->route('salaries.index', ['month' => $month->format('Y-m')])
            ->with('status', $filled > 0
                ? "Marked {$filled} salary payment(s) for {$month->format('F Y')}."
                : 'Everything was already recorded for this month.');
    }

    public function store(Request $request): RedirectResponse
    {
        Expense::create($this->validated($request));

        return redirect()->route('salaries.index')->with('status', 'Employee added.');
    }

    public function update(Request $request, Expense $salary): RedirectResponse
    {
        abort_unless($salary->type === Expense::TYPE_SALARY, 404);

        $unlocked = $request->boolean('unlock_amount');
        $salary->update($this->validated($request, isUpdate: true));

        $message = $unlocked
            ? 'Employee updated. Salary amount changed.'
            : 'Employee updated.';

        return redirect()->route('salaries.show', $salary)->with('status', $message);
    }

    public function destroy(Expense $salary): RedirectResponse
    {
        abort_unless($salary->type === Expense::TYPE_SALARY, 404);

        $salary->delete();

        return redirect()->route('salaries.index')->with('status', 'Employee removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $isUpdate = false): array
    {
        $rules = $this->withLockedAmountRules($request, [
            'name' => ['required', 'string', 'max:255'],
            'role' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'joined_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], $isUpdate);

        $validator = Validator::make($request->all(), $rules);
        $validator->after(fn ($v) => $this->confirmAmountUnlock($request, $v, $isUpdate));
        $data = $validator->validate();

        $data = $this->withoutLockedAmount($request, $data, $isUpdate);
        $data['type'] = Expense::TYPE_SALARY;
        $data['is_active'] = $request->boolean('is_active', true);
        $data['start_month'] = null;
        $data['installments'] = null;

        return $data;
    }
}
