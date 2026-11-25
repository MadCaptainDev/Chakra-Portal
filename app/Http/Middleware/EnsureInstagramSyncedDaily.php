<?php

namespace App\Http\Middleware;

use App\Models\InstagramSyncJob;
use App\Models\SocialAccount;
use App\Services\Instagram\InstagramSyncQueue;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The daily Instagram sync, carried by page visits rather than the clock.
 *
 * This host's cron does not fire (`schedule:run` never runs), so the 2am
 * schedule entry is dead. And syncing every account in one go inside one
 * request does not work either: the host cuts a long request off, so the
 * old catch-up synced the first three accounts each morning and never
 * reached the rest (Mahavir and Squad sat at 21 October).
 *
 * So: from 5am, any visit to the portal syncs ONE account -- the one synced
 * longest ago that has not been synced today -- after the response has gone
 * to the browser, under a lease so two visits never sync at once. A dozen
 * visits and every account is done; then a flag stops even the lookup until
 * tomorrow. One failing account (an expired login) is handled inside
 * instagram:sync and simply waits for the next day.
 */
class EnsureInstagramSyncedDaily
{
    /** Not before this hour (studio time): the day's numbers are morning numbers. */
    public const FROM_HOUR = 5;

    private const LEASE_SECONDS = 240;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Never under the test runner: this calls Meta's API for real.
        if (app()->runningUnitTests() || now()->hour < self::FROM_HOUR || $request->isMethod('HEAD')) {
            return $response;
        }

        // A "Sync all Instagram" someone pressed goes first: it is only worked right
        // after the press, and a run cut short used to leave jobs queued for weeks.
        if (self::manualWaiting()) {
            if (Cache::add('instagram-sync-lease', 'manual', self::LEASE_SECONDS)) {
                defer(function () {
                    @set_time_limit(0);
                    @ignore_user_abort(true);
                    try {
                        app(InstagramSyncQueue::class)->work(50);
                    } catch (Throwable $e) {
                        report($e);
                    } finally {
                        Cache::forget('instagram-sync-lease');
                    }
                });
            }

            return $response;
        }

        $done = 'instagram-sync-done-'.today()->toDateString();
        if (Cache::has($done)) {
            return $response;
        }

        $account = self::next();
        if (! $account) {
            Cache::put($done, true, now()->endOfDay());

            return $response;
        }

        // One sync at a time across every visitor.
        if (! Cache::add('instagram-sync-lease', $account->id, self::LEASE_SECONDS)) {
            return $response;
        }

        defer(function () use ($account) {
            @set_time_limit(0);
            @ignore_user_abort(true);
            try {
                Artisan::call('instagram:sync', ['--force' => true, '--account' => $account->id]);
            } catch (Throwable $e) {
                report($e);
            } finally {
                // Synced or failed, it has had its turn today.
                if ($account->fresh()?->last_synced_at?->lt(today())) {
                    Cache::put('instagram-sync-tried-'.today()->toDateString().'-'.$account->id, true, now()->endOfDay());
                }
                Cache::forget('instagram-sync-lease');
            }
        });

        return $response;
    }

    /**
     * Manual syncs still waiting. Ones older than two days are closed off
     * instead -- the daily sync has covered them since.
     */
    public static function manualWaiting(): bool
    {
        return Cache::remember('instagram-manual-waiting', 60, function () {
            InstagramSyncJob::query()->where('status', InstagramSyncJob::QUEUED)->where('created_at', '<', now()->subDays(2))
                ->update(['status' => InstagramSyncJob::FAILED, 'message' => 'Expired: the daily sync covered it.', 'finished_at' => now()]);

            return InstagramSyncJob::query()->where('status', InstagramSyncJob::QUEUED)->exists();
        });
    }

    /** The connected account synced longest ago, not yet synced (or tried) today. */
    public static function next(): ?SocialAccount
    {
        $tried = fn (SocialAccount $a) => Cache::has('instagram-sync-tried-'.today()->toDateString().'-'.$a->id);

        return SocialAccount::query()
            ->forPlatform(SocialAccount::PLATFORM_INSTAGRAM)
            ->connected()
            ->where(fn ($q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<', today()))
            ->orderByRaw('last_synced_at is not null')->orderBy('last_synced_at')
            ->get()
            ->reject($tried)
            ->first();
    }
}
