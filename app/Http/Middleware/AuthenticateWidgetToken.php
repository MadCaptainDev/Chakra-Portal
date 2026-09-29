<?php

namespace App\Http\Middleware;

use App\Models\WidgetToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The only door a widget key opens: GET /api/widget/today.
 *
 * Bound onto the request, never into a session -- a key copied out of a phone
 * script cannot be turned into a browser login.
 */
class AuthenticateWidgetToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = WidgetToken::resolve($request->bearerToken());

        // A client account has nothing a staff widget shows; refuse it the
        // same as a bad key rather than hand back an empty summary.
        if (! $token || ! $token->user || $token->user->isClient()) {
            return response()->json(['error' => 'This widget key is not valid any more. Make a new one on your Profile page.'], 401);
        }

        $token->touchLastUsed();

        $request->setUserResolver(fn () => $token->user);

        return $next($request);
    }
}
