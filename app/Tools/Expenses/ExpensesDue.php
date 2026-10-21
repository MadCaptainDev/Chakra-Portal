<?php

namespace App\Tools\Expenses;

use App\Models\Expense;
use App\Models\User;
use App\Services\ExpenseLedger;
use App\Tools\Tool;

class ExpensesDue extends Tool
{
    public function name(): string
    {
        return 'expenses_due';
    }

    public function title(): string
    {
        return 'The studio\'s own outgoings for a month';
    }

    public function group(): string
    {
        return 'Expenses';
    }

    public function description(): string
    {
        return 'Everything the studio itself pays in a month -- EMIs (with "installment 4 of 15"), salaries, '
            .'bills and one-time spends -- each with what is due, what has been recorded as paid, and its '
            .'expense_id. USE BEFORE record_expense_payment to find the id, and when asked "which EMIs are '
            .'unpaid this month", "what have we spent in October". Salaries only appear for people allowed to '
            .'see them. This is NOT money clients owe -- use money_due for that. Read-only.';
    }

    public function permission(): ?string
    {
        return 'expenses.view';
    }

    // Kept off the WhatsApp assistant: its token budget is spent on every message.
    public function mcpOnly(): bool
    {
        return true;
    }

    public function schema(): array
    {
        return $this->object([
            'month' => ['type' => 'string', 'description' => 'Optional. YYYY-MM; defaults to this month.'],
            'type' => ['type' => 'string', 'enum' => array_keys(Expense::TYPES), 'description' => 'Optional. Only this kind: emi, salary, bill or other (one-time).'],
            'unpaid_only' => ['type' => 'boolean', 'description' => 'Optional. Only items not yet paid in full.'],
        ]);
    }

    public function handle(array $arguments, User $user): array
    {
        $month = ExpenseSupport::month($arguments['month'] ?? null);
        $seeSalaries = ExpenseSupport::canSeeSalaries($user);

        $expenses = Expense::dueIn($month)
            ->filter(fn (Expense $e) => $seeSalaries || $e->type !== Expense::TYPE_SALARY)
            ->filter(fn (Expense $e) => empty($arguments['type']) || $e->type === $arguments['type'])
            ->values();

        $rows = app(ExpenseLedger::class)->rowsFor($month, $expenses)
            ->map(fn (array $row) => [
                'expense_id' => $row['expense']->id,
                'name' => $row['expense']->name,
                'type' => $row['expense']->type,
                'payee' => $row['expense']->payee,
                'category' => $row['expense']->category,
                'installment' => $row['installment'] ? "{$row['installment']} of {$row['expense']->installments}" : null,
                'due' => $row['due'],
                'paid' => $row['paid'],
                'paid_on' => $row['payment']?->paid_on?->toDateString(),
                'status' => match (true) {
                    $row['paid'] <= 0 => 'unpaid',
                    $row['paid'] + 0.001 < $row['due'] => 'part paid',
                    default => 'paid',
                },
            ]);

        if (! empty($arguments['unpaid_only'])) {
            $rows = $rows->reject(fn (array $row) => $row['status'] === 'paid');
        }

        $due = round($rows->sum('due'), 2);
        $paid = round($rows->sum('paid'), 2);

        return [
            'month' => $month->format('Y-m'),
            'salaries_included' => $seeSalaries,
            'total_due' => $due,
            'total_paid' => $paid,
            'still_to_pay' => round(max($due - $paid, 0), 2),
            'items' => $rows->values()->all(),
        ];
    }
}
