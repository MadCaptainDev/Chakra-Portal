<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\ResolvesClient;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\PortfolioItem;
use App\Models\SocialAccount;
use App\Services\Instagram\InstagramReportData;
use App\Services\Instagram\InstagramSyncRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Your own Instagram analytics, self-service -- the same screen and the same
 * data as the staff-side App\Http\Controllers\InstagramInsightsController,
 * minus the two actions that are a studio decision rather than a client one:
 * "Sync now" (spends the account's API quota -- clients,edit territory over
 * there) and adding/removing a post from the studio's own portfolio. Both
 * already hide themselves correctly for a client-role user (no sync markup
 * is rendered when $selfService is passed, and the portfolio actions are
 * already gated by @canany(['portfolio.create', 'portfolio.delete']), which
 * a client role never has). The client id comes from the signed-in user's
 * own row via ResolvesClient, same as every other client screen, rather than
 * the {client} route segment the staff version reads.
 */
class InstagramInsightsController extends Controller
{
    use ResolvesClient;

    /** @var array<string, string> */
    private const RANGES = [
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        'this_month' => 'This month',
        'previous_month' => 'Previous month',
    ];

    // Same two constants as the staff controller -- see that class for why
    // they differ. Duplicated rather than shared because they are private
    // implementation constants of a controller, not a concept anything else
    // in the app needs to reference.
    private const CONTENT_HISTORY_DAYS = 730;

    private const ACCOUNT_INSIGHTS_DAYS = 90;

    public function show(Request $request): View
    {
        $client = $this->client($request);
        $account = $this->instagramFor($client);
        [$since, $until, $rangeKey] = $this->resolveRange($request);

        $shared = [
            'client' => $client,
            'selfService' => true,
            'insightsRoute' => route('client.instagram.insights'),
            'reportRoute' => route('client.instagram.report'),
            'backRoute' => route('client.social'),
        ];

        if (! $account) {
            return view('instagram.insights', $shared + [
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
        $content = InstagramReportData::contentPerformance($account, $since, $until, limit: 200, sortBy: $sortBy, direction: $direction);
        $formats = InstagramReportData::formatBreakdown($content);

        $portfolioItemsByMedia = PortfolioItem::query()
            ->whereIn('social_media_item_id', $content->pluck('id'))
            ->pluck('id', 'social_media_item_id');

        return view('instagram.insights', $shared + [
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
            'beyondAccountInsights' => $since->lt(now()->subDays(self::ACCOUNT_INSIGHTS_DAYS)->startOfDay()),
        ]);
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
            return [now()->subDays(29)->startOfDay(), now()->endOfDay(), '30d'];
        }

        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $earliest = now()->subDays(self::CONTENT_HISTORY_DAYS)->startOfDay();
        if ($from->lt($earliest)) {
            $from = $earliest;

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
