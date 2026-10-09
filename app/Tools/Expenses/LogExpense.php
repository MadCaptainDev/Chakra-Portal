<?php

namespace App\Tools\Expenses;

use App\Models\Expense;
use App\Models\User;
use App\Services\ExpenseLedger;
use App\Tools\Tool;
use App\Tools\ToolException;
use Illuminate\Support\Carbon;

class LogExpense extends Tool
{
    public function name(): string
    {
        return 'log_expense';
    }

    public function title(): string
    {
        return 'Log a one-time studio expense';
    }

    public function group(): string
    {
        return 'Expenses';
    }

    public function description(): string
    {
        return 'Record a one-time spend the studio has ALREADY paid for itself -- fuel, a lens, a software '
            .'licence, a team lunch. Example: "spent ₹2,400 on fuel today". It lands in Expenses → One-time '
            .'as paid. category must be one of: '.implode(', ', Expense::OTHER_CATEGORIES).'. '
            .'DO NOT use for an EMI, salary or monthly bill falling due -- use record_expense_payment; nor '
            .'for a cost paid on a client\'s behalf -- use log_client_advance. Never guess an amount, date '
            .'or category the person did not give or clearly imply; ask. Returns what was logged.';
    }

    public function permission(): ?string
    {
        return 'expenses.create';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    // Kept off the WhatsApp assistant: its token budget is spent on every message.
    public function mcpOnly(): bool
    {
        return true;
    }

    public function schema(): array
    {
        return $this->object([
            'name' => ['type' => 'string', 'description' => 'What it was, e.g. "Fuel for Ooty shoot".'],
            'amount' => ['type' => 'number', 'description' => 'Rupees paid, e.g. 2400.'],
            'category' => ['type' => 'string', 'enum' => Expense::OTHER_CATEGORIES],
            'spent_on' => ['type' => 'string', 'description' => 'Optional. YYYY-MM-DD; defaults to today.'],
            'payee' => ['type' => 'string', 'description' => 'Optional. Who was paid, e.g. "Indian Oil".'],
            'notes' => ['type' => 'string', 'description' => 'Optional.'],
        ], ['name', 'amount', 'category']);
    }

    public function handle(array $arguments, User $user): array
    {
        $name = trim((string) ($arguments['name'] ?? ''));
        if ($name === '') {
            throw new ToolException('Say what the expense was for.');
        }

        $amount = $arguments['amount'] ?? null;
        if (! is_numeric($amount) || (float) $amount <= 0) {
            throw new ToolException('amount must be a positive number of rupees.');
        }

        $category = collect(Expense::OTHER_CATEGORIES)
            ->first(fn (string $c) => mb_strtolower($c) === mb_strtolower(trim((string) ($arguments['category'] ?? ''))));
        if (! $category) {
            throw new ToolException('category must be one of: '.implode(', ', Expense::OTHER_CATEGORIES).'.');
        }

        $spentOn = $arguments['spent_on'] ?? today()->toDateString();
        if (! is_string($spentOn) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $spentOn)) {
            throw new ToolException('spent_on must be YYYY-MM-DD.');
        }
        if (Carbon::parse($spentOn)->isAfter(today())) {
            throw new ToolException('spent_on is in the future — this logs money already spent.');
        }

        $expense = Expense::create([
            'name' => $name,
            'type' => Expense::TYPE_OTHER,
            'category' => $category,
            'amount' => round((float) $amount, 2),
            'spent_on' => $spentOn,
            'payee' => filled($arguments['payee'] ?? null) ? trim($arguments['payee']) : null,
            'notes' => filled($arguments['notes'] ?? null) ? mb_substr(trim($arguments['notes']), 0, 255) : null,
            'is_active' => false,
        ]);

        // One-time spends are already paid when logged -- as the screen does.
        app(ExpenseLedger::class)->record(
            $expense,
            $expense->spent_on->copy()->startOfMonth(),
            (float) $expense->amount,
            $expense->spent_on->toDateString(),
            $expense->category,
        );

        $monthTotal = Expense::query()
            ->where('type', Expense::TYPE_OTHER)
            ->whereDate('spent_on', '>=', $expense->spent_on->copy()->startOfMonth()->toDateString())
            ->whereDate('spent_on', '<=', $expense->spent_on->copy()->endOfMonth()->toDateString())
            ->sum('amount');

        return [
            'logged' => true,
            'expense_id' => $expense->id,
            'name' => $expense->name,
            'amount' => (float) $expense->amount,
            'category' => $expense->category,
            'spent_on' => $expense->spent_on->toDateString(),
            'payee' => $expense->payee,
            'one_time_total_for_month' => [
                'month' => $expense->spent_on->format('Y-m'),
                'total' => round((float) $monthTotal, 2),
            ],
        ];
    }
}
