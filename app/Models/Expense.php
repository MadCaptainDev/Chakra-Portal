<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class Expense extends Model
{
    use HasFactory;

    public const TYPE_EMI = 'emi';

    public const TYPE_SALARY = 'salary';

    public const TYPE_BILL = 'bill';

    public const TYPE_OTHER = 'other';

    public const TYPES = [
        self::TYPE_EMI => 'EMI / Finance',
        self::TYPE_SALARY => 'Salary',
        self::TYPE_BILL => 'Bill',
        self::TYPE_OTHER => 'One-time',
    ];

    /** Categories for irregular / one-time company spends. */
    public const OTHER_CATEGORIES = [
        'Travel',
        'Fuel',
        'Equipment',
        'Software',
        'Marketing',
        'Office',
        'Food & Entertainment',
        'Professional Services',
        'Maintenance',
        'Miscellaneous',
    ];

    protected $fillable = [
        'user_id',
        'name',
        'type',
        'payee',
        'role',
        'joined_on',
        'spent_on',
        'category',
        'phone',
        'amount',
        'start_month',
        'installments',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'start_month' => 'date',
        'joined_on' => 'date',
        'spent_on' => 'date',
        'installments' => 'integer',
        'is_active' => 'boolean',
    ];

    public function payments(): HasMany
    {
        return $this->hasMany(ExpensePayment::class);
    }

    /**
     * The login belonging to this employee, when one has been issued.
     * Only meaningful for type = salary.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isEmi(): bool
    {
        return $this->type === self::TYPE_EMI;
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst((string) $this->type);
    }

    /**
     * Month (day 1) of the final installment. Null when open-ended.
     */
    public function lastMonth(): ?Carbon
    {
        if (! $this->isEmi() || ! $this->start_month || ! $this->installments) {
            return null;
        }

        return $this->start_month->copy()->startOfMonth()
            ->addMonthsNoOverflow($this->installments - 1);
    }

    public function isDueIn(Carbon $month): bool
    {
        $month = $month->copy()->startOfMonth();

        if ($this->type === self::TYPE_OTHER) {
            return $this->spent_on
                && $this->spent_on->copy()->startOfMonth()->equalTo($month);
        }

        if ($this->isEmi()) {
            if (! $this->start_month || ! $this->installments) {
                return false;
            }

            return $month->gte($this->start_month->copy()->startOfMonth())
                && $month->lte($this->lastMonth());
        }

        return (bool) $this->is_active;
    }

    public function isOther(): bool
    {
        return $this->type === self::TYPE_OTHER;
    }

    /**
     * 1-based installment number for a month, for showing "4 of 15".
     */
    public function installmentNumberFor(Carbon $month): ?int
    {
        if (! $this->isEmi() || ! $this->isDueIn($month)) {
            return null;
        }

        $start = $this->start_month->copy()->startOfMonth();

        // Cast: Carbon 3 returns a float from diffInMonths.
        return (int) $start->diffInMonths($month->copy()->startOfMonth()) + 1;
    }

    public function totalCommitment(): float
    {
        return $this->isEmi() && $this->installments
            ? (float) $this->amount * $this->installments
            : 0.0;
    }

    /*
     * ---------------------------------------------------------------------
     * EMI progress
     *
     * Schedule position (installmentsElapsed) is calendar-only: months that
     * fell due before asOf. Liability and "finished" also honour the payment
     * recorded for the asOf month, so a paid final installment shows 0 left
     * / Cleared instead of still looking unpaid.
     *
     * recordedPaid() is lifetime app-logged payments and may lag the schedule
     * when older months were never entered here.
     * ---------------------------------------------------------------------
     */

    /**
     * Installments falling strictly before the given month, clamped to the term.
     */
    public function installmentsElapsed(?Carbon $asOf = null): int
    {
        if (! $this->isEmi() || ! $this->start_month || ! $this->installments) {
            return 0;
        }

        $asOf = ($asOf ?? now())->copy()->startOfMonth();
        $start = $this->start_month->copy()->startOfMonth();

        if ($asOf->lte($start)) {
            return 0;
        }

        // Cast: Carbon 3 returns a float from diffInMonths.
        return (int) min((int) $start->diffInMonths($asOf), $this->installments);
    }

    public function remainingInstallments(?Carbon $asOf = null): int
    {
        return $this->isEmi() && $this->installments
            ? max($this->installments - $this->installmentsElapsed($asOf), 0)
            : 0;
    }

    /**
     * Amount recorded for a single month (0 when none).
     */
    public function recordedPaidFor(?Carbon $month = null): float
    {
        $month = ($month ?? now())->copy()->startOfMonth();

        if ($this->relationLoaded('payments')) {
            $payment = $this->payments->first(
                fn (ExpensePayment $p) => $p->period?->copy()->startOfMonth()->equalTo($month)
            );

            return (float) ($payment?->amount_paid ?? 0);
        }

        return (float) $this->payments()
            ->whereDate('period', $month->toDateString())
            ->value('amount_paid');
    }

    public function isPaidInFullFor(?Carbon $month = null): bool
    {
        return $this->recordedPaidFor($month) + 0.001 >= (float) $this->amount;
    }

    /**
     * Past installments plus the current month when it is paid in full.
     */
    public function installmentsCompleted(?Carbon $asOf = null): int
    {
        if (! $this->isEmi() || ! $this->installments) {
            return 0;
        }

        $asOf = ($asOf ?? now())->copy()->startOfMonth();
        $completed = $this->installmentsElapsed($asOf);

        if ($this->isDueIn($asOf) && $this->isPaidInFullFor($asOf)) {
            $completed = min($completed + 1, $this->installments);
        }

        return $completed;
    }

    /**
     * Installments that fall after the asOf month. This month itself is not
     * counted — it is due now, not leftover.
     */
    public function remainingAfterCurrentMonth(?Carbon $asOf = null): int
    {
        if (! $this->isEmi() || ! $this->installments) {
            return 0;
        }

        $asOf = ($asOf ?? now())->copy()->startOfMonth();
        $dueThisMonth = $this->isDueIn($asOf);

        return max(
            $this->installments - $this->installmentsElapsed($asOf) - ($dueThisMonth ? 1 : 0),
            0
        );
    }

    /**
     * Still owed: future installments after asOf, plus any unpaid slice of
     * the current month. Paying this month reduces (or clears) the figure.
     */
    public function outstandingAmount(?Carbon $asOf = null): float
    {
        if (! $this->isEmi() || ! $this->installments) {
            return 0.0;
        }

        $asOf = ($asOf ?? now())->copy()->startOfMonth();
        $amount = (float) $this->amount;
        $outstanding = $this->remainingAfterCurrentMonth($asOf) * $amount;

        if ($this->isDueIn($asOf)) {
            $outstanding += max($amount - $this->recordedPaidFor($asOf), 0.0);
        }

        return $outstanding;
    }

    public function scheduledPaidAmount(?Carbon $asOf = null): float
    {
        return $this->installmentsCompleted($asOf) * (float) $this->amount;
    }

    public function progressPercent(?Carbon $asOf = null): int
    {
        if (! $this->isEmi() || ! $this->installments) {
            return 0;
        }

        return (int) round($this->installmentsCompleted($asOf) / $this->installments * 100);
    }

    /**
     * What has actually been recorded as paid in this app, all months.
     */
    public function recordedPaid(): float
    {
        return (float) ($this->relationLoaded('payments')
            ? $this->payments->sum('amount_paid')
            : $this->payments()->sum('amount_paid'));
    }

    public function isFinished(?Carbon $asOf = null): bool
    {
        if (! $this->isEmi() || ! $this->installments || ! $this->start_month) {
            return false;
        }

        $asOf = ($asOf ?? now())->copy()->startOfMonth();

        // Calendar past the last installment month.
        if ($this->remainingInstallments($asOf) === 0) {
            return true;
        }

        // Final installment month paid in full → cleared now, not next month.
        return $this->isDueIn($asOf)
            && $this->installmentNumberFor($asOf) === (int) $this->installments
            && $this->isPaidInFullFor($asOf);
    }

    /**
     * Everything payable in the given month -- EMIs still inside their term
     * plus every active salary and bill.
     *
     * Filtered in PHP rather than SQL on purpose: the date arithmetic differs
     * between MySQL and the SQLite used by the test suite, and this table holds
     * tens of rows, not thousands.
     *
     * @return Collection<int, Expense>
     */
    public static function dueIn(Carbon $month): Collection
    {
        return static::query()
            ->orderByRaw("CASE type WHEN 'emi' THEN 0 WHEN 'salary' THEN 1 WHEN 'bill' THEN 2 WHEN 'other' THEN 3 ELSE 4 END")
            ->orderBy('name')
            ->get()
            ->filter(fn (Expense $expense) => $expense->isDueIn($month))
            ->values();
    }
}
