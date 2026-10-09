<?php

namespace App\Tools\Expenses;

use App\Models\Expense;
use App\Models\User;
use App\Tools\ToolException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * What the expense tools share: turning "the HDFC loan" into exactly one
 * expense, reading a month, and the salaries rule.
 *
 * Salaries live in the same table as EMIs and bills but behind their own
 * module, so somebody who may run the expenses board is not thereby shown
 * what each person is paid. Every tool here asks canSeeSalaries() before a
 * salary row goes in or out.
 */
class ExpenseSupport
{
    public static function canSeeSalaries(User $user): bool
    {
        return Gate::forUser($user)->allows('salaries.view');
    }

    /**
     * "2026-10", or today's month when nothing was given.
     */
    public static function month(mixed $value): Carbon
    {
        if ($value === null || $value === '') {
            return today()->startOfMonth();
        }

        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}$/', $value)) {
            throw new ToolException('month must be YYYY-MM, e.g. "'.today()->format('Y-m').'".');
        }

        return Carbon::createFromFormat('Y-m-d', $value.'-01')->startOfMonth();
    }

    /**
     * One recurring expense (EMI, salary or bill) by id or name, or refuse.
     *
     * Same rule as ClientResolver: a name that fits two rows is an error
     * listing both with ids, never a guess -- marking the wrong loan paid is
     * exactly the mistake this is here to stop. One-time expenses are left
     * out: they are paid the moment they are logged.
     */
    public static function resolveRecurring(mixed $value, User $user): Expense
    {
        $value = trim((string) $value);

        if ($value === '') {
            throw new ToolException('Say which expense: its id from expenses_due, or its name.');
        }

        $types = [Expense::TYPE_EMI, Expense::TYPE_BILL];
        if (self::canSeeSalaries($user)) {
            $types[] = Expense::TYPE_SALARY;
        }

        $query = fn () => Expense::query()->whereIn('type', $types);

        if (ctype_digit($value)) {
            return $query()->find((int) $value)
                ?? throw new ToolException("There is no EMI, bill or salary with id {$value}. Use expenses_due to see them.");
        }

        $exact = $query()->whereRaw('LOWER(name) = ?', [mb_strtolower($value)])->get();

        if ($exact->count() === 1) {
            return $exact->first();
        }

        $matches = $exact->isNotEmpty()
            ? $exact
            : $query()->where(fn ($q) => $q->where('name', 'like', '%'.$value.'%')->orWhere('payee', 'like', '%'.$value.'%'))
                ->orderBy('name')->limit(6)->get();

        if ($matches->count() === 1) {
            return $matches->first();
        }

        if ($matches->isEmpty()) {
            throw new ToolException("No EMI, bill or salary matches \"{$value}\". Use expenses_due to see them.");
        }

        throw new ToolException(
            "\"{$value}\" matches more than one expense — ask the person which one, then pass its id: "
            .$matches->map(fn (Expense $e) => "{$e->name} ({$e->typeLabel()}, ₹".number_format((float) $e->amount).", id {$e->id})")->implode('; ').'.'
        );
    }
}
