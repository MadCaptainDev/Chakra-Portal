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
     * @param  array<string, float>  $ctx
     * @return array{labels: list<string>, values: list<float>, colors: list<string>}
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
        ])->filter(fn (array $row) => $row['value'] > 0)->values();

        return [
            'labels' => $rows->pluck('label')->all(),
            'values' => $rows->pluck('value')->all(),
            'colors' => $rows->pluck('color')->all(),
        ];
    }

    /**
     * Prioritized work list for the company this month.
     *
     * @param  array<string, mixed>  $ctx
     * @return list<array{tone: string, title: string, detail: string, href: string, cta: string}>
     */
    private function actionItems(array $ctx): array
    {
        $items = [];

        if ($ctx['pendingApprovalCount'] > 0) {
            $items[] = [
                'tone' => 'amber',
                'title' => $ctx['pendingApprovalCount'].' invoice(s) need approval',
                'detail' => 'Nothing goes out until these are approved.',
                'href' => route('invoices.index', ['status' => 'pending_approval']),
                'cta' => 'Review',
            ];
        }

        if ($ctx['overdueCount'] > 0) {
            $items[] = [
                'tone' => 'red',
                'title' => $ctx['overdueCount'].' overdue invoice(s)',
                'detail' => number_format($ctx['overdueAmount'], 2).' waiting to be collected.',
                'href' => route('invoices.index', ['status' => 'unpaid']),
                'cta' => 'Chase',
            ];
        }

        if ($ctx['salaryPending'] > 0) {
            $items[] = [
                'tone' => 'amber',
                'title' => 'Payroll incomplete',
                'detail' => number_format($ctx['salaryPending'], 2).' pending across '.$ctx['salaryUnpaidCount'].' staff.',
                'href' => route('salaries.index', ['month' => $ctx['monthKey']]),
                'cta' => 'Pay staff',
            ];
        }

        if ($ctx['emiUnpaidCount'] > 0) {
            $items[] = [
                'tone' => 'red',
                'title' => $ctx['emiUnpaidCount'].' EMI payment(s) open',
                'detail' => 'Keep finance schedules current to avoid penalties.',
                'href' => route('emi.index', ['month' => $ctx['monthKey']]),
                'cta' => 'Pay EMI',
            ];
        }

        if ($ctx['billPending'] > 0) {
            $items[] = [
                'tone' => 'amber',
                'title' => 'Bills still open',
                'detail' => number_format($ctx['billPending'], 2).' budgeted but not fully paid.',
                'href' => route('bills.index', ['month' => $ctx['monthKey']]),
                'cta' => 'Pay bills',
            ];
        }

        if ($ctx['invoiceOutstanding'] > 0 && $ctx['overdueCount'] === 0) {
            $items[] = [
                'tone' => 'brand',
                'title' => 'Month invoice balance open',
                'detail' => number_format($ctx['invoiceOutstanding'], 2).' of this month’s invoices not yet collected.',
                'href' => route('invoices.index', ['month' => $ctx['monthKey']]),
                'cta' => 'Follow up',
            ];
        }

        if ($ctx['netCash'] < 0) {
            $items[] = [
                'tone' => 'red',
                'title' => 'Cash outflow ahead of collections',
                'detail' => 'This month paid out '.number_format(abs($ctx['netCash']), 2).' more than collected. Prioritize receivables.',
                'href' => route('invoices.index', ['status' => 'unpaid']),
                'cta' => 'Collections',
            ];
        }

        if ($items === []) {
            $items[] = [
                'tone' => 'green',
                'title' => 'Nothing urgent right now',
                'detail' => 'Payroll, EMIs, bills and approvals look under control for this month.',
                'href' => route('expenses.index', ['month' => $ctx['monthKey']]),
                'cta' => 'Overview',
            ];
        }

        return $items;
    }
}
