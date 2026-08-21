<?php

namespace App\Services\Notion;

use App\Models\ContentItem;
use App\Models\NotionSetting;
use App\Models\NotionShoot;
use App\Services\Instagram\InstagramContentMatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * When the Notion cache gets refreshed, and by whom.
 *
 * Notion cannot be read live on a page load: a full pass is ~20 paginated
 * calls across five databases and measures about 11 seconds against the
 * real workspace, and Notion rate-limits an integration at roughly three
 * requests a second, so doing it per request would be both slow and
 * self-defeating with more than one person looking.
 *
 * So the dashboard reads the local cache, and this keeps that cache honest
 * two ways: a person can press Refresh, and a view of stale data triggers
 * one refresh in the background of that request. Same inline-work-under-a-
 * lock pattern as InstagramSyncRunner, for the same reason -- this host has
 * no queue worker and no confirmed cron.
 */
class NotionSyncRunner
{
    /**
     * How old the cache may get before a page view refreshes it.
     *
     * 15 minutes, not 1: the refresh runs INSIDE somebody's request, so the
     * person who happens to trigger it waits ~11 seconds for their page. At
     * this interval that is at worst four unlucky page loads an hour, which
     * is a fair price for numbers that are never meaningfully behind. A
     * queue worker or a real cron would let this go much lower.
     */
    private const STALE_MINUTES = 15;

    private const LOCK_KEY = 'notion-auto-sync';

    /**
     * Run a sync now and return a status line for a flash message.
     *
     * Never throws: ContentSyncService already logs and swallows per-source
     * failures, and this is also called from a GET path where a page must
     * not 500 because Notion had a bad moment.
     */
    public static function run(bool $fresh = false): string
    {
        if (! NotionSetting::current()->api_key) {
            return 'No Notion API key saved. Add one under Setup → Notion.';
        }

        $counts = app(ContentSyncService::class)->syncAll(fresh: $fresh);

        /*
         * Straight after the sync, not on a schedule of its own: both of
         * these read what the sync just wrote, and leaving them to run
         * separately is how a dashboard ends up showing this month's
         * content against last week's Instagram matches.
         */
        app(NotionShootImporter::class)->autoMapClients();
        $matched = app(InstagramContentMatcher::class)->matchAll();

        $status = 'Refreshed from Notion. '.collect($counts)
            ->map(fn (int $count, string $source) => "{$source}: {$count}")
            ->implode(', ').'.';

        if ($matched['matched'] > 0) {
            $status .= " Matched {$matched['matched']} to Instagram posts.";
        }

        return $status;
    }

    /**
     * Refresh if the cache is older than STALE_MINUTES, at most once at a
     * time.
     *
     * The Cache::add() lock is what stops two people opening the dashboard
     * in the same minute from both running an 11-second sync -- the second
     * request finds the lock taken and simply renders whatever is cached,
     * which is at most 15 minutes old and about to be replaced anyway.
     */
    public static function ensureFresh(): void
    {
        if (! NotionSetting::current()->api_key) {
            return;
        }

        $syncedAt = self::lastSyncedAt();

        if ($syncedAt !== null && $syncedAt->gt(now()->subMinutes(self::STALE_MINUTES))) {
            return;
        }

        // TTL comfortably longer than a sync takes, so a request that dies
        // mid-sync cannot leave the lock held until the cache is cleared.
        if (! Cache::add(self::LOCK_KEY, true, now()->addMinutes(5))) {
            return;
        }

        try {
            self::run();
        } finally {
            Cache::forget(self::LOCK_KEY);
        }
    }

    /** The most recent sync across both synced tables. */
    public static function lastSyncedAt(): ?Carbon
    {
        $values = array_filter([ContentItem::max('synced_at'), NotionShoot::max('synced_at')]);

        return $values === [] ? null : Carbon::parse(max($values));
    }

    public static function isStale(): bool
    {
        $syncedAt = self::lastSyncedAt();

        return $syncedAt === null || $syncedAt->lte(now()->subMinutes(self::STALE_MINUTES));
    }
}
