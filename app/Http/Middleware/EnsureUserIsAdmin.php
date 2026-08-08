<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards every admin area of the portal.
 *
 * Employees have logins purely so they can fill in their own timesheet; they
 * must not reach invoices, clients, salaries or settings. Applied to the whole
 * authenticated admin route group rather than per-controller, so a new admin
 * route is protected by default instead of by remembering.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isAdmin(), 403);

        return $next($request);
    }
}
