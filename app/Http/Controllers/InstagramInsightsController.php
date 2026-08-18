<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\SocialAccount;
use App\Services\Instagram\InstagramException;
use App\Services\Instagram\InstagramInsights;
use App\Services\Instagram\InstagramReportData;
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

        $overview = InstagramReportData::overview($account, $since, $until);
        $trend = InstagramReportData::trend($account, 'reach', $since, $until);
        $breakdown = InstagramReportData::engagementBreakdown($account, $since, $until);
        $content = InstagramReportData::contentPerformance($account, $since, $until);

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

            return back()->with('status', $status);
        } catch (InstagramException $e) {
            $account->recordFailure($e->userMessage(), fatal: $e->isAuthFailure());

            return back()->with('status', 'Could not sync: '.$e->userMessage());
        }
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
