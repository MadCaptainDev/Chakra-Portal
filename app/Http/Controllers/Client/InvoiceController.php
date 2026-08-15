<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\ResolvesClient;
use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Services\InvoiceDocumentRenderer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * The client's own invoices, and the PDF of one.
 *
 * The route model binding is not used and must not be: {invoice} in the URL is
 * resolved through this client's own query, so another client's id is a 404
 * before anything is loaded. A 404 rather than a 403, because "that invoice
 * exists but is not yours" is itself something a client should not learn.
 */
class InvoiceController extends Controller
{
    use ResolvesClient;

    public function index(Request $request): View
    {
        $client = $this->client($request);

        $invoices = $this->issuedInvoices($client)
            ->with(['payments' => fn ($query) => $query->orderByDesc('paid_on')])
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->get();

        return view('client.invoices', [
            'client' => $client,
            'invoices' => $invoices,
            'outstanding' => $invoices->sum(fn (Invoice $invoice) => $invoice->balanceDue()),
            'paidTotal' => $invoices->sum(fn (Invoice $invoice) => $invoice->paidTotal()),
        ]);
    }

    /**
     * The same document the studio sends, rendered by the same service.
     *
     * Deliberately not a second template. A client comparing the PDF they
     * downloaded here against the one they were emailed and finding them
     * different would be worse than having no download at all.
     */
    public function pdf(Request $request, int $invoice, InvoiceDocumentRenderer $renderer): Response
    {
        $client = $this->client($request);

        $model = $this->issuedInvoices($client)
            ->with(['items', 'client'])
            ->findOrFail($invoice);

        $html = $renderer->render($model, CompanySetting::current());

        return Pdf::loadHTML($html)->setPaper('a4')->download($model->invoice_number.'.pdf');
    }
}
