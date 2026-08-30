<?php

namespace App\Http\Controllers;

use App\Models\WhatsappFlow;
use App\Models\WhatsappFlowSession;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Read-only: the operator-facing "why did this contact get stuck" screen.
 *
 * Nothing here ever advances or edits a session -- that is FlowEngine's job
 * alone, running off the real webhook and queue paths. This only ever reads
 * WhatsappFlowSession rows for debugging: which flow, which contact, what
 * node it is sitting on, and why it stopped (last_error) if it did.
 */
class WhatsappFlowSessionController extends Controller
{
    public function index(Request $request): View
    {
        $flowId = $request->integer('flow') ?: null;
        $status = trim((string) $request->string('status'));

        $sessions = WhatsappFlowSession::query()
            ->with('flow')
            ->when($flowId, fn ($query) => $query->where('flow_id', $flowId))
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('whatsapp-crm.flow-sessions.index', [
            'sessions' => $sessions,
            'flows' => WhatsappFlow::orderBy('name')->get(['id', 'name']),
            'selectedFlowId' => $flowId,
            'selectedStatus' => $status,
        ]);
    }

    public function show(WhatsappFlowSession $flowSession): View
    {
        return view('whatsapp-crm.flow-sessions.show', [
            'session' => $flowSession->load('flow'),
        ]);
    }
}
