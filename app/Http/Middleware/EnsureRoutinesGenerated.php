<?php

namespace App\Http\Middleware;

use App\Services\RoutineOccurrenceGenerator;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Belt-and-braces catch-up for routine occurrences on a machine that may
 * be switched off when the scheduler would normally fire. The first
 * authenticated request of the day triggers generation; Cache::add()
 * is atomic, so this runs at most once per day regardless of how many
 * requests land concurrently — not once per request.
 */
class EnsureRoutinesGenerated
{
    public function handle(Request $request, Closure $next): Response
    {
        $claimed = Cache::add('routines-generated-on-'.today()->toDateString(), true, now()->addDay());

        if ($claimed) {
            app(RoutineOccurrenceGenerator::class)->run();
        }

        return $next($request);
    }
}
