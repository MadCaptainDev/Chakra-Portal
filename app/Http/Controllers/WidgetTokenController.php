<?php

namespace App\Http\Controllers;

use App\Models\WidgetToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Making and revoking the keys the phone widget reads with. Same flow as
 * McpTokenController: the plaintext is flashed once and never kept.
 */
class WidgetTokenController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        abort_if($request->user()->isClient(), 403);

        $validated = $request->validateWithBag('widgetToken', [
            'name' => ['required', 'string', 'max:60'],
        ]);

        $issued = WidgetToken::issue($request->user(), $validated['name']);

        return redirect(route('profile.edit').'#phone-widget')
            ->with('status', 'Widget script ready. Copy it now — the key inside will not be shown again.')
            ->with('widget_token_plain', $issued['plain']);
    }

    public function destroy(Request $request, WidgetToken $token): RedirectResponse
    {
        abort_unless($token->user_id === $request->user()->id, 404);

        $name = $token->name;
        $token->delete();

        return redirect(route('profile.edit').'#phone-widget')
            ->with('status', '"'.$name.'" was revoked. That widget will stop updating.');
    }
}
