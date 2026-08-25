<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the personal /my/* area: timesheet, to-dos, calendar, dashboard.
 *
 * Employees always qualify. An admin qualifies only when linked to a Salaries
 * row (see User::logsWork). Pure admins and clients are refused here -- they
 * have their own homes and must not use these personal boards by accident.
 */
class EnsureUserLogsWork
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->logsWork(), 403);

        return $next($request);
    }
}
