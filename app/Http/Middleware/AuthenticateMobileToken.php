<?php

namespace App\Http\Middleware;

use App\Models\PlannerSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The front door to the Chakra mobile API (routes/mobile-api.php): a bearer
 * token from POST /api/mobile/login. It is a PlannerSession token -- one
 * signed-in-device list for both apps (/planner/setup), and the
 * same token also works on /api/planner/* for the planner screens.
 *
 * Every teammate gets in; clients do not. What each person may SEE is
 * decided further in, by the same permission checks as the portal.
 */
class AuthenticateMobileToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $session = PlannerSession::resolve($request->bearerToken());

        if (! $session || ! $session->user) {
            return response()->json(['message' => 'Sign in again.', 'code' => 'unauthenticated'], 401);
        }

        if ($session->user->isClient()) {
            return response()->json(['message' => 'The Chakra app is for the studio team.', 'code' => 'no_access'], 403);
        }

        $session->touchLastUsed($request->header('X-App-Version'));

        $user = $session->user;
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('planner_session', $session);

        return $next($request);
    }
}
