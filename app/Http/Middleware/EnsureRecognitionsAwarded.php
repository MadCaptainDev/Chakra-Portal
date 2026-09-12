<?php

namespace App\Http\Middleware;

use App\Services\RecognitionAwarder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Same belt-and-braces catch-up as EnsureRoutinesGenerated, for the same
 * reason: there is no scheduler that can be relied on here. The first
 * authenticated request of the day claims the run; Cache::add() is atomic,
 * so concurrent requests produce one run, not one each.
 *
 * The awarder itself walks a fortnight backwards on every run, so a stretch
 * of days where nobody signed in fills itself in rather than being lost --
 * and its unique index means the repeated days cost an index lookup, not a
 * duplicate award.
 */
class EnsureRecognitionsAwarded
{
    public function handle(Request $request, Closure $next): Response
    {
        $claimed = Cache::add('recognitions-awarded-on-'.today()->toDateString(), true, now()->addDay());

        if ($claimed) {
            dispatch(function (): void {
                app(RecognitionAwarder::class)->run();
            })->afterResponse();
        }

        return $next($request);
    }
}
