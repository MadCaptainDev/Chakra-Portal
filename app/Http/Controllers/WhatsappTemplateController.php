<?php

namespace App\Http\Controllers;

use App\Models\WhatsappSetting;
use App\Services\WhatsappTemplateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Read-only view of the Meta-approved message templates this account can
 * send. Templates live in Meta, not in this database -- there is nothing to
 * create, edit or delete here, only to list and refresh.
 */
class WhatsappTemplateController extends Controller
{
    public function index(): View
    {
        $canSend = WhatsappSetting::current()->canSend();

        return view('whatsapp-crm.templates.index', [
            'canSend' => $canSend,
            'templates' => $canSend ? WhatsappTemplateService::make()->list() : [],
        ]);
    }

    public function refresh(): RedirectResponse
    {
        WhatsappTemplateService::make()->refresh();

        return redirect()->route('whatsapp-crm.templates.index')
            ->with('status', 'Templates refreshed from Meta.');
    }
}
