<?php

namespace App\Http\Controllers;

use App\Models\CompanySetting;
use App\Models\Quotation;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one quotation page a client can open without logging in -- same
 * reasoning as PublicInvoiceController.
 */
class PublicQuotationController extends Controller
{
    /**
     * A 404 rather than a 403 for an unknown token: there is nothing to
     * authenticate as, so "no" and "wrong" read the same.
     */
    public function pdf(string $token): Response
    {
        $quotation = Quotation::with('client', 'items')->where('public_token', $token)->first();

        abort_if($quotation === null, 404);

        $settings = CompanySetting::current();
        $html = view('quotations.document', compact('quotation', 'settings'))->render();

        $pdf = Pdf::loadHTML($html)->setPaper('a4');

        return $pdf->stream(($quotation->quotation_number ?? 'quotation').'.pdf');
    }
}
