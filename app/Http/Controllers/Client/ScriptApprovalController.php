<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\ResolvesClient;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Script;
use App\Models\User;
use App\Notifications\ScriptApproved;
use App\Notifications\ScriptChangesRequested;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\View\View;

/**
 * One script at a time, the way the studio asked for this: a client with
 * several scripts sitting in Client Review sees only the one sent to them
 * first, decides on it, and the next one takes its place on the next visit.
 * A list of everything pending would let a client skip past an awkward one
 * and leave it unanswered indefinitely; a queue does not.
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

        $pendingIds = $this->queue($client)->pluck('id');

        $script = $pendingIds->isEmpty()
            ? null
            : Script::with(['sections', 'comments', 'writer'])->find($pendingIds->first());

        return view('client.scripts', [
            'client' => $client,
            'script' => $script,
            // Everyone behind the one on screen -- shown so a client with
            // several scripts waiting knows there is more coming rather
            // than assuming this is the only one.
            'waitingCount' => max(0, $pendingIds->count() - 1),
        ]);
    }

    public function approve(Request $request, int $script): RedirectResponse
    {
        $client = $this->client($request);

        $model = $this->queue($client)->findOrFail($script);

        $model->approveByClient();

        Notification::send(User::canSee('scripts')->get(), new ScriptApproved($model));

        return redirect()->route('client.scripts')->with('status', 'Approved — thank you.');
    }

    public function requestChanges(Request $request, int $script): RedirectResponse
    {
        $client = $this->client($request);

        $model = $this->queue($client)->findOrFail($script);

        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
        ]);

        $model->requestChangesByClient($request->user(), $data['note']);

        Notification::send(User::canSee('scripts')->get(), new ScriptChangesRequested($model));

        return redirect()->route('client.scripts')->with('status', 'Sent back for changes.');
    }

    /**
     * This client's approval queue, oldest-sent first -- the order a
     * one-at-a-time screen has to agree with itself on every request, or a
     * client approving one script could find a different one waiting than
     * the index page just showed them.
     *
     * @return Builder<Script>
     */
    private function queue(Client $client): Builder
    {
        return Script::query()
            ->where('client_id', $client->id)
            ->where('status', Script::STATUS_CLIENT_REVIEW)
            ->orderBy('sent_to_client_at');
    }
}
