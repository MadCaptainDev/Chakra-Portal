<?php

namespace App\Http\Controllers;

use App\Models\McpToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Making and revoking the keys an AI client connects with.
 *
 * Everybody may make their own. A token can never reach further than the person
 * who made it, so handing one out is exactly as risky as that person's own
 * account -- which is a decision they are already trusted with every time they
 * log in somewhere.
 */
class McpTokenController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
        ]);

        $issued = McpToken::issue($request->user(), $validated['name']);

        /*
         * Flashed, not stored. This is the only moment the plaintext exists
         * outside the client's config file, and it lives in the session for one
         * page render before it is gone for good.
         */
        return back()
            ->with('status', 'Token created. Copy it now — it will not be shown again.')
            ->with('mcp_token_plain', $issued['plain']);
    }

    public function destroy(Request $request, McpToken $token): RedirectResponse
    {
        // 404 rather than 403: somebody else's token is not theirs to know about.
        abort_unless($token->user_id === $request->user()->id, 404);

        $name = $token->name;
        $token->delete();

        return back()->with('status', '"'.$name.'" was revoked. Anything using it is now locked out.');
    }
}
