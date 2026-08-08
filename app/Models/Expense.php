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

    public const TYPES = [
        self::TYPE_EMI => 'EMI / Finance',
        self::TYPE_SALARY => 'Salary',
        self::TYPE_BILL => 'Bill',
    ];

    protected $fillable = [
        'user_id',
        'name',
        'type',
        'payee',
        'role',
        'joined_on',
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

        if (! $this->isEmi()) {
            return (bool) $this->is_active;
        }

        if (! $this->start_month || ! $this->installments) {
            return false;
        }

        return $month->gte($this->start_month->copy()->startOfMonth())
            && $month->lte($this->lastMonth());
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
     * Two DIFFERENT facts live here and must not be blended in the UI:
     *
     *  - Schedule position (installmentsElapsed / outstandingAmount /
     *    progressPercent) -- where the plan says we are. This is what the
     *    original spreadsheet tracked and it is meaningful immediately.
     *  - Recorded payments (recordedPaid) -- what has actually been ticked
     *    off in this app. Currently zero for every EMI, so driving the
     *    progress bar off it would show everything at 0%.
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
     * Still owed on the schedule, counting the current month as unpaid.
     */
    public function outstandingAmount(?Carbon $asOf = null): float
    {
        return $this->remainingInstallments($asOf) * (float) $this->amount;
    }

    public function scheduledPaidAmount(?Carbon $asOf = null): float
    {
        return $this->installmentsElapsed($asOf) * (float) $this->amount;
    }

    public function progressPercent(?Carbon $asOf = null): int
    {
        if (! $this->isEmi() || ! $this->installments) {
            return 0;
        }

        return (int) round($this->installmentsElapsed($asOf) / $this->installments * 100);
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
        return $this->isEmi() && $this->remainingInstallments($asOf) === 0;
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
            ->orderByRaw("CASE type WHEN 'emi' THEN 0 WHEN 'salary' THEN 1 ELSE 2 END")
            ->orderBy('name')
            ->get()
            ->filter(fn (Expense $expense) => $expense->isDueIn($month))
            ->values();
    }
}
