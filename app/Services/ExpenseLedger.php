<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpensePayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Month resolution and payment recording, shared by the Expenses overview and
 * the EMI, Salaries and Bills modules so the four don't each grow their own
 * slightly different copy.
 */
class ExpenseLedger
{
    /**
     * Accepts "2026-08" from the month navigation or a full date; falls back
     * to the current month for anything unparseable.
     */
    public function resolveMonth(?string $value): Carbon
    {
        if (! $value) {
            return now()->startOfMonth();
        }

        try {
            return Carbon::parse(strlen($value) === 7 ? $value.'-01' : $value)->startOfMonth();
        } catch (Throwable) {
            return now()->startOfMonth();
        }
    }

    /**
     * Payments for a month, keyed by expense_id.
     *
     * @param  Collection<int, Expense>  $expenses
     * @return Collection<int, ExpensePayment>
     */
    public function paymentsFor(Carbon $month, Collection $expenses): Collection
    {
        if ($expenses->isEmpty()) {
            return collect();
        }

        return ExpensePayment::whereDate('period', $month->copy()->startOfMonth()->toDateString())
            ->whereIn('expense_id', $expenses->pluck('id'))
            ->get()
            ->keyBy('expense_id');
    }

    /**
     * Record what was actually paid. Zero clears the row instead of storing a
     * real 0.00, so "no record" keeps meaning "not paid yet".
     */
    public function record(Expense $expense, Carbon $month, float $amount, ?string $paidOn = null, ?string $note = null): ?ExpensePayment
    {
        $period = $month->copy()->startOfMonth();

        if ($amount <= 0) {
            $this->clear($expense, $period);

            return null;
        }

        return ExpensePayment::updateOrCreate(
            ['expense_id' => $expense->id, 'period' => $period->toDateString()],
            [
                'amount_paid' => $amount,
                'paid_on' => $paidOn ?? now()->toDateString(),
                'note' => $note,
            ]
        );
    }

    public function clear(Expense $expense, Carbon $month): void
    {
        $expense->payments()
            ->whereDate('period', $month->copy()->startOfMonth()->toDateString())
            ->delete();
    }

    /**
     * Mark every not-yet-recorded item in the month as paid at its standard
     * amount. Existing records (including partial ones) are left alone.
     *
     * @param  Collection<int, Expense>  $expenses
     * @return int  how many were filled in
     */
    public function markAllPaid(Carbon $month, Collection $expenses): int
    {
        $period = $month->copy()->startOfMonth();
        $existing = $this->paymentsFor($period, $expenses);
        $filled = 0;

        foreach ($expenses as $expense) {
            if ($existing->has($expense->id)) {
                continue;
            }

            ExpensePayment::create([
                'expense_id' => $expense->id,
                'period' => $period->toDateString(),
                'amount_paid' => $expense->amount,
                'paid_on' => now()->toDateString(),
            ]);

            $filled++;
        }

        return $filled;
    }

    /**
     * Rows for a month board: the expense, its payment, due and paid figures.
     *
     * @param  Collection<int, Expense>  $expenses
     * @return Collection<int, array<string, mixed>>
     */
    public function rowsFor(Carbon $month, Collection $expenses): Collection
    {
        $payments = $this->paymentsFor($month, $expenses);

        return $expenses->map(fn (Expense $expense) => [
            'expense' => $expense,
            'payment' => $payments->get($expense->id),
            'due' => (float) $expense->amount,
            'paid' => (float) ($payments->get($expense->id)?->amount_paid ?? 0),
            'installment' => $expense->installmentNumberFor($month),
        ])->values();
    }
}
