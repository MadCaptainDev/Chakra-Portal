<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $totalInvoiced = Invoice::whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PAID])->sum('total');

        $thisMonthRevenue = Payment::whereBetween('paid_on', [now()->startOfMonth(), now()->endOfMonth()])->sum('amount');

        $unpaidInvoices = Invoice::unpaid()->with('payments')->get();
        $outstanding = $unpaidInvoices->sum(fn (Invoice $invoice) => $invoice->balanceDue());

        $overdueInvoices = $unpaidInvoices->filter(fn (Invoice $invoice) => $invoice->isOverdue());
        $overdueCount = $overdueInvoices->count();
        $overdueAmount = $overdueInvoices->sum(fn (Invoice $invoice) => $invoice->balanceDue());

        $pendingApprovalCount = Invoice::pendingApproval()->count();

        $recentInvoices = Invoice::with('client')
            ->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PAID])
            ->latest('invoice_date')
            ->take(5)
            ->get();

        $monthlyRevenue = collect(range(5, 0))->map(function (int $monthsAgo) {
            $month = now()->subMonths($monthsAgo);

            return [
                'label' => $month->format('M'),
                'total' => (float) Payment::whereBetween('paid_on', [
                    $month->copy()->startOfMonth(),
                    $month->copy()->endOfMonth(),
                ])->sum('amount'),
            ];
        });

        return view('dashboard', compact(
            'totalInvoiced',
            'thisMonthRevenue',
            'outstanding',
            'overdueCount',
            'overdueAmount',
            'pendingApprovalCount',
            'recentInvoices',
            'monthlyRevenue',
        ));
    }
}
