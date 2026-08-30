<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\ClientBriefNudge;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The studio's side of the brand brief: hand out the link, close it, reopen
 * it, and take the answers away as text.
 *
 * Separate from Client\BriefController, which is the client filling it in.
 * These are staff actions on somebody else's brief and they carry different
 * permissions, so keeping them apart means neither controller has to ask which
 * kind of caller it is serving.
 */
class ClientBriefLinkController extends Controller
{
    /**
     * Issue a link, or replace the one already out there.
     *
     * firstOrCreate because a brief row may not exist yet -- the client has
     * never opened the form -- and there is no sense in refusing to invite
     * somebody who has not started.
     */
    public function issue(Request $request, Client $client): RedirectResponse
    {
        $brief = $client->brief()->firstOrCreate([]);
        $replacing = $brief->public_token !== null;

        $brief->issuePublicToken($request->user());

        return back()->with('status', $replacing
            ? 'New link created. The previous one stops working immediately.'
            : 'Link created. Anyone with it can fill in this brief once.');
    }

    public function revoke(Client $client): RedirectResponse
    {
        $client->brief?->revokePublicToken();

        return back()->with('status', 'Link closed. Answers already saved are kept.');
    }

    /**
     * Let a submitted brief be edited again.
     *
     * The escape hatch for the one-time rule, and the reason that rule is safe
     * to enforce: somebody will answer a question wrong and press Send, and
     * without this the only remedy is a staff member retyping a client's own
     * words on their behalf.
     */
    public function reopen(Client $client): RedirectResponse
    {
        $brief = $client->brief;

        if (! $brief?->exists) {
            return back()->with('status', 'Nothing to reopen — this brief has not been started.');
        }

        $brief->reopen();

        // Reopening without a working link would be a half-measure: the point
        // is that the client can get back in.
        if (! $brief->public_token) {
            $brief->issuePublicToken(request()->user());
        }

        return back()->with('status', 'Reopened. The client can edit and send it again on the same link.');
    }

    /**
     * Send the brand-brief reminder through the studio's connected WhatsApp.
     *
     * Free text when the client messaged within 24 hours; otherwise the
     * approved template. Either way the send is filed in WhatsApp CRM.
     */
    public function nudge(Request $request, Client $client): RedirectResponse
    {
        try {
            $conversation = app(ClientBriefNudge::class)->send($client, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $crmLink = $request->user()->can('whatsapp-crm.view')
            ? route('whatsapp-crm.inbox.show', $conversation)
            : null;

        return back()->with([
            'status' => 'Brand brief reminder sent on WhatsApp.',
            'whatsapp_crm_url' => $crmLink,
        ]);
    }

    /**
     * The brief as a text file.
     *
     * A download rather than a screen to copy from, because the thing people
     * actually do with this is paste it into a script document or a message to
     * whoever is writing -- and plain text is the one format that survives
     * both. Nothing here is generated that is not already on the client's
     * page; this is a shape, not a new disclosure.
     */
    public function export(Client $client): Response
    {
        $brief = $client->brief;

        abort_if(! $brief?->exists, 404);

        $filename = str($client->name)->slug().'-brand-brief.txt';

        return response($brief->loadMissing('answers', 'client')->toText(), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
