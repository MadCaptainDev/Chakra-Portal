<?php

namespace App\Services\Instagram;

use App\Models\SocialAccount;
use App\Models\SocialInsight;
use Illuminate\Support\Carbon;

/**
 * The cached-data computations behind an Instagram report for one account
 * and date range -- shared by the Insights screen and the Monthly Report,
 * so a range's overview/breakdown/trend/top-content mean the same thing
 * wherever they're shown.
 *
 * Reads ONLY the local social_insights cache -- see InstagramInsights for
 * why nothing here calls Instagram. Extracted verbatim from
 * InstagramInsightsController (mechanical move, not a rewrite): the
 * existing InstagramInsightsTest suite already exercises every method here
 * through that controller and is the regression proof that this move
 * changed nothing.
 */
class InstagramReportData
{
    /**
     * @return array{reach: int, views: int, engagement: int, followers: int|null, follower_growth: int|null}
     */
    public static function overview(SocialAccount $account, Carbon $since, Carbon $until): array
    {
        $totals = SocialInsight::query()
            ->where('social_account_id', $account->id)
            ->accountLevel()
            ->between($since, $until)
            ->whereIn('metric', ['reach', 'views', 'total_interactions'])
            ->selectRaw('metric, SUM(value) as total')
            ->groupBy('metric')
            ->pluck('total', 'metric');

        $followerSeries = SocialInsight::query()
            ->where('social_account_id', $account->id)
            ->accountLevel()
            ->metric('follower_count')
            ->between($since, $until)
            ->orderBy('period_start')
            ->get(['value', 'period_start']);

        return [
            'reach' => (int) ($totals['reach'] ?? 0),
            'views' => (int) ($totals['views'] ?? 0),
            'engagement' => (int) ($totals['total_interactions'] ?? 0),
            // The account's current count, not a range sum -- a follower
            // count summed over days is not a number anybody means.
            'followers' => $account->followers_count,
            'follower_growth' => $followerSeries->count() > 1
                ? (int) $followerSeries->last()->value - (int) $followerSeries->first()->value
                : null,
        ];
    }

    /**
     * Engagement, taken apart into what it is made of.
     *
     * "Engagement" alone tells a client a number moved; a like, a save and a
     * share are three different reasons to make more of something, and the
     * total flattens them into one indistinguishable figure. Every one of
     * these is already synced for the overview total_interactions figure --
     * this reads the same cached rows, no extra API call.
     *
     * Named counts rather than a percentage of the total: Meta's own
     * total_interactions sometimes includes interaction types this account
     * does not break out individually (poll votes, sticker taps), so the six
     * components below will not always sum to exactly the overview figure,
     * and implying they do with a 100%-stacked bar would be a precision the
     * data does not have.
     *
     * @return list<array{label: string, value: int}>
     */
    public static function engagementBreakdown(SocialAccount $account, Carbon $since, Carbon $until): array
    {
        $metrics = ['likes' => 'Likes', 'comments' => 'Comments', 'shares' => 'Shares',
            'saves' => 'Saves', 'reposts' => 'Reposts', 'replies' => 'Replies'];

        $totals = SocialInsight::query()
            ->where('social_account_id', $account->id)
            ->accountLevel()
            ->between($since, $until)
            ->whereIn('metric', array_keys($metrics))
            ->selectRaw('metric, SUM(value) as total')
            ->groupBy('metric')
            ->pluck('total', 'metric');

        return collect($metrics)
            ->map(fn (string $label, string $metric) => ['label' => $label, 'value' => (int) ($totals[$metric] ?? 0)])
            ->values()
            ->all();
    }

    /**
     * One metric, one row per day in the range -- what the trend chart draws.
     *
     * Every day in the range is represented even when nothing was fetched for
     * it, so a gap in the sync reads as a flat patch on the chart rather than
     * silently compressing the x-axis.
     *
     * @return list<array{date: string, value: int}>
     */
    public static function trend(SocialAccount $account, string $metric, Carbon $since, Carbon $until): array
    {
        $byDay = SocialInsight::query()
            ->where('social_account_id', $account->id)
            ->accountLevel()
            ->metric($metric)
            ->between($since, $until)
            ->get(['value', 'period_start'])
            ->keyBy(fn (SocialInsight $row) => $row->period_start->toDateString());

        $days = [];

        for ($day = $since->copy(); $day->lte($until); $day->addDay()) {
            $key = $day->toDateString();
            $days[] = ['date' => $key, 'value' => (int) ($byDay[$key]->value ?? 0)];
        }

        return $days;
    }

    /**
     * Media actually posted within the selected range, sorted by reach so the
     * best performer leads -- "what worked" is the question this table
     * answers.
     *
     * Filtered by posted_at: without it, this would ignore $since/$until
     * entirely and always show the most recent items overall, so picking
     * "Last 7 days" could still surface a post from three weeks ago sitting
     * inside the most-recent-N window (the bug reported and fixed here).
     *
     * The insight VALUES themselves are not range-scoped the way the metric
     * is filtered here -- Meta's media insights answer with a single current
     * total for a piece of content, not a per-day series the way account
     * metrics do, confirmed empirically (see InstagramInsights). Filtering
     * the row's own posted_at is the correct fix for "which posts am I
     * looking at"; there is no equivalent fix for "reach as of the 12th" on a
     * single post, because Meta does not offer that number.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\SocialMediaItem>
     */
    public static function contentPerformance(SocialAccount $account, Carbon $since, Carbon $until, int $limit = 25)
    {
        return $account->socialMediaItems()
            ->with('insights')
            ->whereBetween('posted_at', [$since, $until])
            ->newestFirst()
            ->limit($limit)
            ->get()
            ->sortByDesc(fn ($item) => $item->metricValue('reach') ?? -1)
            ->values();
    }
}
