<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Services\ExpenseLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Irregular / one-time company spends — not salaries, bills, or EMIs.
 * Each entry has a spent_on date and category, and counts only in that month.
 */
class OtherExpenseController extends Controller
{
    public function __construct(private readonly ExpenseLedger $ledger) {}

    public function index(Request $request): View
    {
        $month = $this->ledger->resolveMonth($request->query('month'));

        $items = Expense::query()
            ->where('type', Expense::TYPE_OTHER)
            ->whereDate('spent_on', '>=', $month->copy()->startOfMonth()->toDateString())
            ->whereDate('spent_on', '<=', $month->copy()->endOfMonth()->toDateString())
            ->orderByDesc('spent_on')
            ->orderBy('name')
            ->get();

        $byCategory = $items
            ->groupBy(fn (Expense $e) => $e->category ?: 'Miscellaneous')
            ->map(fn ($group, $category) => [
                'category' => $category,
                'total' => (float) $group->sum('amount'),
                'count' => $group->count(),
            ])
            ->sortByDesc('total')
            ->values();

        return view('other.index', [
            'month' => $month,
            'items' => $items,
            'byCategory' => $byCategory,
            'total' => (float) $items->sum('amount'),
            'categories' => Expense::OTHER_CATEGORIES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $expense = Expense::create($data);

        // One-time spends are already paid when logged.
        $this->ledger->record(
            $expense,
            $expense->spent_on->copy()->startOfMonth(),
            (float) $expense->amount,
            $expense->spent_on->toDateString(),
            $expense->category
        );

        return redirect()
            ->route('other.index', ['month' => $expense->spent_on->format('Y-m')])
            ->with('status', 'One-time expense added.');
    }

    public function update(Request $request, Expense $other): RedirectResponse
    {
        abort_unless($other->type === Expense::TYPE_OTHER, 404);

        $data = $this->validated($request);
        $other->update($data);

        // Keep the payment row aligned with the (possibly moved) spent_on month.
        $other->payments()->delete();
        $this->ledger->record(
            $other->fresh(),
            $other->spent_on->copy()->startOfMonth(),
            (float) $other->amount,
            $other->spent_on->toDateString(),
            $other->category
        );

        return redirect()
            ->route('other.index', ['month' => $other->spent_on->format('Y-m')])
            ->with('status', 'One-time expense updated.');
    }

    public function destroy(Expense $other): RedirectResponse
    {
        abort_unless($other->type === Expense::TYPE_OTHER, 404);

        $monthKey = $other->spent_on?->format('Y-m') ?? now()->format('Y-m');
        $other->delete();

        return redirect()
            ->route('other.index', ['month' => $monthKey])
            ->with('status', 'One-time expense removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', Rule::in(Expense::OTHER_CATEGORIES)],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'spent_on' => ['required', 'date'],
            'payee' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $data['type'] = Expense::TYPE_OTHER;
        $data['is_active'] = false;
        $data['start_month'] = null;
        $data['installments'] = null;
        $data['spent_on'] = Carbon::parse($data['spent_on'])->toDateString();

        return $data;
    }
}
