<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * After AuthenticateMcpToken: the token must belong to an admin.
 *
 * Guards the Routines WhatsApp API, which sends from the studio's own number
 * -- a staff member's token is enough to read their own timesheet over MCP,
 * not to message anyone in the studio's name.
 */
class EnsureTokenOwnerIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json([
                'success' => false,
                'error' => 'This API needs a token that belongs to an admin.',
            ], 403);
        }

        return $next($request);
    }
}
