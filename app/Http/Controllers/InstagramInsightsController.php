<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\PortfolioItem;
use App\Models\SocialAccount;
use App\Services\Instagram\InstagramReportData;
use App\Services\Instagram\InstagramSyncRunner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * The Instagram analytics screen for one client.
 *
 * Mostly reads the local social_insights cache -- "Sync now" is the
 * deliberate, opt-in, rate-visible way to refresh it. show() is the one
 * exception: it calls InstagramSyncRunner::ensureFresh() first, which
 * silently backfills a never-synced account's first 90 days, or fills in a
 * specific range that has never been fetched (a custom range picked via
 * "Go") -- both throttle-respecting and a no-op on every ordinary view
 * where the cache already covers what's being looked at. See
 * InstagramSyncRunner for the full reasoning.
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

    /**
     * How far back the custom-range picker will go -- NOT the same wall as
     * Instagram's 90-day account-insights retention (ACCOUNT_INSIGHTS_DAYS
     * below), and deliberately more generous. Confirmed live against a real
     * connected account (janethospitaltrichy): individual posts and their
     * own per-post metrics (reach/views/likes on that specific post) are
     * still fully available for posts from ~4 months back, well outside the
     * 90-day account-level window -- Content Performance can use a range
     * this old even though the account-wide charts above it (Reach,
     * Follower growth, Views trend, Engagement breakdown) cannot, because
     * those read a different, harder-capped metric family. There is no
     * confirmed outer edge for per-post retention; 2 years is a deliberately
     * conservative guess at "further back than any real client will need",
     * not an empirically confirmed limit -- revisit if a client's history
     * turns out to need more.
     */
    private const CONTENT_HISTORY_DAYS = 730;

    /** Instagram's own hard retention ceiling for account-level daily insights -- confirmed, not a guess. */
    private const ACCOUNT_INSIGHTS_DAYS = 90;

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

        InstagramSyncRunner::ensureFresh($account, $since, $until, checkWindow: $rangeKey === 'custom');

        [$sortBy, $direction] = $this->resolveSort($request);

        $overview = InstagramReportData::overview($account, $since, $until);
        $trend = InstagramReportData::trend($account, 'reach', $since, $until);
        $breakdown = InstagramReportData::engagementBreakdown($account, $since, $until);
        // 200, not the old bare 25: with sorting now user-controlled, the DB
        // cap has to hold enough of the range for a sort to mean something --
        // capping at 25 THEN sorting only reorders whichever 25 were most
        // recently posted, silently hiding a high-reach older post from its
        // own "sort by reach" (the same fix Monthly Report already needed
        // its own limit bump for, same reasoning).
        $content = InstagramReportData::contentPerformance($account, $since, $until, limit: 200, sortBy: $sortBy, direction: $direction);
        $formats = InstagramReportData::formatBreakdown($content);

        // Which of the media on screen already has a portfolio piece built
        // from it, keyed by social_media_item_id -> the PortfolioItem so the
        // row can link straight to removing it. One query for the whole
        // page rather than one per row: content can hold up to 200 items,
        // and a portfolio piece is looked up by a unique FK either way.
        $portfolioItemsByMedia = PortfolioItem::query()
            ->whereIn('social_media_item_id', $content->pluck('id'))
            ->pluck('id', 'social_media_item_id');

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
            'formats' => $formats,
            'portfolioItemsByMedia' => $portfolioItemsByMedia,
            'sortBy' => $sortBy,
            'direction' => $direction,
            // True whenever ANY part of the selected range is older than
            // Instagram's 90-day account-insights ceiling -- the view uses
            // this to explain why Reach/Follower growth/Views/Engagement
            // can look thin or empty for that portion, instead of pointing
            // at "Sync now" for something no sync can ever fetch.
            'beyondAccountInsights' => $since->lt(now()->subDays(self::ACCOUNT_INSIGHTS_DAYS)->startOfDay()),
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

        return back()->with('status', InstagramSyncRunner::run($account, $since, $until));
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

        // CONTENT_HISTORY_DAYS, not ACCOUNT_INSIGHTS_DAYS -- see the
        // constant's own comment. Individual posts reach back further than
        // account-level insights do, so the picker's floor is set by the
        // more generous of the two; the account-wide charts showing empty
        // for the portion of the range past 90 days is a real, honest
        // limit the view itself explains, not something to hide by
        // clamping the whole picker to the tighter number.
        $earliest = now()->subDays(self::CONTENT_HISTORY_DAYS)->startOfDay();
        if ($from->lt($earliest)) {
            $from = $earliest;

            // A $to that ALSO predates the floor (someone asking for a
            // window entirely before it, e.g. a stray old date) just got
            // walked past by the clamp above -- $from is now after $to,
            // an inverted range no query layer should be asked to make
            // sense of. Pull $to up to match rather than let that through.
            if ($to->lt($from)) {
                $to = $from->copy()->endOfDay();
            }
        }

        return [$from, $to->min(now()->endOfDay()), 'custom'];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveSort(Request $request): array
    {
        $sortBy = $request->query('sort', 'reach');
        $direction = $request->query('direction', 'desc');

        if (! array_key_exists($sortBy, InstagramReportData::SORTABLE)) {
            $sortBy = 'reach';
        }

        return [$sortBy, $direction === 'asc' ? 'asc' : 'desc'];
    }

    private function instagramFor(Client $client): ?SocialAccount
    {
        return $client->socialAccounts()
            ->forPlatform(SocialAccount::PLATFORM_INSTAGRAM)
            ->connected()
            ->first();
    }
}
