<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\SocialAccount;
use App\Models\SocialInsight;
use App\Services\Instagram\InstagramException;
use App\Services\Instagram\InstagramInsights;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * The Instagram analytics screen for one client.
 *
 * Reads ONLY the local social_insights cache -- see InstagramInsights for why
 * nothing here calls Instagram on a page load. "Sync now" is the one action
 * that does, and it is opt-in and rate-visible to whoever clicks it.
 *
 * scopeBindings() on the route group is what makes this safe across clients:
 * {client} and the account it resolves are bound together, so client B's
 * account can never be the one rendered from client A's URL. There is no
 * account id anywhere in this controller that came from the request.
 */
class InstagramInsightsController extends Controller
{
    /** @var array<string, string> */
    private const RANGES = [
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        'this_month' => 'This month',
        'previous_month' => 'Previous month',
    ];

    public function show(Request $request, Client $client): View
    {
        $account = $this->instagramFor($client);
        [$since, $until, $rangeKey] = $this->resolveRange($request);

        if (! $account) {
            return view('instagram.insights', [
                'client' => $client,
                'account' => null,
                'ranges' => self::RANGES,
                'rangeKey' => $rangeKey,
                'since' => $since,
                'until' => $until,
            ]);
        }

        $overview = $this->overview($account, $since, $until);
        $trend = $this->trend($account, 'reach', $since, $until);
        $breakdown = $this->engagementBreakdown($account, $since, $until);
        $content = $this->contentPerformance($account, $since, $until);

        return view('instagram.insights', [
            'client' => $client,
            'account' => $account,
            'ranges' => self::RANGES,
            'rangeKey' => $rangeKey,
            'since' => $since,
            'until' => $until,
            'overview' => $overview,
            'trend' => $trend,
            'breakdown' => $breakdown,
            'content' => $content,
        ]);
    }

    /**
     * Pull fresh data for this one client, on demand.
     *
     * The only place in the insights screen that calls Instagram. A single
     * account's sync is one or two HTTP round trips, which is fine for a
     * button click and wrong for a scheduled job hitting every client on
     * every page view -- see InstagramInsights for that reasoning.
     */
    public function sync(Request $request, Client $client): RedirectResponse
    {
        $account = $this->instagramFor($client);

        if (! $account) {
            return back()->with('status', 'Connect Instagram for this client before syncing.');
        }

        if (! $account->canSyncNow()) {
            $wait = now()->diffForHumans($account->nextSyncAllowedAt(), true);

            return back()->with('status', "Synced too recently — you can sync again in about {$wait}. "
                .'The throttle is set under Setup → Instagram.');
        }

        [$since, $until] = $this->resolveRange($request);

        try {
            $result = InstagramInsights::make()->syncAll($account, $since, $until);

            $account->forceFill(['last_synced_at' => now()])->save();
            $account->clearFailure();

            $skipped = array_unique([...$result['account']['skipped'], ...$result['media']['skipped']]);

            $status = sprintf('Synced. %d item(s) checked.', $result['media']['items']);

            if ($skipped !== []) {
                $status .= ' Not available for this account: '.implode(', ', $skipped).'.';
            }

            return back()->with('status', $status);
        } catch (InstagramException $e) {
            $account->recordFailure($e->userMessage(), fatal: $e->isAuthFailure());

            return back()->with('status', 'Could not sync: '.$e->userMessage());
        }
    }

    /**
     * @return array{reach: int, views: int, engagement: int, followers: int|null, follower_growth: int|null}
     */
    private function overview(SocialAccount $account, Carbon $since, Carbon $until): array
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
    private function engagementBreakdown(SocialAccount $account, Carbon $since, Carbon $until): array
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
    private function trend(SocialAccount $account, string $metric, Carbon $since, Carbon $until): array
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
     * Filtered by posted_at, which was the bug reported and confirmed:
     * without it, this ignored $since/$until entirely and always showed the
     * most recent items overall, so picking "Last 7 days" could still surface
     * a post from three weeks ago sitting inside the most-recent-N window.
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
    private function contentPerformance(SocialAccount $account, Carbon $since, Carbon $until, int $limit = 25)
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

    /**
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    private function resolveRange(Request $request): array
    {
        $key = $request->query('range', '30d');

        return match ($key) {
            '7d' => [now()->subDays(6)->startOfDay(), now()->endOfDay(), '7d'],
            'this_month' => [now()->startOfMonth(), now()->endOfDay(), 'this_month'],
            'previous_month' => [
                now()->subMonthNoOverflow()->startOfMonth(),
                now()->subMonthNoOverflow()->endOfMonth(),
                'previous_month',
            ],
            'custom' => $this->customRange($request),
            default => [now()->subDays(29)->startOfDay(), now()->endOfDay(), '30d'],
        };
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    private function customRange(Request $request): array
    {
        try {
            $from = Carbon::parse((string) $request->query('from'))->startOfDay();
            $to = Carbon::parse((string) $request->query('to'))->endOfDay();
        } catch (\Throwable) {
            // An unparsable custom range falls back rather than 500ing on a
            // typo in the address bar.
            return [now()->subDays(29)->startOfDay(), now()->endOfDay(), '30d'];
        }

        // A backwards range is swapped rather than rejected -- the person
        // meant something, and guessing right costs nothing here.
        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        // Instagram keeps 90 days of account insights; asking further back
        // always returns nothing, which would look like a bug rather than a
        // documented limit.
        $earliest = now()->subDays(90)->startOfDay();
        if ($from->lt($earliest)) {
            $from = $earliest;
        }

        return [$from, $to->min(now()->endOfDay()), 'custom'];
    }

    private function instagramFor(Client $client): ?SocialAccount
    {
        return $client->socialAccounts()
            ->forPlatform(SocialAccount::PLATFORM_INSTAGRAM)
            ->connected()
            ->first();
    }
}
