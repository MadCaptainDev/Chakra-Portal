<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\ExpenseLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Money, on one screen (as designed): a month's money in and out, what is
 * overdue, what is still to come in, and what is still to pay. Admin only,
 * like the dashboard: it reads across every client's money. The figures
 * come from the same ledgers the dashboard uses (Payment, ExpenseLedger).
 */
class MoneyOverviewController extends Controller
{
    public function __construct(private readonly ExpenseLedger $ledger) {}

    public function index(Request $request): View
    {
        $month = rescue(fn () => Carbon::parse($request->query('month', now()->format('Y-m')).'-01')->startOfMonth(), now()->startOfMonth(), false);
        $end = $month->copy()->endOfMonth();

        $in = Payment::query()->with('invoice.client')->whereBetween('paid_on', [$month, $end])->orderByDesc('paid_on')->get();
        $rows = $this->ledger->rowsFor($month, Expense::dueIn($month));
        $paidOut = (float) $rows->sum('paid');

        $unpaid = Invoice::unpaid()->with(['client', 'payments'])->orderBy('due_date')->get();
        $overdue = $unpaid->filter->isOverdue()->values();
        $coming = $unpaid->reject->isOverdue()->values();

        return view('money.index', [
            'month' => $month,
            'cameIn' => (float) $in->sum('amount'),
            'paidOut' => $paidOut,
            'payments' => $in->take(6),
            'overdue' => $overdue,
            'overdueTotal' => (float) $overdue->sum(fn (Invoice $i) => $i->balanceDue()),
            'coming' => $coming->take(8),
            'comingTotal' => (float) $coming->sum(fn (Invoice $i) => $i->balanceDue()),
            'toPay' => $rows->map(fn ($r) => $r + ['left' => max($r['due'] - $r['paid'], 0)])->filter(fn ($r) => $r['left'] > 0.5)->sortByDesc('left')->values(),
            'pendingApproval' => Invoice::pendingApproval()->count(),
        ]);
    }
}
