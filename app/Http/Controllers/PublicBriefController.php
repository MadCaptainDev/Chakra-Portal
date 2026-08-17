<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SavesClientBrief;
use App\Http\Requests\ClientBriefRequest;
use App\Models\ClientBrief;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The brand brief, filled by a client who has no login.
 *
 * Most clients never sign in. Making somebody accept an account and choose a
 * password before they can answer questions about their own business is how a
 * brief comes back as a voice note instead -- so this is the same form behind
 * a long random URL.
 *
 * The token IS the authorisation. There is no {client} in any of these paths
 * and no id to tamper with: the token is looked up, and whichever brief it
 * belongs to is the one being filled. Wrong token, no page.
 *
 * Filled once. The link stops accepting answers the moment it is submitted,
 * because a URL that can be forwarded is a URL that can be reopened by anybody
 * who has it -- including months later, over the top of answers the studio has
 * already written scripts from. Staff can reopen it deliberately.
 */
class PublicBriefController extends Controller
{
    use SavesClientBrief;

    public function show(string $token): View
    {
        $brief = $this->resolve($token);

        // Submitted means closed. Shown as its own page rather than a locked
        // form: a client returning to a link they already used wants to know
        // it worked, not to look at fields they cannot change.
        if ($brief->isSubmitted()) {
            return view('brief.closed', [
                'client' => $brief->client,
                'brief' => $brief,
            ]);
        }

        return view('brief.public', [
            'client' => $brief->client,
            'brief' => $brief,
            'answers' => $brief->exists ? $brief->keyedAnswers() : collect(),
            'options' => $this->taxonomyOptions($brief),
            'token' => $token,
        ]);
    }

    public function update(ClientBriefRequest $request, string $token): RedirectResponse
    {
        $brief = $this->resolve($token, forWriting: true);

        $this->saveBrief($brief->client, $request);

        return redirect()
            ->route('brief.public', $token)
            ->with('status', 'Saved. This link stays open until you send it in, so come back whenever suits you.');
    }

    public function submit(ClientBriefRequest $request, string $token): RedirectResponse
    {
        $brief = $this->resolve($token, forWriting: true);

        $saved = $this->saveBrief($brief->client, $request);

        /*
         * The name is asked for at the moment of submitting rather than at the
         * top of the form. There is no account behind a public link, so
         * without it the studio has a brief and no idea which person in the
         * business wrote it -- and a name field on question zero is the one
         * most likely to make somebody close the tab.
         */
        $saved->forceFill([
            'status' => ClientBrief::STATUS_SUBMITTED,
            'submitted_at' => $saved->submitted_at ?? now(),
            'public_submitted_at' => now(),
            'public_submitted_name' => $request->string('submitted_name')->trim()->value() ?: null,
        ])->save();

        return redirect()->route('brief.public', $token);
    }

    /**
     * The token, and nothing else, decides which brief this is.
     *
     * A 404 rather than a 403 for an unknown token: there is nothing to
     * authenticate as, so "no" and "wrong" are the same answer, and saying
     * which would confirm that a guessed token nearly worked.
     */
    private function resolve(string $token, bool $forWriting = false): ClientBrief
    {
        $brief = ClientBrief::with(['client', 'answers'])
            ->where('public_token', $token)
            ->first();

        abort_if($brief === null, 404);

        // A write against a closed link is refused rather than redirected, so
        // a stale tab left open overnight cannot overwrite a submitted brief.
        abort_if($forWriting && ! $brief->acceptsPublicEdits(), 403);

        return $brief;
    }
}
