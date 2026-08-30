<?php

namespace App\Http\Controllers;

use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Services\InvoiceDocumentRenderer;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one invoice page a client can open without logging in.
 *
 * The token in the path is the only credential -- same reasoning as
 * PublicBriefController's brief/{token}, simpler in one way: an invoice has
 * no state a stranger could corrupt by opening it, so there is nothing here
 * to guard beyond "know the token".
 */
class PublicInvoiceController extends Controller
{
    /**
     * A 404 rather than a 403 for an unknown token: there is nothing to
     * authenticate as, so "no" and "wrong" read the same, and a 403 would
     * confirm a guessed token nearly worked.
     */
    public function pdf(string $token, InvoiceDocumentRenderer $renderer): Response
    {
        $invoice = Invoice::with('client', 'items')->where('public_token', $token)->first();

        abort_if($invoice === null, 404);

        $html = $renderer->render($invoice, CompanySetting::current());

        $pdf = Pdf::loadHTML($html)->setPaper('a4');

        return $pdf->stream(($invoice->invoice_number ?? 'invoice').'.pdf');
    }
}
