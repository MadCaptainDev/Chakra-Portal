<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every Chakra Planner API answer is JSON, errors included -- a validation
 * failure or a 404 must never come back as an HTML page or a redirect to
 * the login screen, whatever Accept header the app happened to send.
 */
class PlannerJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
