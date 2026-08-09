<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\ExpenseLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private readonly ExpenseLedger $ledger) {}

    public function index(): View
    {
        $month = now()->startOfMonth();
        $monthKey = $month->format('Y-m');
        $monthEnd = $month->copy()->endOfMonth();

        // —— Invoices (this month by invoice_date) ——
        $monthInvoices = Invoice::query()
            ->with(['client', 'payments'])
            ->whereDate('invoice_date', '>=', $month->toDateString())
            ->whereDate('invoice_date', '<=', $monthEnd->toDateString())
            ->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PAID])
            ->get();

        $invoiceDue = (float) $monthInvoices->sum('total');
        $invoicePaidAgainstMonth = (float) $monthInvoices->sum(
            fn (Invoice $invoice) => (float) $invoice->payments->sum('amount')
        );
        $invoiceOutstanding = max($invoiceDue - $invoicePaidAgainstMonth, 0);

        $thisMonthRevenue = (float) Payment::whereBetween('paid_on', [
            $month->copy()->startOfMonth(),
            $monthEnd,
        ])->sum('amount');

        $unpaidInvoices = Invoice::unpaid()->with(['client', 'payments'])->latest('due_date')->get();
        $outstanding = $unpaidInvoices->sum(fn (Invoice $invoice) => $invoice->balanceDue());
        $overdueInvoices = $unpaidInvoices->filter(fn (Invoice $invoice) => $invoice->isOverdue());
        $overdueCount = $overdueInvoices->count();
        $overdueAmount = $overdueInvoices->sum(fn (Invoice $invoice) => $invoice->balanceDue());
        $pendingApprovalCount = Invoice::pendingApproval()->count();
        $recentUnpaid = $unpaidInvoices->take(6);

        // —— Expenses (this month) ——
        $dueExpenses = Expense::dueIn($month);
        $expenseRows = $this->ledger->rowsFor($month, $dueExpenses);
        $expenseDue = (float) $expenseRows->sum('due');
        $expensePaid = (float) $expenseRows->sum('paid');
        $expenseOutstanding = max($expenseDue - $expensePaid, 0);

        $expenseByType = $expenseRows
            ->groupBy(fn ($row) => $row['expense']->type)
            ->map(fn ($group, $type) => [
                'type' => $type,
                'label' => Expense::TYPES[$type] ?? ucfirst((string) $type),
                'due' => (float) $group->sum('due'),
                'paid' => (float) $group->sum('paid'),
                'outstanding' => max((float) $group->sum('due') - (float) $group->sum('paid'), 0),
            ])
            ->values();

        $salaryRows = $expenseRows->filter(fn ($row) => $row['expense']->type === Expense::TYPE_SALARY);
        $salaryDue = (float) $salaryRows->sum('due');
        $salaryPaid = (float) $salaryRows->sum('paid');
        $salaryPending = max($salaryDue - $salaryPaid, 0);
        $salaryUnpaidCount = $salaryRows->filter(fn ($row) => $row['paid'] <= 0)->count();

        $emiRows = $expenseRows->filter(fn ($row) => $row['expense']->type === Expense::TYPE_EMI);
        $emiLoad = (float) $emiRows->sum('due');
        $emiPaid = (float) $emiRows->sum('paid');
        $emiUnpaidCount = $emiRows->filter(fn ($row) => $row['paid'] + 0.001 < $row['due'])->count();
        $emiOutstandingTotal = (float) Expense::where('type', Expense::TYPE_EMI)
            ->get()
            ->filter(fn (Expense $e) => ! $e->isFinished($month))
            ->sum(fn (Expense $e) => $e->outstandingAmount($month));

        $billRows = $expenseRows->filter(fn ($row) => $row['expense']->type === Expense::TYPE_BILL);
        $billPending = max((float) $billRows->sum('due') - (float) $billRows->sum('paid'), 0);

        $otherRows = $expenseRows->filter(fn ($row) => $row['expense']->type === Expense::TYPE_OTHER);
        $otherSpent = (float) $otherRows->sum('due');

        $emiOpen = max($emiLoad - $emiPaid, 0);

        // —— Charts: cashflow (6 months), expense pie, income pie, bottlenecks ——
        $monthlyCashflow = $this->monthlyCashflow(6);
        $expenseSplit = $this->expenseSplitChart($expenseByType);
        $incomeSplit = $this->incomeSplitByClient($month, $monthEnd, $monthInvoices);
        $bottlenecks = $this->bottlenecksChart([
            'overdueAmount' => $overdueAmount,
            'invoiceOutstanding' => $invoiceOutstanding,
            'salaryPending' => $salaryPending,
            'emiOpen' => $emiOpen,
            'billPending' => $billPending,
            'otherSpent' => $otherSpent,
        ]);

        $netCash = $thisMonthRevenue - $expensePaid;
        $burnCoverage = $expenseDue > 0
            ? round(($thisMonthRevenue / $expenseDue) * 100, 1)
            : null;

        $actionItems = $this->actionItems([
            'pendingApprovalCount' => $pendingApprovalCount,
            'overdueCount' => $overdueCount,
            'overdueAmount' => $overdueAmount,
            'expenseOutstanding' => $expenseOutstanding,
            'salaryPending' => $salaryPending,
            'salaryUnpaidCount' => $salaryUnpaidCount,
            'emiUnpaidCount' => $emiUnpaidCount,
            'billPending' => $billPending,
            'invoiceOutstanding' => $invoiceOutstanding,
            'monthKey' => $monthKey,
            'netCash' => $netCash,
        ]);

        return view('dashboard', [
            'month' => $month,
            'monthKey' => $monthKey,
            'invoiceDue' => $invoiceDue,
            'invoicePaid' => $invoicePaidAgainstMonth,
            'invoiceOutstanding' => $invoiceOutstanding,
            'thisMonthRevenue' => $thisMonthRevenue,
            'outstanding' => $outstanding,
            'overdueCount' => $overdueCount,
            'overdueAmount' => $overdueAmount,
            'pendingApprovalCount' => $pendingApprovalCount,
            'recentUnpaid' => $recentUnpaid,
            'expenseDue' => $expenseDue,
            'expensePaid' => $expensePaid,
            'expenseOutstanding' => $expenseOutstanding,
            'expenseByType' => $expenseByType,
            'salaryDue' => $salaryDue,
            'salaryPaid' => $salaryPaid,
            'salaryPending' => $salaryPending,
            'salaryUnpaidCount' => $salaryUnpaidCount,
            'emiLoad' => $emiLoad,
            'emiPaid' => $emiPaid,
            'emiOpen' => $emiOpen,
            'emiUnpaidCount' => $emiUnpaidCount,
            'emiOutstandingTotal' => $emiOutstandingTotal,
            'billPending' => $billPending,
            'otherSpent' => $otherSpent,
            'netCash' => $netCash,
            'burnCoverage' => $burnCoverage,
            'monthlyCashflow' => $monthlyCashflow,
            'expenseSplit' => $expenseSplit,
            'incomeSplit' => $incomeSplit,
            'bottlenecks' => $bottlenecks,
            'actionItems' => $actionItems,

            // Aliases for the outflow widget. The figures above come from the
            // same month and the same ledger rows, so recomputing them here
            // would only risk the two disagreeing.
            'outflowDue' => $expenseDue,
            'outflowPaid' => $expensePaid,
            'outflowPending' => $expenseOutstanding,
            'emiThisMonth' => $emiLoad,
        ]);
    }

    /**
     * Income vs outflow for the last N months (collections vs expense payments recorded).
     *
     * @return Collection<int, array{label: string, income: float, expense: float, net: float}>
     */
    private function monthlyCashflow(int $months): Collection
    {
        return collect(range($months - 1, 0))->map(function (int $monthsAgo) {
            $period = now()->subMonthsNoOverflow($monthsAgo)->startOfMonth();
            $end = $period->copy()->endOfMonth();

            $income = (float) Payment::whereBetween('paid_on', [$period, $end])->sum('amount');

            $expense = (float) ExpensePayment::whereDate('period', $period->toDateString())
                ->sum('amount_paid');

            return [
                'label' => $period->format('M'),
                'income' => $income,
                'expense' => $expense,
                'net' => $income - $expense,
            ];
        });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $expenseByType
     * @return array{labels: list<string>, values: list<float>, colors: list<string>}
     */
    private function expenseSplitChart(Collection $expenseByType): array
    {
        $colors = [
            'emi' => '#0f766e',
            'salary' => '#16a34a',
            'bill' => '#0284c7',
            'other' => '#ca8a04',
        ];

        $ordered = collect([Expense::TYPE_EMI, Expense::TYPE_SALARY, Expense::TYPE_BILL, Expense::TYPE_OTHER])
            ->map(function (string $type) use ($expenseByType, $colors) {
                $row = $expenseByType->firstWhere('type', $type);

                return [
                    'label' => Expense::TYPES[$type] ?? ucfirst($type),
                    'value' => (float) ($row['due'] ?? 0),
                    'color' => $colors[$type] ?? '#6b7280',
                ];
            })
            ->filter(fn (array $row) => $row['value'] > 0)
            ->values();

        return [
            'labels' => $ordered->pluck('label')->all(),
            'values' => $ordered->pluck('value')->all(),
            'colors' => $ordered->pluck('color')->all(),
        ];
    }

    /**
     * Income mix: prefer collections this month by client; fall back to invoiced totals.
     *
     * @param  Collection<int, Invoice>  $monthInvoices
     * @return array{labels: list<string>, values: list<float>, colors: list<string>, mode: string}
     */
    private function incomeSplitByClient(Carbon $month, Carbon $monthEnd, Collection $monthInvoices): array
    {
        $palette = ['#0f766e', '#ca8a04', '#0284c7', '#dc2626', '#7c3aed', '#ea580c', '#64748b'];

        $collected = Payment::query()
            ->whereBetween('paid_on', [$month, $monthEnd])
            ->with('invoice.client')
            ->get()
            ->groupBy(fn (Payment $payment) => $payment->invoice?->client?->name ?: 'Unknown')
            ->map(fn (Collection $group, string $name) => [
                'label' => $name,
                'value' => (float) $group->sum('amount'),
            ])
            ->sortByDesc('value')
            ->values();

        $mode = 'collected';

        if ($collected->sum('value') <= 0) {
            $mode = 'invoiced';
            $collected = $monthInvoices
                ->groupBy(fn (Invoice $invoice) => $invoice->client?->name ?: 'Unknown')
                ->map(fn (Collection $group, string $name) => [
                    'label' => $name,
                    'value' => (float) $group->sum('total'),
                ])
                ->sortByDesc('value')
                ->values();
        }

        // Top 5 + Other
        if ($collected->count() > 5) {
            $top = $collected->take(5);
            $other = (float) $collected->slice(5)->sum('value');
            $collected = $top->values();
            if ($other > 0) {
                $collected->push(['label' => 'Other', 'value' => $other]);
            }
        }

        return [
            'labels' => $collected->pluck('label')->all(),
            'values' => $collected->pluck('value')->all(),
            'colors' => $collected->values()->map(
                fn ($row, $i) => $palette[$i % count($palette)]
            )->all(),
            'mode' => $mode,
        ];
    }

    /**
     * Pressure points that block cashflow — amounts still open.
     *
     * Ordered biggest first: the chart is read to answer "what is holding the
     * most money", and a fixed order buries that under whichever category
     * happens to be listed first.
     *
     * @param  array<string, float>  $ctx
     * @return array{labels: list<string>, values: list<float>, colors: list<string>, total: float}
     */
    private function bottlenecksChart(array $ctx): array
    {
        $rows = collect([
            ['label' => 'Overdue invoices', 'value' => (float) $ctx['overdueAmount'], 'color' => '#dc2626'],
            ['label' => 'Month invoices open', 'value' => (float) $ctx['invoiceOutstanding'], 'color' => '#ca8a04'],
            ['label' => 'Payroll pending', 'value' => (float) $ctx['salaryPending'], 'color' => '#16a34a'],
            ['label' => 'EMI open', 'value' => (float) $ctx['emiOpen'], 'color' => '#0f766e'],
            ['label' => 'Bills pending', 'value' => (float) $ctx['billPending'], 'color' => '#0284c7'],
            ['label' => 'One-time spent', 'value' => (float) $ctx['otherSpent'], 'color' => '#64748b'],
        ])
            ->filter(fn (array $row) => $row['value'] > 0)
            ->sortByDesc('value')
            ->values();

        return [
            'labels' => $rows->pluck('label')->all(),
            'values' => $rows->pluck('value')->all(),
            'colors' => $rows->pluck('color')->all(),
            'total' => (float) $rows->sum('value'),
        ];
    }

    /**
     * Prioritized work list for the company this month.
     *
     * Every item carries a rank, and the list is sorted by it, so the top card
     * is always the most urgent thing rather than whichever check happens to
     * run first. 'amount' is what is at stake, rendered as the headline figure.
     *
     * @param  array<string, mixed>  $ctx
     * @return list<array{tone: string, rank: int, title: string, detail: string, amount: float|null, href: string, cta: string}>
     */
    private function actionItems(array $ctx): array
    {
        $items = [];

        if ($ctx['overdueCount'] > 0) {
            $items[] = [
                'tone' => 'red',
                'rank' => 1,
                'title' => $ctx['overdueCount'].' overdue invoice(s)',
                'detail' => 'Past their due date and still unpaid.',
                'amount' => (float) $ctx['overdueAmount'],
                'href' => route('invoices.index', ['status' => 'unpaid']),
                'cta' => 'Chase',
            ];
        }

        if ($ctx['netCash'] < 0) {
            $items[] = [
                'tone' => 'red',
                'rank' => 2,
                'title' => 'Paying out faster than collecting',
                'detail' => 'This month has paid out more than it collected. Prioritize receivables.',
                'amount' => abs((float) $ctx['netCash']),
                'href' => route('invoices.index', ['status' => 'unpaid']),
                'cta' => 'Collections',
            ];
        }

        if ($ctx['emiUnpaidCount'] > 0) {
            $items[] = [
                'tone' => 'red',
                'rank' => 3,
                'title' => $ctx['emiUnpaidCount'].' EMI payment(s) open',
                'detail' => 'Keep finance schedules current to avoid penalties.',
                'amount' => null,
                'href' => route('emi.index', ['month' => $ctx['monthKey']]),
                'cta' => 'Pay EMI',
            ];
        }

        if ($ctx['pendingApprovalCount'] > 0) {
            $items[] = [
                'tone' => 'amber',
                'rank' => 4,
                'title' => $ctx['pendingApprovalCount'].' invoice(s) need approval',
                'detail' => 'These are waiting for your approval — nothing goes out until then.',
                'amount' => null,
                'href' => route('invoices.index', ['status' => 'pending_approval']),
                'cta' => 'Review',
            ];
        }

        if ($ctx['salaryPending'] > 0) {
            $items[] = [
                'tone' => 'amber',
                'rank' => 5,
                'title' => 'Payroll incomplete',
                'detail' => 'Pending across '.$ctx['salaryUnpaidCount'].' staff.',
                'amount' => (float) $ctx['salaryPending'],
                'href' => route('salaries.index', ['month' => $ctx['monthKey']]),
                'cta' => 'Pay staff',
            ];
        }

        if ($ctx['billPending'] > 0) {
            $items[] = [
                'tone' => 'amber',
                'rank' => 6,
                'title' => 'Bills still open',
                'detail' => 'Budgeted for this month but not fully paid.',
                'amount' => (float) $ctx['billPending'],
                'href' => route('bills.index', ['month' => $ctx['monthKey']]),
                'cta' => 'Pay bills',
            ];
        }

        if ($ctx['invoiceOutstanding'] > 0 && $ctx['overdueCount'] === 0) {
            $items[] = [
                'tone' => 'brand',
                'rank' => 7,
                'title' => 'Month invoice balance open',
                'detail' => 'Invoiced this month, not yet collected. Nothing overdue.',
                'amount' => (float) $ctx['invoiceOutstanding'],
                'href' => route('invoices.index', ['month' => $ctx['monthKey']]),
                'cta' => 'Follow up',
            ];
        }

        if ($items === []) {
            $items[] = [
                'tone' => 'green',
                'rank' => 99,
                'title' => 'Nothing urgent right now',
                'detail' => 'Payroll, EMIs, bills and approvals look under control for this month.',
                'amount' => null,
                'href' => route('expenses.index', ['month' => $ctx['monthKey']]),
                'cta' => 'Overview',
            ];
        }

        usort($items, fn (array $a, array $b) => $a['rank'] <=> $b['rank']);

        return $items;
    }
}
