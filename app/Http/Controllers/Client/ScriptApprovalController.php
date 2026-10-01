<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\ResolvesClient;
use App\Http\Controllers\Controller;
use App\Models\Script;
use App\Models\User;
use App\Notifications\ScriptApproved;
use App\Notifications\ScriptChangesRequested;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\View\View;

/**
 * Every script titled waiting on this client, and each one's own page to
 * read it, comment, and decide -- approve it or send it back with a note.
 * "Reject" is deliberately never the word used anywhere on this screen:
 * requestChanges() asks for a note and reopens the script for a rewrite,
 * which is the outcome the studio wants a client reaching for, not a dead
 * end with no next step.
 *
 * {script} is a plain int, not a route model binding -- every query below
 * is scoped to this client's own id, so another client's script id 404s
 * before anything is loaded, same reasoning as Client\InvoiceController.
 */
class ScriptApprovalController extends Controller
{
    use ResolvesClient;

    public function index(Request $request): View
    {
        $client = $this->client($request);

        $scripts = Script::pendingClientReview($client->id)
            ->with(['scriptTypeTerm', 'platformTerm'])
            ->get();

        return view('client.scripts', [
            'client' => $client,
            'scripts' => $scripts,
        ]);
    }

    public function show(Request $request, int $script): View
    {
        $client = $this->client($request);

        $model = Script::pendingClientReview($client->id)
            ->with(['sections', 'comments', 'writer'])
            ->findOrFail($script);

        return view('client.scripts-show', [
            'client' => $client,
            'script' => $model,
        ]);
    }

    /** A note that is not a decision -- the script stays in the queue. */
    public function comment(Request $request, int $script): RedirectResponse
    {
        $client = $this->client($request);

        $model = Script::pendingClientReview($client->id)->findOrFail($script);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $model->comments()->create([
            'user_id' => $request->user()->id,
            'body' => $data['body'],
        ]);

        return redirect(route('client.scripts.show', $model).'#comments');
    }

    public function approve(Request $request, int $script): RedirectResponse
    {
        $client = $this->client($request);

        $model = Script::pendingClientReview($client->id)->findOrFail($script);

        $model->approveByClient();

        Notification::send(User::canSee('scripts')->get(), new ScriptApproved($model));

        return redirect()->route('client.scripts')->with('status', 'Approved — thank you.');
    }

    public function requestChanges(Request $request, int $script): RedirectResponse
    {
        $client = $this->client($request);

        $model = Script::pendingClientReview($client->id)->findOrFail($script);

        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
        ]);

        $model->requestChangesByClient($request->user(), $data['note']);

        Notification::send(User::canSee('scripts')->get(), new ScriptChangesRequested($model));

        return redirect()->route('client.scripts')->with('status', 'Sent back for changes.');
    }
}
