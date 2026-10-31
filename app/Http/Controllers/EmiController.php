<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\Expense;
use App\Services\ExpenseLedger;
use App\Support\Banks;
use App\Support\LocksExpenseAmount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EmiController extends Controller
{
    use LocksExpenseAmount;

    public function __construct(private readonly ExpenseLedger $ledger) {}

    public function index(Request $request): View
    {
        $asOf = $this->ledger->resolveMonth($request->query('month'));

        $emis = Expense::where('type', Expense::TYPE_EMI)
            ->with('payments', 'bankAccount')
            ->orderBy('name')
            ->get();

        [$running, $finished] = $emis->partition(fn (Expense $e) => ! $e->isFinished($asOf));
        $running = $running->sortBy(fn (Expense $e) => $e->lastMonth()?->timestamp)->values();

        $dueThisMonth = $running->filter(fn (Expense $e) => $e->isDueIn($asOf))->values();
        $payRows = $this->ledger->rowsFor($asOf, $dueThisMonth)->keyBy(fn ($row) => $row['expense']->id);

        return view('emi.index', [
            'asOf' => $asOf,
            'month' => $asOf,
            'running' => $running,
            'finished' => $finished->values(),
            'payRows' => $payRows,
            'outstanding' => $running->sum(fn (Expense $e) => $e->outstandingAmount($asOf)),
            'monthlyLoad' => $dueThisMonth->sum(fn (Expense $e) => (float) $e->amount),
            'monthlyPaid' => $payRows->sum('paid'),
            'byBank' => $this->byBank($running, $asOf),
            'deposits' => $this->deposits($running, $payRows, $asOf),
            'accounts' => BankAccount::forPicker(),
            'banks' => Banks::options(),
            'timeline' => $this->timeline($emis, $asOf),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        DB::transaction(fn () => Expense::create($this->withAccount($request, $this->validated($request))));

        return redirect()->route('emi.index')->with('status', 'EMI added.');
    }

    public function update(Request $request, Expense $emi): RedirectResponse
    {
        // Without this, PUT /emi/{id} pointed at a salary would rewrite that
        // employee record into an EMI -- validated() force-sets the type.
        abort_unless($emi->isEmi(), 404);

        $unlocked = $request->boolean('unlock_amount');
        DB::transaction(fn () => $emi->update($this->withAccount($request, $this->validated($request, isUpdate: true))));

        $message = $unlocked
            ? 'EMI updated. Monthly amount changed.'
            : 'EMI updated.';

        return redirect()->route('emi.index')->with('status', $message);
    }

    public function destroy(Expense $emi): RedirectResponse
    {
        abort_unless($emi->isEmi(), 404);

        $emi->delete();

        return redirect()->route('emi.index')->with('status', 'EMI deleted.');
    }

    public function pay(Request $request, Expense $emi): RedirectResponse
    {
        abort_unless($emi->type === Expense::TYPE_EMI, 404);

        $validated = $request->validate([
            'month' => ['required', 'date'],
            'amount_paid' => ['required', 'numeric', 'min:0'],
        ]);

        $month = Carbon::parse($validated['month'])->startOfMonth();

        if (! $emi->isDueIn($month)) {
            return redirect()
                ->route('emi.index', ['month' => $month->format('Y-m')])
                ->with('error', "{$emi->name} is not payable in {$month->format('F Y')}.");
        }

        $this->ledger->record($emi, $month, (float) $validated['amount_paid']);

        return redirect()
            ->route('emi.index', ['month' => $month->format('Y-m')])
            ->with('status', (float) $validated['amount_paid'] > 0
                ? "Recorded {$emi->name} for {$month->format('F Y')}."
                : "Cleared {$emi->name} for {$month->format('F Y')}.");
    }

    // ——— Accounts ———

    public function storeAccount(Request $request): RedirectResponse
    {
        $account = BankAccount::create($this->accountData($request));

        return redirect()->route('emi.index')->with('status', 'Added '.$account->label().'.');
    }

    public function updateAccount(Request $request, BankAccount $account): RedirectResponse
    {
        $account->update($this->accountData($request));
        // Keep the old free-text column in step for the screens that read it.
        Expense::where('bank_account_id', $account->id)->update(['payee' => $account->payeeText()]);

        return redirect()->route('emi.index')->with('status', 'Saved '.$account->label().'.');
    }

    public function destroyAccount(BankAccount $account): RedirectResponse
    {
        if ($account->emis()->exists()) {
            return redirect()->route('emi.index')->with('error', 'Move its EMIs to another account first.');
        }

        $account->delete();

        return redirect()->route('emi.index')->with('status', 'Account removed.');
    }

    /** @return array<string, mixed> */
    private function accountData(Request $request, string $prefix = ''): array
    {
        $data = $request->validate([
            $prefix.'bank' => ['nullable', Rule::in(array_keys(Banks::ALL))],
            $prefix.'holder_name' => ['nullable', 'string', 'max:255', 'required_without:'.$prefix.'bank'],
            $prefix.'last4' => ['nullable', 'digits:4'],
            $prefix.'nickname' => ['nullable', 'string', 'max:255'],
            $prefix.'account_notes' => ['nullable', 'string', 'max:255'],
        ], [
            $prefix.'holder_name.required_without' => 'Pick a bank, or say who is paid directly.',
        ]);

        return [
            'bank' => $data[$prefix.'bank'] ?? null,
            'holder_name' => filled($data[$prefix.'holder_name'] ?? null) ? trim($data[$prefix.'holder_name']) : null,
            'last4' => $data[$prefix.'last4'] ?? null,
            'nickname' => filled($data[$prefix.'nickname'] ?? null) ? trim($data[$prefix.'nickname']) : null,
            'notes' => $data[$prefix.'account_notes'] ?? null,
        ];
    }

    /**
     * The account an EMI form picked -- an existing one, or "new" with its
     * own fields -- set on the EMI, with payee kept as its readable text.
     */
    private function withAccount(Request $request, array $data): array
    {
        $choice = $request->input('bank_account_id');

        if ($choice === 'new') {
            $account = BankAccount::create($this->accountData($request, 'new_'));
        } elseif (filled($choice)) {
            $account = BankAccount::find($request->validate(['bank_account_id' => ['integer', Rule::exists('bank_accounts', 'id')]])['bank_account_id']);
        } else {
            return $data + ['bank_account_id' => null];
        }

        return array_merge($data, ['bank_account_id' => $account->id, 'payee' => $account->payeeText()]);
    }

    /**
     * This month's deposit plan: for each account, how much has to be put
     * into it for the EMIs due this month, and how much of that is paid.
     *
     * @param  \Illuminate\Support\Collection<int, Expense>  $running
     * @return list<array<string, mixed>>
     */
    private function deposits($running, $payRows, Carbon $asOf): array
    {
        return $running->groupBy(fn (Expense $e) => $e->bank_account_id ?? 0)
            ->map(function ($group, $accountId) use ($payRows, $asOf) {
                $due = $group->filter(fn (Expense $e) => $e->isDueIn($asOf));
                $dueTotal = $due->sum(fn (Expense $e) => (float) ($payRows->get($e->id)['due'] ?? $e->amount));
                $paid = $due->sum(fn (Expense $e) => min((float) ($payRows->get($e->id)['paid'] ?? 0), (float) ($payRows->get($e->id)['due'] ?? $e->amount)));

                return [
                    'account' => $group->first()->bankAccount,
                    'emis' => $group->values(),
                    'due' => $dueTotal,
                    'paid' => $paid,
                    'left' => max($dueTotal - $paid, 0),
                    'outstanding' => $group->sum(fn (Expense $e) => $e->outstandingAmount($asOf)),
                ];
            })
            // What still has to be deposited first; settled and idle accounts last.
            ->sortBy([[fn ($d) => $d['left'] > 0.001 ? 0 : 1, 'asc'], ['left', 'desc'], ['outstanding', 'desc']])
            ->values()
            ->all();
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
    private function validated(Request $request, bool $isUpdate = false): array
    {
        $rules = $this->withLockedAmountRules($request, [
            'name' => ['required', 'string', 'max:255'],
            'payee' => ['nullable', 'string', 'max:255'],
            'start_month' => ['required', 'date'],
            'installments' => ['required', 'integer', 'min:1', 'max:600'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], $isUpdate);

        $validator = Validator::make($request->all(), $rules);
        $validator->after(fn ($v) => $this->confirmAmountUnlock($request, $v, $isUpdate));
        $data = $validator->validate();

        $data = $this->withoutLockedAmount($request, $data, $isUpdate);
        $data['type'] = Expense::TYPE_EMI;
        $data['is_active'] = true;
        $data['start_month'] = Carbon::parse($data['start_month'])->startOfMonth()->toDateString();

        return $data;
    }
}
