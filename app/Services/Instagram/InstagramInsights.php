<?php

namespace App\Services\Instagram;

use App\Models\SocialAccount;
use App\Models\SocialAudienceSnapshot;
use App\Models\SocialInsight;
use App\Models\SocialMediaItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Fetching and caching Instagram analytics for one connected account.
 *
 * The metric lists below are NOT copied from Meta's marketing docs, which
 * disagree with each other and with themselves across pages. They were
 * confirmed by calling this exact endpoint against a real connected account
 * (thechakra_productions) on API v23.0 and reading Meta's own rejection
 * error, which -- usefully -- names every metric the endpoint currently
 * accepts. `impressions` was probed deliberately and confirmed retired.
 *
 * Two account-level shapes, because Instagram itself splits them:
 *
 *   TIME_SERIES metrics answer with one value per day over a range and are
 *   asked for with period=day, since=, until=.
 *
 *   TOTAL_VALUE metrics answer with one number for the whole range and need
 *   metric_type=total_value in the request or the API silently returns no
 *   data at all -- confirmed empirically: profile_views, accounts_engaged and
 *   friends all came back `"data":[]` without it.
 *
 * Nothing here calls Instagram on a page load. This is invoked by
 * `instagram:sync` or the manual "Sync now" action, and reads are always
 * against the local social_insights table.
 */
class InstagramInsights
{
    /** @var list<string> */
    public const ACCOUNT_TIME_SERIES_METRICS = ['reach', 'follower_count'];

    /** @var list<string> */
    public const ACCOUNT_TOTAL_VALUE_METRICS = [
        'views', 'profile_views', 'accounts_engaged', 'total_interactions',
        'likes', 'comments', 'shares', 'saves', 'replies', 'reposts',
        'website_clicks', 'profile_links_taps',
    ];

    /** Every media type is asked for these. */
    public const MEDIA_METRICS_COMMON = ['reach', 'views', 'likes', 'comments', 'saved', 'shares', 'total_interactions'];

    /** Only asked for when media_product_type is REELS -- Meta refuses them otherwise. */
    public const MEDIA_METRICS_REELS = ['ig_reels_avg_watch_time', 'ig_reels_video_view_total_time', 'reels_skip_rate'];

    /**
     * Media metrics that answer as a percentage (0-100, one decimal place)
     * rather than a count -- confirmed empirically against a real connected
     * account (riyamakeover_artistry, media 18125455090694038): `reels_skip_rate`
     * came back e.g. `66.5`, `60.6`, `57.4`, NOT a 0-1 fraction as Meta's docs
     * would suggest by the metric's name. `value` is an unsigned bigint built
     * for counts, so storeMediaResponse() scales these by
     * PERCENT_METRIC_SCALE before storing (66.5 -> 6650) instead of casting
     * straight to int, which would truncate the decimal and silently drop
     * precision. percentMetricValue() reverses the scale on read.
     *
     * @var list<string>
     */
    public const PERCENT_METRICS = ['reels_skip_rate'];

    /** Scale factor applied to PERCENT_METRICS before storing -- see above. */
    public const PERCENT_METRIC_SCALE = 100;

    /**
     * Who follows the account -- confirmed empirically against both live
     * connected accounts (see docs/MONTHLY_REPORT.md): `period=lifetime` +
     * `metric_type=total_value` + `breakdown=age,gender` in ONE call
     * returns a joint distribution (`dimension_keys: ["age","gender"]`);
     * `breakdown=city` is a SEPARATE call, Meta does not accept all three
     * dimensions in one request. Answers as
     * `total_value.breakdowns[0].results[]`, each a
     * `{dimension_values: [...], value: int}` row -- a shape social_insight
     * cannot hold (one metric -> one int), hence SocialAudienceSnapshot.
     */
    public const AUDIENCE_METRIC = 'follower_demographics';

    /** How many items a single /media call asks for. */
    private const MEDIA_SYNC_LIMIT = 25;

    /**
     * Hard ceiling on how many pages syncMedia() will follow when given a
     * $since floor (the one-time 90-day backfill -- see InstagramSyncRunner).
     * 10 pages * 25 items = 250, generous for a quarter's worth of posts for
     * any studio client, and a safety net against an unbounded loop should
     * Meta ever answer with a cursor that doesn't converge.
     */
    private const MEDIA_SYNC_MAX_PAGES = 10;

    public function __construct(private readonly InstagramGraph $graph) {}

    public static function make(): self
    {
        return new self(new InstagramGraph);
    }

    /**
     * Account-level metrics for one date range.
     *
     * @return array{synced: int, skipped: list<string>}
     */
    public function syncAccount(SocialAccount $account, Carbon $since, Carbon $until): array
    {
        $skipped = [];
        $synced = 0;

        $synced += $this->fetchAndStore(
            $account,
            self::ACCOUNT_TIME_SERIES_METRICS,
            ['period' => 'day', 'since' => $since->timestamp, 'until' => $until->timestamp],
            SocialInsight::TYPE_TIME_SERIES,
            $skipped,
        );

        /*
         * total_value metrics answer with ONE number for whatever [since,
         * until] was asked -- confirmed empirically: a 30-day request and a
         * single-day request against the same metric each return exactly one
         * total_value.value, no per-day breakdown.
         *
         * That makes a single range-wide call the wrong thing to cache. A
         * view of the dashboard computes its OWN [since, until] independently
         * of whenever the last sync ran -- "last 30 days" a few minutes after
         * sync is not bit-for-bit the same window as "last 30 days" when sync
         * ran -- so a row keyed to the sync's window would need to overlap
         * the view's window, and reporting an overlapping-but-different
         * range's total under a range's label is simply wrong, not a caching
         * detail.
         *
         * Looping one day at a time turns each total_value metric into a
         * proper daily row, exactly like the time-series metrics above, so it
         * SUMS correctly across whatever range a viewer later asks for. It
         * costs one extra API call per day of history rather than one call
         * for the whole range -- acceptable for a sync that runs on a
         * schedule, not on a page load.
         */
        for ($day = $since->copy()->startOfDay(); $day->lte($until); $day->addDay()) {
            $synced += $this->fetchAndStore(
                $account,
                self::ACCOUNT_TOTAL_VALUE_METRICS,
                ['metric_type' => 'total_value', 'period' => 'day', 'since' => $day->timestamp, 'until' => $day->copy()->endOfDay()->timestamp],
                SocialInsight::TYPE_TOTAL_VALUE,
                $skipped,
                singleRow: ['period_start' => $day->toDateString(), 'period_end' => $day->toDateString()],
            );
        }

        return ['synced' => $synced, 'skipped' => array_unique($skipped)];
    }

    /**
     * Media, cached, with their own insights.
     *
     * Without $since (every caller today except the one-time backfill --
     * see InstagramSyncRunner): exactly the latest 25 items, one call,
     * byte-for-byte the same request this always made. A routine "Sync
     * now"/`instagram:sync` does not get slower because this method grew a
     * new capability.
     *
     * With $since: follows Meta's own cursor pagination (`paging.cursors.after`
     * on this call feeds `after` on the next) across additional pages,
     * stopping as soon as a page's oldest item is older than $since, or
     * after MEDIA_SYNC_MAX_PAGES as a hard ceiling. The cursor mechanism
     * itself is Meta's standard, stable, identically-documented pagination
     * convention used across every Graph API list edge -- unlike the metric
     * shapes elsewhere in this class, this one did not need empirical
     * probing to trust. It HAS NOT been exercised against a real account
     * with more than 25 posts in one sync (neither connected account has
     * posted that often), so review this path's behavior the first time an
     * account actually crosses that line.
     *
     * @return array{items: int, synced: int, skipped: list<string>}
     */
    public function syncMedia(SocialAccount $account, ?Carbon $since = null): array
    {
        $skipped = [];
        $synced = 0;
        $items = 0;
        $after = null;
        $pages = 0;

        do {
            $query = [
                'fields' => 'id,caption,media_type,media_product_type,timestamp,permalink,thumbnail_url,media_url',
                'limit' => self::MEDIA_SYNC_LIMIT,
            ];

            if ($after !== null) {
                $query['after'] = $after;
            }

            $media = $this->graph->get($account->platform_user_id.'/media', $account->access_token, $query);
            $pages++;

            $entries = $media['data'] ?? [];
            $oldestOnPage = null;

            foreach ($entries as $entry) {
                $item = SocialMediaItem::updateOrCreate(
                    ['social_account_id' => $account->id, 'platform_media_id' => $entry['id']],
                    [
                        'media_type' => $entry['media_type'] ?? null,
                        'media_product_type' => $entry['media_product_type'] ?? null,
                        'caption' => $entry['caption'] ?? null,
                        'permalink' => $entry['permalink'] ?? null,
                        'thumbnail_url' => $entry['thumbnail_url'] ?? null,
                        'media_url' => $entry['media_url'] ?? null,
                        'posted_at' => isset($entry['timestamp']) ? Carbon::parse($entry['timestamp']) : null,
                        'cached_at' => now(),
                    ]
                );
                $items++;

                if (isset($entry['timestamp'])) {
                    $postedAt = Carbon::parse($entry['timestamp']);
                    $oldestOnPage = $oldestOnPage === null || $postedAt->lt($oldestOnPage) ? $postedAt : $oldestOnPage;
                }

                $metrics = self::MEDIA_METRICS_COMMON;

                if (($entry['media_product_type'] ?? null) === SocialMediaItem::PRODUCT_REELS) {
                    $metrics = [...$metrics, ...self::MEDIA_METRICS_REELS];
                }

                $synced += $this->fetchAndStoreMedia($account, $item, $metrics, $skipped);
            }

            $after = $media['paging']['cursors']['after'] ?? null;

            $keepGoing = $since !== null
                && $after !== null
                && $entries !== []
                && ($oldestOnPage === null || $oldestOnPage->gte($since))
                && $pages < self::MEDIA_SYNC_MAX_PAGES;
        } while ($keepGoing);

        return ['items' => $items, 'synced' => $synced, 'skipped' => array_unique($skipped)];
    }

    /**
     * Follower demographics -- age×gender and city, each a separate call
     * (see the AUDIENCE_METRIC docblock for why they cannot be combined).
     * A snapshot, not a time series: each call overwrites the account's one
     * row for that dimension via SocialAudienceSnapshot's upsert, so a
     * re-sync reflects "who follows right now", not an accumulating log.
     *
     * @return array{synced: int, skipped: list<string>}
     */
    public function syncAudience(SocialAccount $account): array
    {
        $skipped = [];
        $synced = 0;

        $synced += $this->fetchAndStoreAudience($account, SocialAudienceSnapshot::DIMENSION_AGE_GENDER, 'age,gender', $skipped);
        $synced += $this->fetchAndStoreAudience($account, SocialAudienceSnapshot::DIMENSION_CITY, 'city', $skipped);

        return ['synced' => $synced, 'skipped' => $skipped];
    }

    /**
     * @param  list<string>  $skipped
     */
    private function fetchAndStoreAudience(SocialAccount $account, string $dimension, string $breakdown, array &$skipped): int
    {
        try {
            $response = $this->graph->get($account->platform_user_id.'/insights', $account->access_token, [
                'metric' => self::AUDIENCE_METRIC,
                'period' => 'lifetime',
                'metric_type' => 'total_value',
                'breakdown' => $breakdown,
            ]);

            $results = $response['data'][0]['total_value']['breakdowns'][0]['results'] ?? null;

            if (! is_array($results)) {
                $skipped[] = self::AUDIENCE_METRIC.':'.$dimension;

                return 0;
            }

            SocialAudienceSnapshot::updateOrCreate(
                ['social_account_id' => $account->id, 'dimension' => $dimension],
                ['data' => $results, 'fetched_at' => now()],
            );

            return 1;
        } catch (InstagramException $e) {
            Log::info('Instagram audience demographics unsupported for this account, skipped.', [
                'client_id' => $account->client_id,
                'dimension' => $dimension,
                'reason' => $e->getMessage(),
            ]);
            $skipped[] = self::AUDIENCE_METRIC.':'.$dimension;

            return 0;
        }
    }

    /**
     * Every half for one account, tolerating a partial failure in any of
     * them.
     *
     * $mediaSince is null on every ordinary call ("Sync now", `instagram:sync`)
     * -- media stays the fast, unpaginated latest-25 fetch. Only the
     * one-time first-sync backfill (InstagramSyncRunner) passes a real
     * floor, since that is the one case where "the studio's most recent
     * quarter of posts" actually needs more than the newest 25.
     *
     * @return array{account: array, media: array, audience: array}
     */
    public function syncAll(SocialAccount $account, Carbon $since, Carbon $until, ?Carbon $mediaSince = null): array
    {
        return [
            'account' => $this->syncAccount($account, $since, $until),
            'media' => $this->syncMedia($account, $mediaSince),
            'audience' => $this->syncAudience($account),
        ];
    }

    /**
     * Ask for a batch of metrics; on failure, fall back to one at a time so a
     * single unsupported metric cannot cost the rest.
     *
     * Confirmed necessary rather than defensive theatre: Meta rejects the
     * WHOLE request when one metric in a batch is invalid, naming only the
     * first offending one in `metric[0]` regardless of its actual position --
     * there is no partial-success shape to parse, so the only way to recover
     * the good metrics is to ask again without the bad one.
     *
     * @param  list<string>  $metrics
     * @param  array<string, mixed>  $query
     * @param  list<string>  $skipped
     * @param  array{period_start: string, period_end: string}|null  $singleRow
     */
    private function fetchAndStore(
        SocialAccount $account,
        array $metrics,
        array $query,
        string $metricType,
        array &$skipped,
        ?array $singleRow = null,
    ): int {
        try {
            $response = $this->graph->get(
                $account->platform_user_id.'/insights',
                $account->access_token,
                $query + ['metric' => implode(',', $metrics)],
            );

            return $this->storeAccountResponse($account, $response, $metricType, $singleRow);
        } catch (InstagramException $e) {
            if (count($metrics) === 1) {
                Log::info('Instagram metric unsupported for this account, skipped.', [
                    'client_id' => $account->client_id,
                    'metric' => $metrics[0],
                    'reason' => $e->getMessage(),
                ]);
                $skipped[] = $metrics[0];

                return 0;
            }

            $stored = 0;

            foreach ($metrics as $metric) {
                $stored += $this->fetchAndStore($account, [$metric], $query, $metricType, $skipped, $singleRow);
            }

            return $stored;
        }
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array{period_start: string, period_end: string}|null  $singleRow
     */
    private function storeAccountResponse(SocialAccount $account, array $response, string $metricType, ?array $singleRow): int
    {
        $stored = 0;

        foreach ($response['data'] ?? [] as $metric) {
            $name = $metric['name'] ?? null;

            if (! $name) {
                continue;
            }

            if ($singleRow) {
                // total_value: one number for the whole range.
                $value = $metric['total_value']['value'] ?? null;

                if ($value === null || ! self::isStorableCount($value)) {
                    continue;
                }

                SocialInsight::record([
                    'social_account_id' => $account->id,
                    'metric' => $name,
                    'metric_type' => $metricType,
                    'value' => (int) $value,
                    'period' => 'range',
                    'period_start' => $singleRow['period_start'],
                    'period_end' => $singleRow['period_end'],
                ]);
                $stored++;

                continue;
            }

            // time_series: one row per day.
            foreach ($metric['values'] ?? [] as $point) {
                if (! isset($point['value'], $point['end_time']) || ! self::isStorableCount($point['value'])) {
                    continue;
                }

                /*
                 * Instagram's own daily buckets are anchored to a fixed
                 * boundary Meta does not expose or let you change -- in
                 * practice, empirically, midnight Pacific (end_time arrives
                 * as "...T07:00:00+0000" / "...T08:00:00+0000" depending on
                 * daylight saving). end_time is that boundary in UTC, and it
                 * is NOT the app's timezone.
                 *
                 * setTimezone(), not a bare Carbon::parse()->toDateString().
                 * Carbon::parse() keeps whatever offset the string itself
                 * carries (UTC here), so calling toDateString() straight
                 * after reads off the UTC calendar date -- correct only by
                 * coincidence for whichever hour of the day the boundary
                 * happens to land on. Converting explicitly to the studio's
                 * own timezone is what makes "the 16th" on this chart mean
                 * the 16th in Asia/Kolkata, which is the date a person
                 * looking at it actually means.
                 *
                 * The day this cannot make exact: Instagram's Pacific-day
                 * boundary and an IST calendar day are never the same 24-hour
                 * window, so a "day" here is Instagram's nearest bucket to
                 * that IST date, not a clean IST midnight-to-midnight sum.
                 * total_value metrics below have no such limit, because their
                 * [since, until] is one this class chooses itself, already in
                 * the app's timezone.
                 */
                $day = Carbon::parse($point['end_time'])
                    ->setTimezone(config('app.timezone'))
                    ->toDateString();

                SocialInsight::record([
                    'social_account_id' => $account->id,
                    'metric' => $name,
                    'metric_type' => $metricType,
                    'value' => (int) $point['value'],
                    'period' => 'day',
                    'period_start' => $day,
                    'period_end' => $day,
                ]);
                $stored++;
            }
        }

        return $stored;
    }

    /**
     * @param  list<string>  $metrics
     * @param  list<string>  $skipped
     */
    private function fetchAndStoreMedia(SocialAccount $account, SocialMediaItem $item, array $metrics, array &$skipped): int
    {
        try {
            $response = $this->graph->get($item->platform_media_id.'/insights', $account->access_token, [
                'metric' => implode(',', $metrics),
            ]);

            return $this->storeMediaResponse($account, $item, $response);
        } catch (InstagramException $e) {
            if (count($metrics) === 1) {
                Log::info('Instagram media metric unsupported for this item, skipped.', [
                    'media_id' => $item->platform_media_id,
                    'metric' => $metrics[0],
                    'reason' => $e->getMessage(),
                ]);
                $skipped[] = $metrics[0];

                return 0;
            }

            $stored = 0;

            foreach ($metrics as $metric) {
                $stored += $this->fetchAndStoreMedia($account, $item, [$metric], $skipped);
            }

            return $stored;
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function storeMediaResponse(SocialAccount $account, SocialMediaItem $item, array $response): int
    {
        $stored = 0;
        $today = now()->toDateString();

        foreach ($response['data'] ?? [] as $metric) {
            $name = $metric['name'] ?? null;
            // Media insights answer as a single current value, either shape.
            $value = $metric['values'][0]['value'] ?? $metric['total_value']['value'] ?? null;

            if (! $name || $value === null || ! self::isStorableCount($value)) {
                continue;
            }

            $storedValue = in_array($name, self::PERCENT_METRICS, true)
                ? (int) round(((float) $value) * self::PERCENT_METRIC_SCALE)
                : (int) $value;

            SocialInsight::record([
                'social_account_id' => $account->id,
                'social_media_item_id' => $item->id,
                'metric' => $name,
                'metric_type' => SocialInsight::TYPE_TOTAL_VALUE,
                'value' => $storedValue,
                'period' => 'lifetime',
                'period_start' => $today,
                'period_end' => $today,
            ]);
            $stored++;
        }

        return $stored;
    }

    /**
     * Whether a value from Meta is safe to write into `value` (an unsigned
     * bigint).
     *
     * Found, not anticipated: a live sync against a real connected account
     * crashed on a genuine production day where Meta answered `total_value:
     * {"value": -1}` for total_interactions and reposts -- both otherwise
     * ordinary non-negative counters. Nowhere in Meta's documentation
     * mentions -1 as a sentinel, but it is exactly the shape one would use
     * for "not computable for this day" without changing the response
     * schema, and treating it as a metric that quietly has no data for that
     * day is the closest honest reading. The alternative readings are both
     * worse: storing -1 corrupts every SUM this table is for, and crashing
     * the sync loses every OTHER metric and day in the same run over one
     * anomalous number.
     */
    private static function isStorableCount(mixed $value): bool
    {
        return is_numeric($value) && (float) $value >= 0;
    }

    /**
     * Reverses PERCENT_METRIC_SCALE for a `social_insights.value` row whose
     * metric is in PERCENT_METRICS -- e.g. a stored 6650 becomes 66.5 (the
     * percent Meta originally answered with). Not used anywhere yet: nothing
     * in the UI currently renders reels_skip_rate. Call this instead of
     * reading `value` directly once something does.
     */
    public static function percentMetricValue(int $storedValue): float
    {
        return $storedValue / self::PERCENT_METRIC_SCALE;
    }
}
