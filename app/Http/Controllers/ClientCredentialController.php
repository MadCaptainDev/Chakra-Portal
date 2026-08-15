<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientCredential;
use App\Models\ClientCredentialView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The logins the studio holds for a client.
 *
 * Every action here is behind module:clients,credentials -- a separate ability
 * from viewing the client, because keeping client records tidy and reading
 * their Instagram password are different jobs.
 *
 * The password is never rendered into the page. It comes back from reveal(),
 * over one request, which writes down who asked. Putting it in the HTML would
 * put it in the browser cache, in the back button, in any screenshot of the
 * page and in the source of a page somebody left open on a shared machine.
 */
class ClientCredentialController extends Controller
{
    public function store(Request $request, Client $client): RedirectResponse
    {
        $credential = new ClientCredential($this->validated($request, $client));
        $credential->client_id = $client->id;
        $credential->forceFill([
            'created_by_id' => $request->user()->id,
            'updated_by_id' => $request->user()->id,
        ])->save();

        return back()->with('status', $credential->displayName().' saved for '.$client->name.'.');
    }

    public function update(Request $request, Client $client, ClientCredential $credential): RedirectResponse
    {
        $this->authoriseOwnership($client, $credential);

        $data = $this->validated($request, $client, $credential);

        /*
         * An empty password field means "leave it alone", not "erase it".
         * The form cannot show the current value, so it always arrives blank,
         * and treating blank as a deletion would wipe a credential every time
         * somebody corrected a typo in the label.
         */
        if (blank($data['secret'] ?? null)) {
            unset($data['secret']);
        }

        $credential->fill($data);
        $credential->forceFill(['updated_by_id' => $request->user()->id])->save();

        return back()->with('status', $credential->displayName().' updated.');
    }

    public function destroy(Request $request, Client $client, ClientCredential $credential): RedirectResponse
    {
        $this->authoriseOwnership($client, $credential);

        $name = $credential->displayName();
        $credential->delete();

        return back()->with('status', $name.' removed.');
    }

    /**
     * Hand over the password, and write down that it happened.
     *
     * The view is recorded before the value is returned, so a request that
     * fails on the way out has still been logged. Erring towards recording a
     * view that did not quite happen is the right way round.
     */
    public function reveal(Request $request, Client $client, ClientCredential $credential): JsonResponse
    {
        $this->authoriseOwnership($client, $credential);

        ClientCredentialView::create([
            'client_credential_id' => $credential->id,
            'user_id' => $request->user()->id,
            'ip_address' => $request->ip(),
            'viewed_at' => now(),
        ]);

        return response()->json([
            'username' => $credential->username,
            'secret' => $credential->secret,
            'notes' => $credential->notes,
        ]);
    }

    /**
     * A credential belongs to exactly one client, and the URL says which.
     *
     * 404 rather than 403: the id of a credential on another client's record
     * is not something to confirm the existence of.
     */
    private function authoriseOwnership(Client $client, ClientCredential $credential): void
    {
        abort_unless($credential->client_id === $client->id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Client $client, ?ClientCredential $credential = null): array
    {
        return $request->validate([
            'kind' => ['required', Rule::in(array_keys(ClientCredential::KINDS))],
            'label' => ['nullable', 'string', 'max:80'],
            'username' => ['nullable', 'string', 'max:255'],
            // Required on create, optional on edit -- see update() for why.
            'secret' => [$credential === null ? 'required' : 'nullable', 'string', 'max:500'],
            'url' => ['nullable', 'url', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
