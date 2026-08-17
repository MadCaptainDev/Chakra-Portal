<?php

namespace App\Services\Instagram;

use App\Models\SocialAccount;
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

    /** How many recent items a sync caches insights for. */
    private const MEDIA_SYNC_LIMIT = 25;

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
     * Recent media, cached, with their own insights.
     *
     * @return array{items: int, synced: int, skipped: list<string>}
     */
    public function syncMedia(SocialAccount $account): array
    {
        $media = $this->graph->get($account->platform_user_id.'/media', $account->access_token, [
            'fields' => 'id,caption,media_type,media_product_type,timestamp,permalink,thumbnail_url,media_url',
            'limit' => self::MEDIA_SYNC_LIMIT,
        ]);

        $skipped = [];
        $synced = 0;
        $items = 0;

        foreach ($media['data'] ?? [] as $entry) {
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

            $metrics = self::MEDIA_METRICS_COMMON;

            if (($entry['media_product_type'] ?? null) === SocialMediaItem::PRODUCT_REELS) {
                $metrics = [...$metrics, ...self::MEDIA_METRICS_REELS];
            }

            $synced += $this->fetchAndStoreMedia($account, $item, $metrics, $skipped);
        }

        return ['items' => $items, 'synced' => $synced, 'skipped' => array_unique($skipped)];
    }

    /**
     * Both halves for one account, tolerating a partial failure in either.
     *
     * @return array{account: array, media: array}
     */
    public function syncAll(SocialAccount $account, Carbon $since, Carbon $until): array
    {
        return [
            'account' => $this->syncAccount($account, $since, $until),
            'media' => $this->syncMedia($account),
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

                if ($value === null) {
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
                if (! isset($point['value'], $point['end_time'])) {
                    continue;
                }

                $day = Carbon::parse($point['end_time'])->toDateString();

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

            if (! $name || $value === null) {
                continue;
            }

            SocialInsight::record([
                'social_account_id' => $account->id,
                'social_media_item_id' => $item->id,
                'metric' => $name,
                'metric_type' => SocialInsight::TYPE_TOTAL_VALUE,
                'value' => (int) $value,
                'period' => 'lifetime',
                'period_start' => $today,
                'period_end' => $today,
            ]);
            $stored++;
        }

        return $stored;
    }
}
