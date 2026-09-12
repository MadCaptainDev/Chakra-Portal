<?php

namespace App\Http\Middleware;

use App\Services\WhatsappFlow\ScheduledFlowRunner;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * The same catch-up pattern as EnsureRoutinesGenerated, and for the same
 * reason: there is no scheduler on this host worth trusting a daily send to.
 *
 * Checked more than once a day, unlike its siblings -- a flow set for 8am has
 * to be able to fire at 8am, not on whatever the first request of the day
 * happened to be. Cache::add() on a per-15-minute key is what keeps that from
 * meaning "on every request": at most four checks an hour, each of which
 * usually finds nothing due and does nothing.
 *
 * ScheduledFlowRunner owns the once-per-day guarantee itself (last_run_on on
 * the flow row), so even if this fired on every request the briefing would
 * still go out once.
 */
class EnsureScheduledFlowsRun
{
    public function handle(Request $request, Closure $next): Response
    {
        $slot = now()->format('Y-m-d-H').'-'.intdiv((int) now()->format('i'), 15);

        if (Cache::add('scheduled-flows-checked-'.$slot, true, now()->addMinutes(20))) {
            dispatch(function (): void {
                app(ScheduledFlowRunner::class)->run();
            })->afterResponse();
        }

        return $next($request);
    }
}
