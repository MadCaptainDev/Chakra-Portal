<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\ResolvesClient;
use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\ContentItem;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Shoot;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The client's first screen: what they owe, what went out, what is coming.
 *
 * Five figures first, and nothing competes with them for attention -- a
 * client opens this to answer one of three questions and leave, and a page
 * that makes them read to find the balance has failed at the only thing it
 * is for. Team/AMC/announcements below are the deliberate exception: each
 * renders only when there is something real to say (a team actually
 * assigned, a SaaS product actually theirs, an announcement actually opted
 * in for clients -- see Announcement::scopeVisibleToClients()), so a client
 * with none of those never scrolls past an empty section, and the five
 * figures stay exactly what greets everyone else.
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

            /*
             * Null until the client has saved something. Loaded rather than
             * created: opening the dashboard must not start a brief on their
             * behalf, or "not started" stops meaning anything.
             */
            'brief' => $client->brief()->with('answers')->first(),
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
            'teamMembers' => $client->teamMembers()->get(),
            'saasProducts' => $client->saasProducts()->get(),
            'announcements' => Announcement::visibleToClients()->latest()->take(5)->get(),
        ]);
    }
}
