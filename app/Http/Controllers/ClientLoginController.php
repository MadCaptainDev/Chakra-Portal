<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Issuing, resetting and revoking a client's login.
 *
 * The password is set here by an admin and handed over by phone, because
 * MAIL_MAILER is `log` -- the app sends no real email -- and only two of the
 * thirteen clients have an address on file. An invite link would land in a log
 * file and the client would never see it.
 *
 * That means there is no self-serve reset, which is a real limitation and is
 * said out loud on the screen rather than discovered by a client at 9pm.
 */
class ClientLoginController extends Controller
{
    public function store(Request $request, Client $client): RedirectResponse
    {
        abort_if($client->login()->exists(), 409, 'This client already has a login.');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // Their own address if they have one, but any address will do --
            // it is a username here, not somewhere anything gets sent.
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::defaults()],
        ]);

        User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => User::ROLE_CLIENT,
            'client_id' => $client->id,
        ]);

        return back()->with('status', 'Login created for '.$client->name.'. Send them the password directly — it is not shown again.');
    }

    public function updatePassword(Request $request, Client $client): RedirectResponse
    {
        $login = $client->login()->firstOrFail();

        $validated = $request->validate([
            'password' => ['required', Password::defaults()],
        ]);

        $login->update(['password' => $validated['password']]);

        return back()->with('status', 'New password set. Send it to them directly.');
    }

    /**
     * Revoke it entirely.
     *
     * The account goes rather than being disabled. There is no "suspended"
     * state anywhere else in this app, and inventing one here would mean every
     * future query about users had to remember it existed.
     */
    public function destroy(Request $request, Client $client): RedirectResponse
    {
        $login = $client->login()->firstOrFail();

        // Guarded even though the form only appears for client logins: this is
        // the one endpoint that deletes an account by someone else's id.
        abort_unless($login->isClient(), 403);

        $login->delete();

        return back()->with('status', 'Login revoked. '.$client->name.' can no longer sign in.');
    }
}
