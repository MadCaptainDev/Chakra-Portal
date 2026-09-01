<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\InstagramSetting;
use App\Models\SocialAccount;
use App\Services\Instagram\InstagramConnector;
use App\Services\Instagram\InstagramException;
use App\Services\Instagram\InstagramOAuth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Connecting a client's Instagram account, on their behalf -- a staff member
 * doing it for a client who hasn't got (or doesn't want) a portal login. A
 * client with their own login connects it themselves instead, through the
 * near-identical App\Http\Controllers\Client\InstagramConnectionController;
 * both share this class's callback() below, see THE SECURITY DESIGN.
 *
 * THE SECURITY DESIGN, because it is the whole point of this class:
 *
 * Meta allows exactly one redirect URI per app, matched character for
 * character, so the callback cannot carry a {client} segment. The obvious
 * workaround -- putting the client id in the `state` or a query parameter --
 * puts it in the browser, where it can be changed, and a changed value would
 * attach one client's Instagram account to another client's record.
 *
 * So the client id is written into the SESSION when the button is pressed and
 * read from the session on the way back. The browser carries only an opaque
 * `state` whose sole job is proving the callback belongs to the request that
 * started it. Nothing the browser sends decides whose account this becomes --
 * true of both controllers that can write that session key: the staff one
 * takes the client id from a route segment only clients,manage can reach, the
 * client one takes it from the signed-in client's own row, never from
 * anything the browser sends either.
 *
 * Connect and disconnect here sit behind clients,manage -- the same bar as
 * issuing a client login, and for the same reason: both hand out access to
 * something that is not ours.
 */
class InstagramConnectionController extends Controller
{
    /**
     * Send the client off to Instagram to authorise.
     *
     * POST rather than GET: it mutates session state and starts an external
     * flow, so it should be CSRF-protected and should not be a URL somebody
     * can bookmark or be linked into.
     */
    public function connect(Request $request, Client $client): RedirectResponse
    {
        $settings = InstagramSetting::current();

        if (! $settings->isConfigured()) {
            return back()->with('status',
                'Instagram is not set up yet. Add the app ID and secret under Setup → Instagram first.');
        }

        $state = InstagramOAuth::freshState();

        /*
         * The client id goes here and nowhere else. Regenerating nothing and
         * storing the pair together means the callback cannot be pointed at a
         * different client by editing a URL.
         */
        $request->session()->put(InstagramOAuth::SESSION_KEY, [
            'state' => $state,
            'client_id' => $client->id,
        ]);

        try {
            return redirect()->away(InstagramOAuth::make()->authorizationUrl($state));
        } catch (InstagramException $e) {
            return back()->with('status', $e->userMessage());
        }
    }

    /**
     * Instagram sends the client back here.
     *
     * Behind `auth` -- the person who started this (staff or, now, a client
     * connecting their own account) is still signed in, and an
     * unauthenticated hit on this route has no pending attempt to match
     * anyway. One route, because Meta allows exactly one redirect URI --
     * where it sends the browser back to depends on who is signed in, not on
     * which controller happened to start the flow.
     */
    public function callback(Request $request): RedirectResponse
    {
        $pending = $request->session()->pull(InstagramOAuth::SESSION_KEY);

        // Consumed on use, whatever happens next: a state that survives its
        // callback is a state that can be replayed.
        $client = $this->resolvePendingClient($pending, $request);

        $isClientUser = $request->user()->isClient();

        if (! $client) {
            return redirect()->route($isClientUser ? 'client.social' : 'clients.index')->with('status',
                'That Instagram link expired or did not match. Press Connect again.');
        }

        $back = $isClientUser
            ? redirect()->route('client.social')
            : redirect()->route('clients.show', $client);

        // The client said no on Instagram's screen, or closed it.
        if ($request->filled('error')) {
            Log::info('Instagram authorisation declined.', [
                'client_id' => $client->id,
                'error' => $request->string('error')->toString(),
            ]);

            return $back->with('status', $request->string('error')->toString() === 'access_denied'
                ? 'The connection was cancelled on Instagram. Nothing has changed.'
                : 'Instagram refused the connection: '.$request->string('error_description')->toString());
        }

        $code = $request->string('code')->toString();

        if ($code === '') {
            return $back->with('status', 'Instagram sent us back without an authorisation code. Try again.');
        }

        try {
            $account = InstagramConnector::make()->connect($client, $code, $request->user());
        } catch (InstagramException $e) {
            return $back->with('status', 'Could not connect Instagram: '.$e->userMessage());
        } catch (Throwable $e) {
            // Deliberately not $e->getMessage() to the screen: anything
            // unexpected here may be carrying a request body, and that body
            // contains the token.
            Log::error('Instagram connection failed unexpectedly.', [
                'client_id' => $client->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $back->with('status', 'Could not connect Instagram — the error has been logged.');
        }

        return $back->with('status', 'Instagram connected: '.$account->handle().'.');
    }

    public function destroy(Client $client): RedirectResponse
    {
        $account = $this->instagramFor($client);

        if (! $account) {
            return back()->with('status', 'There is no Instagram account connected for this client.');
        }

        InstagramConnector::make()->disconnect($account);

        return back()->with('status',
            'Instagram disconnected. The stored token has been discarded; anything already collected is kept.');
    }

    /**
     * The client this callback belongs to, or null if it cannot be trusted.
     *
     * hash_equals because the state is a secret being compared, and a plain
     * === leaks its contents one character at a time to anybody patient
     * enough to time the responses.
     */
    private function resolvePendingClient(mixed $pending, Request $request): ?Client
    {
        if (! is_array($pending) || ! isset($pending['state'], $pending['client_id'])) {
            return null;
        }

        $returned = $request->string('state')->toString();

        if ($returned === '' || ! hash_equals((string) $pending['state'], $returned)) {
            Log::warning('Instagram callback state mismatch.', ['ip' => $request->ip()]);

            return null;
        }

        return Client::find($pending['client_id']);
    }

    private function instagramFor(Client $client): ?SocialAccount
    {
        return $client->socialAccounts()
            ->forPlatform(SocialAccount::PLATFORM_INSTAGRAM)
            ->first();
    }
}
