<?php

namespace App\Http\Controllers;

use App\Models\PushToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Registering and revoking one browser's push notification token.
 *
 * store() and revoke() are called from JavaScript (resources/js/push.js)
 * with the raw FCM token as the identifier -- that is all the browser has.
 * destroy() is the server-rendered "Stop" button on the profile screen and
 * is route-model-bound by id, matching McpTokenController's shape.
 *
 * Clients never reach any of this: the opt-in card is not rendered for
 * them (see profile/partials/push-notifications.blade.php), and there is
 * nothing here checking the role explicitly because there is no route for
 * a client to have found in the first place -- these three routes sit in
 * the same 'auth' profile group every staff member's profile page uses.
 */
class PushTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        // Belt-and-braces on the staff-only decision: nothing in the UI
        // offers a client this button, but a direct POST should not
        // silently create a token that routeNotificationForFcm() would
        // just return empty for anyway -- refuse it outright instead.
        abort_if($request->user()->isClient(), 403);

        $validated = $request->validate(['token' => ['required', 'string', 'max:4096']]);

        PushToken::register($request->user(), $validated['token'], $request->userAgent());

        return response()->json(['status' => 'registered']);
    }

    /**
     * Called at logout time with the token this browser has cached in
     * localStorage -- see app.js's revoke-before-submit hook. Silently
     * no-ops on a token that does not exist or belongs to someone else,
     * rather than erroring: by the time this fires the session may already
     * be a beat from ending, and the whole point is that logout must never
     * be blockable by this call failing.
     */
    public function revoke(Request $request): JsonResponse
    {
        $validated = $request->validate(['token' => ['required', 'string', 'max:4096']]);

        PushToken::where('user_id', $request->user()->id)
            ->where('token_hash', hash('sha256', $validated['token']))
            ->delete();

        return response()->json(['status' => 'revoked']);
    }

    public function destroy(Request $request, PushToken $pushToken): RedirectResponse
    {
        // 404 rather than 403: somebody else's device is not theirs to know about.
        abort_unless($pushToken->user_id === $request->user()->id, 404);

        $label = $pushToken->device_label ?: 'That device';
        $pushToken->delete();

        return back()->with('status', "{$label} will no longer receive notifications.");
    }
}
