<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * The WhatsApp CRM module's landing page.
 *
 * Placeholder for now -- a later task decides what this actually shows
 * (likely the campaigns list). This exists so the module is routable and
 * navigable end to end: the `whatsapp-crm.view` gate, the route, and the
 * sidebar link all need somewhere real to point.
 */
class WhatsappCrmDashboardController extends Controller
{
    public function index(): View
    {
        return view('whatsapp-crm.dashboard');
    }
}
