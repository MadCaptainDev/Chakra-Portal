<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Script;
use App\Models\User;
use App\Notifications\ScriptApproved;
use App\Notifications\ScriptChangesRequested;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\View\View;

/**
 * The same titled queue as Client\ScriptApprovalController, on a no-login
 * link instead of a session -- most clients never sign into the portal, so
 * this is what actually gets sent over WhatsApp.
 *
 * Same rule as PublicBriefController/PublicProposalController: the token IS
 * the authorisation, there is no {client} in any of these paths, and an
 * unknown token is a plain 404 -- "wrong" and "closed" are the same answer to
 * a stranger.
 */
class PublicScriptApprovalController extends Controller
{
    public function index(string $token): View
    {
        $client = $this->resolve($token);

        $scripts = Script::pendingClientReview($client->id)
            ->with(['scriptTypeTerm', 'platformTerm'])
            ->get();

        return view('client.scripts-public', [
            'client' => $client,
            'token' => $token,
            'scripts' => $scripts,
        ]);
    }

    public function show(string $token, int $script): View
    {
        $client = $this->resolve($token);

        $model = Script::pendingClientReview($client->id)
            ->with(['sections', 'comments', 'writer'])
            ->findOrFail($script);

        return view('client.scripts-public-show', [
            'client' => $client,
            'token' => $token,
            'script' => $model,
        ]);
    }

    public function comment(Request $request, string $token, int $script): RedirectResponse
    {
        $client = $this->resolve($token);

        $model = Script::pendingClientReview($client->id)->findOrFail($script);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $model->comments()->create([
            'user_id' => $this->commentAuthor($client)->id,
            'body' => $data['body'],
        ]);

        return redirect(route('client.scripts.public.show', [$token, $model]).'#comments');
    }

    public function approve(string $token, int $script): RedirectResponse
    {
        $client = $this->resolve($token);

        $model = Script::pendingClientReview($client->id)->findOrFail($script);

        $model->approveByClient();

        Notification::send(User::canSee('scripts')->get(), new ScriptApproved($model));

        return redirect()->route('client.scripts.public', $token)->with('status', 'Approved — thank you.');
    }

    public function requestChanges(Request $request, string $token, int $script): RedirectResponse
    {
        $client = $this->resolve($token);

        $model = Script::pendingClientReview($client->id)->findOrFail($script);

        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
        ]);

        $model->requestChangesByClient($this->commentAuthor($client), $data['note']);

        Notification::send(User::canSee('scripts')->get(), new ScriptChangesRequested($model));

        return redirect()->route('client.scripts.public', $token)->with('status', 'Sent back for changes.');
    }

    /**
     * There is no session on a public link to credit a note to, unlike
     * Client\ScriptApprovalController -- a comment needs a real user_id
     * (script_comments.user_id is not nullable), so this is attributed to
     * the client's own login instead. ClientScriptApprovalLinkController
     * creates one the moment a link is issued for exactly this reason, so it
     * is never missing by the time a client reaches this form.
     */
    private function commentAuthor(Client $client): User
    {
        return $client->login()->firstOrFail();
    }

    /**
     * The token, and nothing else, decides which client's queue this is.
     *
     * A 404 rather than a 403 for an unknown token: there is nothing to
     * authenticate as, so "no" and "wrong" are the same answer, and saying
     * which would confirm that a guessed token nearly worked.
     */
    private function resolve(string $token): Client
    {
        $client = Client::where('script_approval_token', $token)->first();

        abort_if($client === null, 404);

        return $client;
    }
}
