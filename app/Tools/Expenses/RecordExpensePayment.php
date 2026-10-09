<?php

namespace App\Tools\Expenses;

use App\Models\Expense;
use App\Models\User;
use App\Services\ExpenseLedger;
use App\Tools\Tool;
use App\Tools\ToolException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

class RecordExpensePayment extends Tool
{
    public function name(): string
    {
        return 'record_expense_payment';
    }

    public function title(): string
    {
        return 'Mark an EMI, salary or bill paid';
    }

    public function group(): string
    {
        return 'Expenses';
    }

    public function description(): string
    {
        return 'Mark the studio\'s own recurring outgoing as paid for a month: an EMI installment, an '
            .'employee\'s salary, or a monthly bill. Example: "paid the HDFC camera loan EMI for October", '
            .'"Sanjai\'s salary is paid". Pass the expense_id from expenses_due, or a name. Leave amount out '
            .'to record the standard amount; give it for a part payment. If that month already has a '
            .'payment recorded, this refuses and says what is there -- pass replace_existing true only when '
            .'the person says to correct it. NOT for one-time spends (log_expense) or money a client paid '
            .'the studio (record that in the portal). Returns what was recorded and, for an EMI, how many '
            .'installments and how much are left.';
    }

    public function permission(): ?string
    {
        return 'expenses.edit';
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
            'expense' => ['type' => 'string', 'description' => 'The expense_id from expenses_due, or the EMI / bill / employee name.'],
            'month' => ['type' => 'string', 'description' => 'Optional. YYYY-MM the payment is FOR; defaults to this month.'],
            'amount' => ['type' => 'number', 'description' => 'Optional. Rupees paid; leave out for the standard amount.'],
            'paid_on' => ['type' => 'string', 'description' => 'Optional. YYYY-MM-DD the money went out; defaults to today.'],
            'note' => ['type' => 'string', 'description' => 'Optional, e.g. a UTR or "paid by UPI".'],
            'replace_existing' => ['type' => 'boolean', 'description' => 'Optional. Overwrite a payment already recorded for that month. Only when the person asked to correct it.'],
        ], ['expense']);
    }

    public function handle(array $arguments, User $user): array
    {
        $expense = ExpenseSupport::resolveRecurring($arguments['expense'] ?? null, $user);
        $month = ExpenseSupport::month($arguments['month'] ?? null);

        // Salaries are their own module on the screens, and so they are here.
        if ($expense->type === Expense::TYPE_SALARY && ! Gate::forUser($user)->allows('salaries.edit')) {
            throw new ToolException('Recording a salary needs the Salaries edit permission, which this account does not have.');
        }

        if (! $expense->isDueIn($month)) {
            throw new ToolException(match ($expense->type) {
                Expense::TYPE_EMI => "{$expense->name} has no installment in {$month->format('F Y')} — its term runs "
                    .($expense->start_month?->format('M Y') ?? '?').' to '.($expense->lastMonth()?->format('M Y') ?? '?').'.',
                default => "{$expense->name} is marked inactive, so nothing is due for {$month->format('F Y')}. Reactivate it in the portal first.",
            });
        }

        $amount = $arguments['amount'] ?? $expense->amount;
        if (! is_numeric($amount) || (float) $amount <= 0) {
            throw new ToolException('amount must be a positive number of rupees. To undo a payment, use the portal.');
        }
        $amount = round((float) $amount, 2);

        $paidOn = $arguments['paid_on'] ?? today()->toDateString();
        if (! is_string($paidOn) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidOn)) {
            throw new ToolException('paid_on must be YYYY-MM-DD.');
        }
        if (Carbon::parse($paidOn)->isAfter(today())) {
            throw new ToolException('paid_on is in the future — only record money that has gone out.');
        }

        /*
         * The ledger keeps one payment per month and overwrites it. A model
         * told "paid another ₹5,000" would otherwise replace the first ₹5,000
         * instead of adding to it, so an existing record stops the call and
         * the model has to say which it means.
         */
        $previous = $expense->recordedPaidFor($month);
        if ($previous > 0 && empty($arguments['replace_existing'])) {
            throw new ToolException(
                "{$expense->name} already has ₹".number_format($previous, 2)." recorded for {$month->format('F Y')}. "
                .'Nothing was changed. If the person wants that figure corrected, call again with the new total as '
                .'amount and replace_existing true; for a further part payment, pass the combined total.'
            );
        }

        $note = filled($arguments['note'] ?? null) ? mb_substr(trim($arguments['note']), 0, 255) : null;

        $ledger = app(ExpenseLedger::class);

        // Cleared first rather than left to record()'s updateOrCreate, whose
        // match on the period string misses under SQLite's datetime storage.
        if ($previous > 0) {
            $ledger->clear($expense, $month);
        }

        $ledger->record($expense, $month, $amount, $paidOn, $note);

        $result = [
            'recorded' => true,
            'expense_id' => $expense->id,
            'name' => $expense->name,
            'type' => $expense->type,
            'month' => $month->format('Y-m'),
            'amount_paid' => $amount,
            'standard_amount' => (float) $expense->amount,
            'status' => $amount + 0.001 >= (float) $expense->amount ? 'paid' : 'part paid',
            'paid_on' => $paidOn,
            'replaced_previous_amount' => $previous > 0 ? $previous : null,
        ];

        if ($expense->isEmi()) {
            $expense->unsetRelation('payments');
            $result['installment'] = $expense->installmentNumberFor($month).' of '.$expense->installments;
            $result['installments_left_after_this_month'] = $expense->remainingAfterCurrentMonth($month);
            $result['still_owed_on_loan'] = round($expense->outstandingAmount($month), 2);
            $result['loan_cleared'] = $expense->isFinished($month);
        }

        return $result;
    }
}
