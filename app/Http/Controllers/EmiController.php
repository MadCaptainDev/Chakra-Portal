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

class EmiController extends Controller
{
    use LocksExpenseAmount;

    public function __construct(private readonly ExpenseLedger $ledger) {}

    public function index(Request $request): View
    {
        $asOf = $this->ledger->resolveMonth($request->query('month'));

        $emis = Expense::where('type', Expense::TYPE_EMI)
            ->with('payments')
            ->orderBy('name')
            ->get();

        [$running, $finished] = $emis->partition(fn (Expense $e) => ! $e->isFinished($asOf));
        $running = $running->sortBy(fn (Expense $e) => $e->lastMonth()?->timestamp)->values();

        $dueThisMonth = $running->filter(fn (Expense $e) => $e->isDueIn($asOf))->values();
        $payRows = $this->ledger->rowsFor($asOf, $dueThisMonth)->keyBy(fn ($row) => $row['expense']->id);

        return view('emi.index', [
            'asOf' => $asOf,
            'month' => $asOf,
            'running' => $running,
            'finished' => $finished->values(),
            'payRows' => $payRows,
            'outstanding' => $running->sum(fn (Expense $e) => $e->outstandingAmount($asOf)),
            'monthlyLoad' => $dueThisMonth->sum(fn (Expense $e) => (float) $e->amount),
            'monthlyPaid' => $payRows->sum('paid'),
            'byBank' => $this->byBank($running, $asOf),
            'timeline' => $this->timeline($emis, $asOf),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Expense::create($this->validated($request));

        return redirect()->route('emi.index')->with('status', 'EMI added.');
    }

    public function update(Request $request, Expense $emi): RedirectResponse
    {
        // Without this, PUT /emi/{id} pointed at a salary would rewrite that
        // employee record into an EMI -- validated() force-sets the type.
        abort_unless($emi->isEmi(), 404);

        $unlocked = $request->boolean('unlock_amount');
        $emi->update($this->validated($request, isUpdate: true));

        $message = $unlocked
            ? 'EMI updated. Monthly amount changed.'
            : 'EMI updated.';

        return redirect()->route('emi.index')->with('status', $message);
    }

    public function destroy(Expense $emi): RedirectResponse
    {
        abort_unless($emi->isEmi(), 404);

        $emi->delete();

        return redirect()->route('emi.index')->with('status', 'EMI deleted.');
    }

    public function pay(Request $request, Expense $emi): RedirectResponse
    {
        abort_unless($emi->type === Expense::TYPE_EMI, 404);

        $validated = $request->validate([
            'month' => ['required', 'date'],
            'amount_paid' => ['required', 'numeric', 'min:0'],
        ]);

        $month = Carbon::parse($validated['month'])->startOfMonth();

        if (! $emi->isDueIn($month)) {
            return redirect()
                ->route('emi.index', ['month' => $month->format('Y-m')])
                ->with('error', "{$emi->name} is not payable in {$month->format('F Y')}.");
        }

        $this->ledger->record($emi, $month, (float) $validated['amount_paid']);

        return redirect()
            ->route('emi.index', ['month' => $month->format('Y-m')])
            ->with('status', (float) $validated['amount_paid'] > 0
                ? "Recorded {$emi->name} for {$month->format('F Y')}."
                : "Cleared {$emi->name} for {$month->format('F Y')}.");
    }

    /**
     * Outstanding grouped by lender, so exposure per bank is visible.
     *
     * @param  \Illuminate\Support\Collection<int, Expense>  $emis
     * @return array<int, array<string, mixed>>
     */
    private function byBank($emis, Carbon $asOf): array
    {
        return $emis->groupBy(fn (Expense $e) => $e->payee ?: 'Unassigned')
            ->map(fn ($group, $bank) => [
                'bank' => $bank,
                'outstanding' => $group->sum(fn (Expense $e) => $e->outstandingAmount($asOf)),
                'count' => $group->count(),
            ])
            ->sortByDesc('outstanding')
            ->values()
            ->all();
    }

    /**
     * Monthly EMI load from this month until the last installment anywhere --
     * the taper that shows when cash frees up.
     *
     * @param  \Illuminate\Support\Collection<int, Expense>  $emis
     * @return array<int, array<string, mixed>>
     */
    private function timeline($emis, Carbon $asOf): array
    {
        $end = $emis->map(fn (Expense $e) => $e->lastMonth())->filter()->max();

        if (! $end || $end->lt($asOf)) {
            return [];
        }

        $months = [];
        $cursor = $asOf->copy();

        // Guard against a pathological schedule producing a huge chart.
        while ($cursor->lte($end) && count($months) < 60) {
            $total = $emis->sum(fn (Expense $e) => $e->isDueIn($cursor) ? (float) $e->amount : 0);

            $months[] = ['month' => $cursor->copy(), 'total' => $total];
            $cursor->addMonthNoOverflow();
        }

        $peak = collect($months)->max('total') ?: 1;

        return collect($months)->map(fn ($m) => [
            'month' => $m['month'],
            'total' => $m['total'],
            'percent' => (int) round($m['total'] / $peak * 100),
        ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $isUpdate = false): array
    {
        $rules = $this->withLockedAmountRules($request, [
            'name' => ['required', 'string', 'max:255'],
            'payee' => ['nullable', 'string', 'max:255'],
            'start_month' => ['required', 'date'],
            'installments' => ['required', 'integer', 'min:1', 'max:600'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], $isUpdate);

        $validator = Validator::make($request->all(), $rules);
        $validator->after(fn ($v) => $this->confirmAmountUnlock($request, $v, $isUpdate));
        $data = $validator->validate();

        $data = $this->withoutLockedAmount($request, $data, $isUpdate);
        $data['type'] = Expense::TYPE_EMI;
        $data['is_active'] = true;
        $data['start_month'] = Carbon::parse($data['start_month'])->startOfMonth()->toDateString();

        return $data;
    }
}
