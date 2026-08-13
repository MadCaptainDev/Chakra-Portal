<?php

namespace App\Http\Controllers;

use App\Models\Shoot;
use Illuminate\View\View;

/**
 * The call sheet — what the crew opens at 5:40am on the way to set.
 *
 * A web page with print styles rather than a PDF. It is read on a phone far
 * more often than it is printed, dompdf renders none of the Tailwind this app
 * is built from, and the existing PDF path exists only because invoices need
 * pixel-exact branded output that gets emailed and archived. Browser
 * print-to-PDF covers the rare genuine print.
 *
 * Internal notes are deliberately left off: a call sheet gets forwarded.
 */
class CallSheetController extends Controller
{
    public function show(Shoot $shoot): View
    {
        $shoot->load(['client', 'crew.user', 'kit.item.category', 'scripts']);

        return view('shoots.call-sheet', ['shoot' => $shoot]);
    }
}
