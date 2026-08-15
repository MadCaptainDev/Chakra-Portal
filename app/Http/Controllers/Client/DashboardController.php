<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\ResolvesClient;
use App\Http\Controllers\Controller;
use App\Models\ContentItem;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Shoot;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The client's first screen: what they owe, what went out, what is coming.
 *
 * Five figures and nothing else. A client opens this to answer one of three
 * questions and leave; a page that makes them read to find the balance has
 * failed at the only thing it is for.
 */
class DashboardController extends Controller
{
    use ResolvesClient;

    public function index(Request $request): View
    {
        $client = $this->client($request);

        $invoices = $this->issuedInvoices($client)->with('payments')->get();
        $outstanding = $invoices->sum(fn (Invoice $invoice) => $invoice->balanceDue());
        $overdue = $invoices->filter(fn (Invoice $invoice) => $invoice->isOverdue());

        $lastPayment = Payment::whereIn('invoice_id', $invoices->pluck('id'))
            ->orderByDesc('paid_on')
            ->first();

        return view('client.dashboard', [
            'client' => $client,
            'outstanding' => $outstanding,
            'overdueCount' => $overdue->count(),
            'overdueAmount' => $overdue->sum(fn (Invoice $invoice) => $invoice->balanceDue()),
            'invoiceCount' => $invoices->count(),
            'lastPayment' => $lastPayment,
            'publishedThisMonth' => (clone $client)->contentItems()
                ->whereDate('published_date', '>=', now()->startOfMonth()->toDateString())
                ->whereDate('published_date', '<=', now()->endOfMonth()->toDateString())
                ->count(),
            'publishedTotal' => $client->contentItems()->count(),
            'nextShoot' => Shoot::where('client_id', $client->id)->upcoming()->ordered()->first(),
        ]);
    }
}
