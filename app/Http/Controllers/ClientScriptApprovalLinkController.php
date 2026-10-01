<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

/**
 * The studio's side of a client's script approval link: hand one out, or
 * close it.
 *
 * Separate from Client\ScriptApprovalController, which is the client
 * deciding on a script. Same split as ClientBriefLinkController/
 * Client\BriefController, and for the same reason: these are staff actions
 * on somebody else's queue and carry a different permission.
 */
class ClientScriptApprovalLinkController extends Controller
{
    /**
     * Issue a link, or replace the one already out there.
     *
     * A script comment needs a real user_id (script_comments.user_id is not
     * nullable), and a public link has no session to read one from -- so
     * "request changes" on the link attributes the note to this client's own
     * login. Most clients using this feature already have one; for the rest,
     * one is created here, silently, the first time a link is issued --
     * there is nothing for them to sign into it with, since nobody ever
     * learns its password, but it gives every note a real author.
     */
    public function issue(Client $client): RedirectResponse
    {
        if (! $client->login()->exists()) {
            User::create([
                'name' => $client->name,
                'email' => $this->uniqueEmail($client->name),
                'password' => Str::random(40),
                'role' => User::ROLE_CLIENT,
                'client_id' => $client->id,
            ]);
        }

        $replacing = $client->script_approval_token !== null;

        $client->issueScriptApprovalToken();

        return back()->with('status', $replacing
            ? 'New link created. The previous one stops working immediately.'
            : 'Link created. Anyone with it can review and decide on this client\'s scripts, without logging in.');
    }

    public function revoke(Client $client): RedirectResponse
    {
        $client->revokeScriptApprovalToken();

        return back()->with('status', 'Link closed.');
    }

    /** chakragroups.in address derived from the name, same convention as every other client login -- numbered if it collides. */
    private function uniqueEmail(string $name): string
    {
        $slug = Str::of($name)->slug('')->limit(30, '') ?: 'client';
        $email = $slug.'@chakragroups.in';
        $n = 1;

        while (User::where('email', $email)->exists()) {
            $email = $slug.(++$n).'@chakragroups.in';
        }

        return $email;
    }
}
