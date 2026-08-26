<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Belt-and-braces catch-up for the Instagram sync on a machine that may be
 * off at the scheduled 2am run (routes/console.php) -- same pattern as
 * EnsureRecurringInvoicesGenerated and EnsureRoutinesGenerated, and for the
 * same reason: `crontab -l` on this host returns nothing, so
 * `php artisan schedule:run` never actually fires and that 2am schedule
 * entry is dead without this. The first authenticated admin request of the
 * day runs `instagram:sync --force` for every connected account;
 * Cache::add() is atomic, so this runs at most once per day regardless of
 * how many requests land concurrently.
 *
 * Unlike the other two catch-ups, this one calls out to Meta's API per
 * connected account rather than doing DB-only work, so the one request that
 * claims the lock is measurably slower than an ordinary page load -- each
 * account is still wrapped in its own try/catch inside the command
 * (SyncInstagramInsights), so one account with an expired token cannot
 * block the rest or fail the request.
 */
class EnsureInstagramSyncedDaily
{
    public function handle(Request $request, Closure $next): Response
    {
        // Skipped under the test runner on purpose: every other catch-up
        // middleware in this app only ever touches the database, which
        // RefreshDatabase already isolates per test. This one makes a real
        // outbound call to Meta per connected account, and no feature test
        // anywhere in the suite should silently depend on Meta's API being
        // reachable (or eat the latency of it failing) just because it
        // happened to be the first request to hit an admin route.
        if (app()->runningUnitTests()) {
            return $next($request);
        }

        $claimed = Cache::add('instagram-synced-on-'.today()->toDateString(), true, now()->addDay());

        if ($claimed) {
            Artisan::call('instagram:sync', ['--force' => true]);
        }

        return $next($request);
    }
}
