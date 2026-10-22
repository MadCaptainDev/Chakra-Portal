<?php

namespace App\Http\Middleware;

use App\Models\PlannerSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The front door to the Chakra Planner API: a bearer token from
 * POST /api/planner/login, belonging to a staff member who still has the
 * Chakra Planner permission. Checked on every call, so taking the
 * permission away (Users → Permissions) locks the app out at once, without
 * hunting down devices.
 */
class AuthenticatePlannerToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $session = PlannerSession::resolve($request->bearerToken());

        if (! $session || ! $session->user) {
            return response()->json(['message' => 'Sign in again.', 'code' => 'unauthenticated'], 401);
        }

        $user = $session->user;
        if ($user->isClient()) {
            return response()->json(['message' => 'Chakra Planner is for the studio team.', 'code' => 'no_access'], 403);
        }
        // Every teammate signs in for the editing widget (api/planner/editing,
        // me, logout); the planners themselves need Chakra Planner → View.
        $widgetOnly = $request->routeIs('api.planner.editing.*', 'api.planner.me', 'api.planner.logout', 'api.planner.editor-updates');
        if (! $widgetOnly && ! $user->can('planner.view')) {
            return response()->json([
                'message' => 'Your account can use the editing widget but not the planners. Ask an admin to tick Chakra Planner under Users → Permissions.',
                'code' => 'no_access',
            ], 403);
        }

        $session->touchLastUsed($request->header('X-Planner-Version'));

        $request->setUserResolver(fn () => $user);
        $request->attributes->set('planner_session', $session);

        return $next($request);
    }
}
