<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

/**
 * The WhatsApp CRM module's landing page.
 *
 * Redirects to the campaigns list -- the module's natural front page, and
 * the one screen that already shows everything else (contacts, phonebooks,
 * templates) feeds into. Kept as its own controller/route rather than
 * pointing the sidebar link straight at campaigns.index, so the `whatsapp-
 * crm.view` gate still has one fixed landing spot to redirect unauthorized
 * or bare `/whatsapp-crm` visits to.
 */
class WhatsappCrmDashboardController extends Controller
{
    public function index(): RedirectResponse
    {
        return redirect()->route('whatsapp-crm.campaigns.index');
    }
}
