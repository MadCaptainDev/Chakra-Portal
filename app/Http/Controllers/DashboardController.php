<?php

namespace App\Http\Controllers;

use App\Models\Enquiry;
use App\Models\NotionShoot;
use App\Models\RoutineOccurrence;
use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\ExpenseLedger;
use App\Services\Notion\NotionSyncRunner;
use App\Support\ContentDashboard;
use App\Support\ContributionGraph;
use App\Support\DashboardWidgets;
use App\Support\Metric;
use App\Support\PortfolioSuggestions;
use App\Support\TeamPulse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private readonly ExpenseLedger $ledger) {}

    public function index(Request $request): View
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

        $billRows = $expenseRows->filter(fn ($row) => $row['expense']->type === Expense::TYPE_BILL);
        $billPending = max((float) $billRows->sum('due') - (float) $billRows->sum('paid'), 0);

        $otherRows = $expenseRows->filter(fn ($row) => $row['expense']->type === Expense::TYPE_OTHER);
        $otherSpent = (float) $otherRows->sum('due');

        $emiOpen = max($emiLoad - $emiPaid, 0);

        // —— Breakdowns: cashflow (6 months), expense mix, income mix, bottlenecks ——
        $monthlyCashflow = $this->monthlyCashflow(6);
        $expenseSplit = $this->expenseSplit($expenseByType);
        $income = $this->incomeSplitByClient($month, $monthEnd, $monthInvoices);
        $bottlenecks = $this->bottlenecks([
            'overdueAmount' => $overdueAmount,
            'invoiceOutstanding' => $invoiceOutstanding,
            'salaryPending' => $salaryPending,
            'emiOpen' => $emiOpen,
            'billPending' => $billPending,
            'otherSpent' => $otherSpent,
            'monthKey' => $monthKey,
        ]);

        $netCash = $thisMonthRevenue - $expensePaid;
        $burnCoverage = $expenseDue > 0
            ? round(($thisMonthRevenue / $expenseDue) * 100, 1)
            : null;

        /*
         * The studio is not only money. Enquiries, the team and the website
         * each get their own read here, and each can raise an action item --
         * an owner should not have to visit four screens to learn what is
         * waiting.
         */
        $employees = TeamPulse::employees();
        $teamHours = TeamPulse::hours($employees, $month);
        $teamBehind = TeamPulse::behind($employees);
        $pendingReviews = (int) $teamHours->sum('pending');

        /*
         * Only the count, and only so an unanswered lead can raise an action
         * item. Enquiries and the portfolio had a dashboard section each; both
         * are screens people go to on purpose, and repeating them here made a
         * page nobody read to the bottom of.
         */
        $unreadEnquiries = Enquiry::unread()->count();

        $missedRoutinesCount = RoutineOccurrence::overdueCount();
        $missedRoutines = RoutineOccurrence::query()
            ->overdue()
            ->with(['routine', 'checkpoint', 'subject'])
            ->orderBy('due_on')
            ->orderBy('id')
            ->limit(5)
            ->get();

        // One candidate, not a section -- see PortfolioSuggestions::best()
        // for why a single link is the most this can ever offer.
        $portfolioSuggestion = PortfolioSuggestions::best();

        $actionItems = $this->actionItems([
            'unreadEnquiries' => $unreadEnquiries,
            'missedRoutinesCount' => $missedRoutinesCount,
            'portfolioSuggestion' => $portfolioSuggestion,
            'pendingReviews' => $pendingReviews,
            'behindCount' => $teamBehind->count(),
            'behindNames' => $teamBehind->take(2)
                ->map(fn (array $row) => Str::before($row['employee']->name, ' '))
                ->implode(' and '),
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
            // Twenty further figures used to be passed here, one per row of the
            // old bottlenecks widget. They are still computed above, because
            // bottlenecks() and actionItems() take them -- they are just no
            // longer handed to a view that stopped reading them.
            'month' => $month,
            'thisMonthRevenue' => $thisMonthRevenue,
            'outstanding' => $outstanding,
            'recentUnpaid' => $recentUnpaid,
            'expenseDue' => $expenseDue,
            'expensePaid' => $expensePaid,
            'netCash' => $netCash,
            'burnCoverage' => $burnCoverage,
            'monthlyCashflow' => $monthlyCashflow,
            'expenseSplit' => $expenseSplit,
            'incomeSplit' => $income['items'],
            'incomeMode' => $income['mode'],
            'bottlenecks' => $bottlenecks,
            'actionItems' => $actionItems,

            // Aliases for the outflow block. The figures above come from the
            // same month and the same ledger rows, so recomputing them here
            // would only risk the two disagreeing.
            'outflowDue' => $expenseDue,
            'outflowPaid' => $expensePaid,
            'outflowPending' => $expenseOutstanding,
            'emiThisMonth' => $emiLoad,

            // —— Team ——
            'teamHours' => $teamHours,
            'teamBehind' => $teamBehind,
            'teamMinutes' => (int) $teamHours->sum('minutes'),
            'pendingReviews' => $pendingReviews,
            'workGraph' => ContributionGraph::forTeam(),

            // —— Routines ——
            'missedRoutinesCount' => $missedRoutinesCount,
            'missedRoutines' => $missedRoutines,

            // —— Delivery ——
            'content' => $this->contentPulse($month),
            'contentAccounts' => DashboardWidgets::contentAccounts(),
            'contentCards' => DashboardWidgets::contentCards($request->user(), $month),
            'pinnedAccountIds' => DashboardWidgets::pinnedAccountsFor($request->user())->pluck('id'),
            'hasPinnedAccounts' => DashboardWidgets::hasPinned($request->user()),
        ]);
    }

    /**
     * Delivery, not money: how the month's content is tracking against
     * target, what is waiting to be shot, and what mapping is unfinished.
     *
     * The dashboard was entirely invoices, payroll and hours. A studio that
     * misses its content commitments still looks healthy on that page right
     * up to the moment a client asks why, so the thing the studio actually
     * sells now has a read here too.
     *
     * @return array<string, mixed>
     */
    private function contentPulse(Carbon $month): array
    {
        $board = ContentDashboard::forMonth($month);

        $upcoming = DashboardWidgets::upcomingShootsAll(5);

        return [
            'types' => $board['typeTotals'],
            'total' => $board['grandTotal'],
            'target' => $board['grandTarget'],
            'previous' => $board['grandPrevious'],
            'behind' => collect($board['clients'])
                ->flatMap(fn (array $group) => $group['rows'])
                ->filter(fn (array $row) => $row['target'] !== null && $row['total'] < $row['target'])
                ->sortBy('pct')
                ->take(5)
                ->values(),
            'unmappedVentures' => $board['unmapped']->count(),
            'unmappedThisMonth' => $board['unmappedThisMonth'],
            'upcomingShoots' => $upcoming,
            'shootsToImport' => NotionShoot::whereDoesntHave('shoot')->whereNotNull('shoot_date')->count(),
            'lastSynced' => NotionSyncRunner::lastSyncedAt(),
        ];
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
     * Expense mix for the month, as bar-list rows.
     *
     * These used to be returned as three parallel arrays because Chart.js wanted
     * labels/values/colors as separate datasets. Nothing needs that shape now.
     *
     * @param  Collection<int, array<string, mixed>>  $expenseByType
     * @return list<array{label: string, value: float, color: string, href: string}>
     */
    private function expenseSplit(Collection $expenseByType): array
    {
        $colors = [
            Expense::TYPE_EMI => '#0f766e',
            Expense::TYPE_SALARY => '#16a34a',
            Expense::TYPE_BILL => '#0284c7',
            Expense::TYPE_OTHER => '#ca8a04',
        ];

        $routes = [
            Expense::TYPE_EMI => 'emi.index',
            Expense::TYPE_SALARY => 'salaries.index',
            Expense::TYPE_BILL => 'bills.index',
            Expense::TYPE_OTHER => 'other.index',
        ];

        return collect([Expense::TYPE_EMI, Expense::TYPE_SALARY, Expense::TYPE_BILL, Expense::TYPE_OTHER])
            ->map(function (string $type) use ($expenseByType, $colors, $routes) {
                $row = $expenseByType->firstWhere('type', $type);
                $paid = (float) ($row['paid'] ?? 0);
                $due = (float) ($row['due'] ?? 0);

                return [
                    'label' => Expense::TYPES[$type] ?? ucfirst($type),
                    'value' => $due,
                    'color' => $colors[$type] ?? '#6b7280',
                    'href' => route($routes[$type]),
                    'hint' => $due > 0 ? number_format($paid, 0).' paid so far' : null,
                ];
            })
            ->filter(fn (array $row) => $row['value'] > 0)
            ->values()
            ->all();
    }

    /**
     * Income mix: prefer collections this month by client; fall back to invoiced totals.
     *
     * @param  Collection<int, Invoice>  $monthInvoices
     * @return array{items: list<array{label: string, value: float, color: string}>, mode: string}
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
            'items' => $collected->values()->map(fn (array $row, int $i) => [
                'label' => $row['label'],
                'value' => $row['value'],
                'color' => $palette[$i % count($palette)],
            ])->all(),
            'mode' => $mode,
        ];
    }

    /**
     * Pressure points that block cashflow — amounts still open.
     *
     * Each row carries the link to the screen that clears it, so the block is
     * a work queue rather than a picture of one.
     *
     * @param  array<string, mixed>  $ctx
     * @return list<array{label: string, value: float, color: string, href: string}>
     */
    private function bottlenecks(array $ctx): array
    {
        $monthKey = $ctx['monthKey'];

        return collect([
            ['label' => 'Overdue invoices', 'value' => (float) $ctx['overdueAmount'], 'color' => '#dc2626', 'href' => route('invoices.index', ['status' => 'unpaid'])],
            ['label' => 'Month invoices open', 'value' => (float) $ctx['invoiceOutstanding'], 'color' => '#ca8a04', 'href' => route('invoices.index', ['month' => $monthKey])],
            ['label' => 'Payroll pending', 'value' => (float) $ctx['salaryPending'], 'color' => '#16a34a', 'href' => route('salaries.index', ['month' => $monthKey])],
            ['label' => 'EMI open', 'value' => (float) $ctx['emiOpen'], 'color' => '#0f766e', 'href' => route('emi.index', ['month' => $monthKey])],
            ['label' => 'Bills pending', 'value' => (float) $ctx['billPending'], 'color' => '#0284c7', 'href' => route('bills.index', ['month' => $monthKey])],
            ['label' => 'One-time spent', 'value' => (float) $ctx['otherSpent'], 'color' => '#64748b', 'href' => route('other.index', ['month' => $monthKey])],
        ])->filter(fn (array $row) => $row['value'] > 0)->values()->all();
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

        /*
         * Enquiries lead. A lead going cold costs more than an unapproved
         * invoice sitting a day longer, and unlike everything below it there
         * is a person on the other end waiting to hear back.
         */
        if ($ctx['unreadEnquiries'] > 0) {
            $items[] = [
                'tone' => 'red',
                'domain' => 'Enquiries',
                'title' => $ctx['unreadEnquiries'].' '.Str::plural('enquiry', $ctx['unreadEnquiries']).' unread',
                'detail' => 'Nobody has opened these yet. Leads go cold fast.',
                'href' => route('enquiries.index'),
                'cta' => 'Open',
            ];
        }

        if (($ctx['missedRoutinesCount'] ?? 0) > 0) {
            $items[] = [
                'tone' => 'amber',
                'domain' => 'Routines',
                'title' => $ctx['missedRoutinesCount'].' missed '.Str::plural('duty', $ctx['missedRoutinesCount']),
                'detail' => 'Open occurrences past their due date — tick them or skip with a reason.',
                'href' => route('routines.calendar'),
                'cta' => 'Open calendar',
            ];
        }

        /*
         * A post/reel already outperforming the studio's own portfolio
         * average that nobody has added yet -- a positive opportunity, not
         * a problem, so it gets the neutral brand tone rather than amber/red.
         */
        if ($ctx['portfolioSuggestion']) {
            $suggestion = $ctx['portfolioSuggestion'];
            $media = $suggestion['media'];

            $items[] = [
                'tone' => 'brand',
                'domain' => 'Portfolio',
                'title' => 'A '.strtolower($media->typeLabel()).' is outperforming your portfolio and isn\'t added',
                'detail' => Metric::count($suggestion['value']).' '.$suggestion['metric']
                    .' — '.($media->shortCaption(70)).', '.($media->posted_at?->format('j M Y') ?? 'date unknown')
                    .'. The portfolio\'s own average is '.Metric::count($suggestion['bar']).'.',
                'href' => route('portfolio.create', ['client_id' => $suggestion['clientId'], 'media_id' => $media->id]),
                'cta' => 'Add it',
            ];
        }

        if ($ctx['pendingApprovalCount'] > 0) {
            $items[] = [
                'tone' => 'amber',
                'domain' => 'Money',
                'title' => $ctx['pendingApprovalCount'].' invoice(s) need approval',
                'detail' => 'These are waiting for your approval — nothing goes out until then.',
                'href' => route('invoices.index', ['status' => 'pending_approval']),
                'cta' => 'Review',
            ];
        }

        if ($ctx['overdueCount'] > 0) {
            $items[] = [
                'tone' => 'red',
                'domain' => 'Money',
                'title' => $ctx['overdueCount'].' overdue invoice(s)',
                'detail' => number_format($ctx['overdueAmount'], 2).' waiting to be collected.',
                'href' => route('invoices.index', ['status' => 'unpaid']),
                'cta' => 'Chase',
            ];
        }

        if ($ctx['salaryPending'] > 0) {
            $items[] = [
                'tone' => 'amber',
                'domain' => 'Money',
                'title' => 'Payroll incomplete',
                'detail' => number_format($ctx['salaryPending'], 2).' pending across '.$ctx['salaryUnpaidCount'].' staff.',
                'href' => route('salaries.index', ['month' => $ctx['monthKey']]),
                'cta' => 'Pay staff',
            ];
        }

        if ($ctx['emiUnpaidCount'] > 0) {
            $items[] = [
                'tone' => 'red',
                'domain' => 'Money',
                'title' => $ctx['emiUnpaidCount'].' EMI payment(s) open',
                'detail' => 'Keep finance schedules current to avoid penalties.',
                'href' => route('emi.index', ['month' => $ctx['monthKey']]),
                'cta' => 'Pay EMI',
            ];
        }

        if ($ctx['billPending'] > 0) {
            $items[] = [
                'tone' => 'amber',
                'domain' => 'Money',
                'title' => 'Bills still open',
                'detail' => number_format($ctx['billPending'], 2).' budgeted but not fully paid.',
                'href' => route('bills.index', ['month' => $ctx['monthKey']]),
                'cta' => 'Pay bills',
            ];
        }

        if ($ctx['invoiceOutstanding'] > 0 && $ctx['overdueCount'] === 0) {
            $items[] = [
                'tone' => 'brand',
                'domain' => 'Money',
                'title' => 'Month invoice balance open',
                'detail' => number_format($ctx['invoiceOutstanding'], 2).' of this month’s invoices not yet collected.',
                'href' => route('invoices.index', ['month' => $ctx['monthKey']]),
                'cta' => 'Follow up',
            ];
        }

        if ($ctx['pendingReviews'] > 0) {
            $items[] = [
                'tone' => 'amber',
                'domain' => 'Team',
                'title' => $ctx['pendingReviews'].' timesheet '.Str::plural('day', $ctx['pendingReviews']).' undecided',
                'detail' => 'Accept them or send them back with a reason — nobody knows their hours are accepted until you do.',
                'href' => route('timesheets.index'),
                'cta' => 'Review',
            ];
        }

        if ($ctx['behindCount'] > 0) {
            $items[] = [
                'tone' => 'amber',
                'domain' => 'Team',
                'title' => $ctx['behindCount'].' logged nothing this week',
                'detail' => $ctx['behindNames'] !== ''
                    ? $ctx['behindNames'].' '.($ctx['behindCount'] === 1 ? 'has' : 'have').' been quiet since Monday.'
                    : 'Nobody has logged hours since Monday.',
                'href' => route('timesheets.index'),
                'cta' => 'Chase',
            ];
        }

        if ($ctx['netCash'] < 0) {
            $items[] = [
                'tone' => 'red',
                'domain' => 'Money',
                'title' => 'Cash outflow ahead of collections',
                'detail' => 'This month paid out '.number_format(abs($ctx['netCash']), 2).' more than collected. Prioritize receivables.',
                'href' => route('invoices.index', ['status' => 'unpaid']),
                'cta' => 'Collections',
            ];
        }

        if ($items === []) {
            $items[] = [
                'tone' => 'green',
                'domain' => 'Money',
                'title' => 'Nothing urgent right now',
                'detail' => 'Payroll, EMIs, bills and approvals look under control for this month.',
                'href' => route('expenses.index', ['month' => $ctx['monthKey']]),
                'cta' => 'Overview',
            ];
        }

        return $items;
    }
}
