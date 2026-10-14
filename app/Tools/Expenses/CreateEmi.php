<?php

namespace App\Tools\Expenses;

use App\Models\Expense;
use App\Models\User;
use App\Services\ExpenseLedger;
use App\Tools\Tool;
use App\Tools\ToolException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CreateEmi extends Tool
{
    public function name(): string
    {
        return 'create_emi';
    }

    public function title(): string
    {
        return 'Add an EMI';
    }

    public function group(): string
    {
        return 'Expenses';
    }

    public function description(): string
    {
        return 'Add a new EMI / finance plan to Expenses → EMI: what it is for, the bank account it is '
            .'paid through, the monthly amount, the tenure (number of monthly installments) and the first '
            .'month. Example: "Sony lens on Axis, ₹4,200 a month for 12 months from November". Tenure is in '
            .'MONTHS -- convert "2 years" to 24. For a loan that started before this month, set already_paid '
            .'to how many installments are paid so they do not show as due. Never guess an amount, tenure, '
            .'bank or start month the person did not give; ask. To mark a month of an existing EMI paid use '
            .'record_expense_payment instead. Returns the full schedule.';
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
            'name' => ['type' => 'string', 'description' => 'What the EMI is for, e.g. "Sony 70-200 lens".'],
            'bank_account' => ['type' => 'string', 'description' => 'The bank, card or lender it is paid through, as the EMI list writes it, e.g. "Axis", "BOI", "CANA", "FI", "FEDERAL - Annamalai".'],
            'amount' => ['type' => 'number', 'description' => 'Rupees per month, e.g. 4200.'],
            'tenure_months' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 600, 'description' => 'Total number of monthly installments.'],
            'start_month' => ['type' => 'string', 'description' => 'Month of the first installment, YYYY-MM. Defaults to this month.'],
            'already_paid' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Optional. Installments already paid before this month, for a loan that is already running.'],
            'notes' => ['type' => 'string', 'description' => 'Optional, e.g. loan account number or interest rate.'],
        ], ['name', 'bank_account', 'amount', 'tenure_months']);
    }

    public function handle(array $arguments, User $user): array
    {
        $name = trim((string) ($arguments['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255) {
            throw new ToolException('Say what the EMI is for (up to 255 characters).');
        }

        $bank = trim((string) ($arguments['bank_account'] ?? ''));
        if ($bank === '' || mb_strlen($bank) > 255) {
            throw new ToolException('Say which bank account, card or lender it is paid through.');
        }

        $amount = $arguments['amount'] ?? null;
        if (! is_numeric($amount) || (float) $amount <= 0) {
            throw new ToolException('amount must be a positive number of rupees per month.');
        }

        $tenure = $arguments['tenure_months'] ?? null;
        if (! is_numeric($tenure) || (int) $tenure != $tenure || (int) $tenure < 1 || (int) $tenure > 600) {
            throw new ToolException('tenure_months must be a whole number of months from 1 to 600.');
        }
        $tenure = (int) $tenure;

        $start = ExpenseSupport::month($arguments['start_month'] ?? null);

        $paid = (int) ($arguments['already_paid'] ?? 0);
        $pastMonths = max(0, (int) $start->diffInMonths(today()->startOfMonth(), false));
        if ($paid < 0 || $paid > $tenure) {
            throw new ToolException('already_paid must be between 0 and the tenure.');
        }
        if ($paid > $pastMonths) {
            throw new ToolException("Only {$pastMonths} installment(s) fell before this month, so already_paid cannot be {$paid}. Use record_expense_payment for this month.");
        }

        // The same loan twice would double every month's dues.
        $clash = Expense::query()->where('type', Expense::TYPE_EMI)
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])
            ->get()->first(fn (Expense $e) => ! $e->lastMonth() || $e->lastMonth()->gte(today()->startOfMonth()));
        if ($clash) {
            throw new ToolException("There is already a running EMI called \"{$clash->name}\" (id {$clash->id}). Use a different name if this is a separate loan.");
        }

        // One spelling per bank: reuse what the list already says.
        $known = Expense::query()->whereIn('type', [Expense::TYPE_EMI, Expense::TYPE_BILL])
            ->whereNotNull('payee')->pluck('payee')->unique();
        $bank = $known->first(fn ($p) => mb_strtolower(trim($p)) === mb_strtolower($bank)) ?? $bank;

        $notes = filled($arguments['notes'] ?? null) ? mb_substr(trim($arguments['notes']), 0, 255) : null;

        $emi = DB::transaction(function () use ($name, $bank, $amount, $tenure, $start, $paid, $notes) {
            $emi = Expense::create([
                'name' => $name,
                'type' => Expense::TYPE_EMI,
                'payee' => $bank,
                'amount' => round((float) $amount, 2),
                'start_month' => $start->toDateString(),
                'installments' => $tenure,
                'notes' => $notes,
                'is_active' => true,
            ]);

            // Months already behind the studio, filed as paid in their own month.
            for ($i = 0; $i < $paid; $i++) {
                $month = $start->copy()->addMonthsNoOverflow($i);
                app(ExpenseLedger::class)->record($emi, $month, (float) $emi->amount, $month->toDateString(), 'Paid before it was added');
            }

            return $emi;
        });

        $last = $emi->lastMonth();

        return [
            'created' => true,
            'expense_id' => $emi->id,
            'name' => $emi->name,
            'bank_account' => $emi->payee,
            'monthly_amount' => (float) $emi->amount,
            'tenure_months' => $tenure,
            'first_month' => $start->format('Y-m'),
            'last_month' => $last?->format('Y-m'),
            'total_payable' => round((float) $emi->amount * $tenure, 2),
            'already_paid' => $paid,
            'installments_left' => $tenure - $paid,
            'notes' => $emi->notes,
        ];
    }
}
