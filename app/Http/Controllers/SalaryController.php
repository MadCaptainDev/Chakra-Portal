<?php

namespace App\Http\Controllers;

use App\Models\CompanySetting;
use App\Models\Expense;
use App\Models\SalaryHike;
use App\Services\ExpenseLedger;
use App\Support\LocksExpenseAmount;
use App\Support\ManagesAvatars;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SalaryController extends Controller
{
    use LocksExpenseAmount;
    use ManagesAvatars;

    public function __construct(private readonly ExpenseLedger $ledger) {}

    /**
     * The month's payroll run.
     */
    public function index(Request $request): View
    {
        $month = $this->ledger->resolveMonth($request->query('month'));

        $employees = Expense::where('type', Expense::TYPE_SALARY)->with('user')->orderBy('name')->get();
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

        $salary->loadMissing('user');

        $history = $salary->payments()->orderByDesc('period')->get();

        return view('salaries.show', [
            'employee' => $salary,
            'history' => $history,
            'totalPaid' => (float) $history->sum('amount_paid'),
            'hikes' => $salary->hikes()->with('createdBy')->get(),
        ]);
    }

    /**
     * Give (or cut) a raise. Unlike the locked-amount edit form, this is the
     * purposeful path: it always leaves a SalaryHike row behind, so "what
     * did this person earn before March" stays answerable.
     */
    public function hike(Request $request, Expense $salary): RedirectResponse
    {
        abort_unless($salary->type === Expense::TYPE_SALARY, 404);

        $validated = $request->validateWithBag('hike', [
            'new_amount' => ['required', 'numeric', 'min:0'],
            'effective_on' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $previous = (float) $salary->amount;
        $new = (float) $validated['new_amount'];

        if (abs($new - $previous) < 0.01) {
            return redirect()->route('salaries.show', $salary)
                ->with('status', 'No change — that\'s already this salary.');
        }

        $effectiveOn = $validated['effective_on'] ?? now()->toDateString();

        DB::transaction(function () use ($salary, $previous, $new, $effectiveOn, $validated, $request) {
            SalaryHike::create([
                'expense_id' => $salary->id,
                'previous_amount' => $previous,
                'new_amount' => $new,
                'effective_on' => $effectiveOn,
                'reason' => $validated['reason'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            $salary->update(['amount' => $new]);
        });

        $verb = $new > $previous ? 'Raised' : 'Reduced';

        return redirect()->route('salaries.show', $salary)->with(
            'status',
            "{$verb} {$salary->name}'s salary from ".number_format($previous, 2).' to '.number_format($new, 2).'.'
        );
    }

    /**
     * Download one month's payslip as a PDF. $period is "Y-m" -- same shape
     * the payroll month picker already uses everywhere else.
     */
    public function payslip(Expense $salary, string $period): Response
    {
        abort_unless($salary->type === Expense::TYPE_SALARY, 404);

        try {
            $month = Carbon::createFromFormat('Y-m', $period)->startOfMonth();
        } catch (\Throwable) {
            abort(404);
        }

        $salary->loadMissing('user');
        $payment = $salary->payments()->whereDate('period', $month->toDateString())->first();

        $html = view('salaries.payslip', [
            'employee' => $salary,
            'month' => $month,
            'payment' => $payment,
            'settings' => CompanySetting::current(),
        ])->render();

        $pdf = Pdf::loadHTML($html)->setPaper('a4');

        return $pdf->download(Str::slug($salary->name).'-payslip-'.$month->format('Y-m').'.pdf');
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
        $data = $this->validated($request, isUpdate: true);

        $salary->loadMissing('user');
        $profile = [];
        if ($salary->user) {
            $profile = $request->validate([
                'bio' => ['nullable', 'string', 'max:1000'],
                'avatar' => ['nullable', 'image', 'max:2048'],
                'remove_avatar' => ['sometimes', 'boolean'],
            ]);
        }

        $salary->update($data);

        if ($salary->user) {
            $salary->user->fill([
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'bio' => $profile['bio'] ?? null,
            ]);
            $this->applyAvatarUpload($request, $salary->user);
            $salary->user->save();
        }

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
