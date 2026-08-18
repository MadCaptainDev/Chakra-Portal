<?php

namespace App\Services\Instagram;

use App\Models\SocialAccount;
use App\Models\SocialInsight;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The one place that actually performs an Instagram sync and updates the
 * account's own bookkeeping (last_synced_at, failure state, linked
 * portfolio items) -- shared by the manual "Sync now" button
 * (InstagramInsightsController::sync()) and the automatic triggers below,
 * so there is exactly one implementation of "what a sync means" rather than
 * two that could drift apart.
 */
class InstagramSyncRunner
{
    /** Instagram's own retention ceiling for account insights -- the same constant InstagramInsightsController::customRange() already clamps to. */
    private const BACKFILL_DAYS = 90;

    /**
     * What "Sync now" does: run the sync, update the account, return a
     * status string for the flash message. Never throws -- this is also
     * called from a GET request path (ensureFresh(), below) and a page load
     * must not 500 because Instagram's API had a bad moment.
     *
     * $mediaSince is forwarded to syncMedia() as-is: null on every manual
     * "Sync now" click (fast, unpaginated latest-25, exactly today's
     * behavior), a real floor only from ensureFresh()'s first-ever-sync
     * backfill.
     */
    public static function run(SocialAccount $account, Carbon $since, Carbon $until, ?Carbon $mediaSince = null): string
    {
        try {
            $result = InstagramInsights::make()->syncAll($account, $since, $until, $mediaSince);

            $account->forceFill(['last_synced_at' => now()])->save();
            $account->clearFailure();
            $account->refreshLinkedPortfolioItems();

            $skipped = array_unique([
                ...$result['account']['skipped'],
                ...$result['media']['skipped'],
                ...$result['audience']['skipped'],
            ]);

            $status = sprintf('Synced. %d item(s) checked.', $result['media']['items']);

            if ($skipped !== []) {
                $status .= ' Not available for this account: '.implode(', ', $skipped).'.';
            }

            return $status;
        } catch (InstagramException $e) {
            $account->recordFailure($e->userMessage(), fatal: $e->isAuthFailure());

            return 'Could not sync: '.$e->userMessage();
        }
    }

    /**
     * Called at the top of show() on the Insights screen and the Monthly
     * Report, before any data is read. Two cases, in order:
     *
     * 1. Never synced at all: backfill AT LEAST 90 days -- Instagram's own
     *    retention ceiling -- regardless of whatever range is currently
     *    being viewed, so every later navigation across ranges just works
     *    from cache. This is the "first time opening a client's Instagram
     *    in the portal" promise: it fires on the first page view, not
     *    inside the OAuth callback, which stays instant as it is today.
     * 2. Already synced at least once, but the specific window being viewed
     *    has no cached account-level data at all: sync exactly that window
     *    -- what clicking "Sync now" would do, done for you the moment you
     *    land on dates nothing has ever fetched. Gated by $checkWindow, not
     *    unconditional: a normal sync cadence already keeps the Insights
     *    screen's PRESET ranges (last 7/30 days, this/previous month)
     *    covered, so checking cache presence on every ordinary preset-range
     *    view would mean a real API round trip on page loads that have no
     *    real reason to need one -- callers pass true only where every view
     *    is inherently a specific, possibly-never-fetched window (the
     *    Insights screen's own custom date picker; every Monthly Report
     *    month, which has no "preset" concept to lean on).
     *
     * Both respect the existing per-account throttle (canSyncNow()) -- if
     * throttled, this silently does nothing and the page renders whatever
     * is already cached, exactly like today. A short Cache::add() lock
     * (keyed per account) stops two concurrent page loads (two tabs, a
     * refresh mid-sync) from double-triggering the same work -- the same
     * pattern EnsureRecurringInvoicesGenerated already uses on this host,
     * which has no queue worker and no cron to hand slow work off to.
     */
    public static function ensureFresh(SocialAccount $account, Carbon $since, Carbon $until, bool $checkWindow): void
    {
        if (! $account->canSyncNow()) {
            return;
        }

        $lock = 'instagram-auto-sync-'.$account->id;

        if (! Cache::add($lock, true, now()->addSeconds(120))) {
            return;
        }

        try {
            if ($account->last_synced_at === null) {
                $floor = now()->subDays(self::BACKFILL_DAYS)->startOfDay();
                $effectiveSince = $since->gt($floor) ? $floor : $since;
                self::run($account, $effectiveSince, now()->endOfDay(), mediaSince: $effectiveSince);

                return;
            }

            if (! $checkWindow) {
                return;
            }

            $hasData = SocialInsight::query()
                ->where('social_account_id', $account->id)
                ->accountLevel()
                ->between($since, $until)
                ->exists();

            if (! $hasData) {
                self::run($account, $since, $until);
            }
        } finally {
            Cache::forget($lock);
        }
    }
}
