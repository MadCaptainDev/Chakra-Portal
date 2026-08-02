<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Services\ExpenseLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class EmiController extends Controller
{
    public function __construct(private readonly ExpenseLedger $ledger) {}

    public function index(Request $request): View
    {
        $asOf = now()->startOfMonth();

        $emis = Expense::where('type', Expense::TYPE_EMI)
            ->with('payments')
            ->orderBy('name')
            ->get();

        [$running, $finished] = $emis->partition(fn (Expense $e) => ! $e->isFinished($asOf));

        return view('emi.index', [
            'asOf' => $asOf,
            'running' => $running->sortBy(fn (Expense $e) => $e->lastMonth()?->timestamp)->values(),
            'finished' => $finished->values(),
            'outstanding' => $running->sum(fn (Expense $e) => $e->outstandingAmount($asOf)),
            'monthlyLoad' => $running->sum(fn (Expense $e) => $e->isDueIn($asOf) ? (float) $e->amount : 0),
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
        $emi->update($this->validated($request));

        return redirect()->route('emi.index')->with('status', 'EMI updated.');
    }

    public function destroy(Expense $emi): RedirectResponse
    {
        $emi->delete();

        return redirect()->route('emi.index')->with('status', 'EMI deleted.');
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
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'payee' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'start_month' => ['required', 'date'],
            'installments' => ['required', 'integer', 'min:1', 'max:600'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $data['type'] = Expense::TYPE_EMI;
        $data['is_active'] = true;
        $data['start_month'] = Carbon::parse($data['start_month'])->startOfMonth()->toDateString();

        return $data;
    }
}
