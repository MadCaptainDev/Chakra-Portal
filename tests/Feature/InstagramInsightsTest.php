<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\InstagramSetting;
use App\Models\SocialAccount;
use App\Models\SocialInsight;
use App\Models\SocialMediaItem;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\Instagram\InstagramInsights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fetching and reading Instagram analytics.
 *
 * The metric shapes faked here are not guessed: they are the exact shapes a
 * live account returned when this was built (`impressions` rejected naming
 * the valid metric enum; `profile_views` and friends empty without
 * metric_type=total_value; media insights answering with `values`). See
 * InstagramInsights for the citation.
 */
class InstagramInsightsTest extends TestCase
{
    use RefreshDatabase;

    private function client(string $name = 'Chakra Production'): Client
    {
        return Client::create(['name' => $name]);
    }

    private function connectedAccount(
        ?Client $client = null,
        string $platformUserId = '17841476964090891',
        string $username = 'thechakra_productions',
    ): SocialAccount {
        $client ??= $this->client();

        $account = SocialAccount::create([
            'client_id' => $client->id,
            'platform' => SocialAccount::PLATFORM_INSTAGRAM,
            'platform_user_id' => $platformUserId,
            'username' => $username,
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);

        $account->forceFill(['access_token' => 'IGQV-token', 'connected_at' => now()])->save();

        return $account->fresh();
    }

    private function staff(array $abilities = ['view']): User
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        foreach ($abilities as $ability) {
            UserPermission::create(['user_id' => $user->id, 'module' => 'clients', 'ability' => $ability]);
        }

        return $user->refresh();
    }

    /** A day-by-day answer, the shape time_series metrics actually return. */
    private function timeSeries(string $metric, array $dailyValues): array
    {
        $values = [];
        $day = now()->subDays(count($dailyValues) - 1);

        foreach ($dailyValues as $v) {
            $values[] = ['value' => $v, 'end_time' => $day->toIso8601String()];
            $day = $day->copy()->addDay();
        }

        return ['name' => $metric, 'period' => 'day', 'values' => $values];
    }

    /** total_value answers one number for the whole range. */
    private function totalValue(string $metric, int $value): array
    {
        return ['name' => $metric, 'period' => 'day', 'total_value' => ['value' => $value]];
    }

    public function test_syncing_account_metrics_stores_a_row_per_day(): void
    {
        $account = $this->connectedAccount();

        Http::fake([
            'graph.instagram.com/*/insights*metric=reach%2Cfollower_count*' => Http::response([
                'data' => [
                    $this->timeSeries('reach', [10, 20, 30]),
                    $this->timeSeries('follower_count', [100, 100, 102]),
                ],
            ]),
            // total_value is asked once per day in the range -- see the note
            // on syncAccount() for why a single range-wide call is wrong.
            // Every day's call gets the same fixture value here; the
            // composability that matters is covered by its own test below.
            'graph.instagram.com/*/insights*metric_type=total_value*' => Http::response([
                'data' => [$this->totalValue('views', 500)],
            ]),
        ]);

        $result = InstagramInsights::make()->syncAccount($account, now()->subDays(2), now());

        $this->assertSame(3, SocialInsight::where('metric', 'reach')->count());
        $this->assertSame(30, SocialInsight::where('metric', 'reach')->orderByDesc('period_start')->value('value'));
        $this->assertSame(102, SocialInsight::where('metric', 'follower_count')->orderByDesc('period_start')->value('value'));
        // A 3-day range means 3 daily calls for views, one row each.
        $this->assertSame(3, SocialInsight::where('metric', 'views')->count());
        $this->assertGreaterThan(0, $result['synced']);
    }

    public function test_total_value_metrics_sum_correctly_across_a_range_synced_independently_of_the_view(): void
    {
        // The actual bug this fixes: views/engagement showed as 0 on a real
        // connected account despite Instagram reporting real numbers for
        // every individual day, because the whole range was cached as ONE
        // row and a later read used a slightly different [since, until] than
        // whatever the sync happened to use -- the row's single period_start
        // fell outside the read's window and the SUM silently found nothing.
        $account = $this->connectedAccount();
        $callsPerDay = [];

        Http::fake(function ($request) use (&$callsPerDay) {
            $url = $request->url();

            if (str_contains($url, 'metric=reach')) {
                return Http::response(['data' => [$this->timeSeries('reach', [1, 1, 1])]]);
            }

            parse_str(parse_url($url, PHP_URL_QUERY), $q);
            $day = date('Y-m-d', (int) $q['since']);
            $callsPerDay[$day] = ($callsPerDay[$day] ?? 0) + 1;

            // A distinct, verifiable value for each of the three days.
            $value = match ($day) {
                now()->subDays(2)->toDateString() => 100,
                now()->subDays(1)->toDateString() => 200,
                default => 300,
            };

            return Http::response(['data' => [$this->totalValue('views', $value)]]);
        });

        InstagramInsights::make()->syncAccount($account, now()->subDays(2)->startOfDay(), now()->endOfDay());

        // One call per day, not one for the whole range.
        $this->assertCount(3, $callsPerDay);

        // Reading with a DIFFERENT, independently-computed window -- exactly
        // what the dashboard controller does on every page load -- must still
        // see all three days' worth.
        $independentSince = now()->subDays(6)->startOfDay();
        $independentUntil = now()->addMinute()->endOfDay();

        $total = SocialInsight::query()
            ->where('social_account_id', $account->id)
            ->accountLevel()
            ->metric('views')
            ->between($independentSince, $independentUntil)
            ->sum('value');

        $this->assertSame(600, $total);
    }

    public function test_resyncing_a_day_updates_the_row_rather_than_duplicating_it(): void
    {
        $account = $this->connectedAccount();

        Http::fake(['graph.instagram.com/*' => Http::response([
            'data' => [$this->timeSeries('reach', [10])],
        ])]);

        InstagramInsights::make()->syncAccount($account, now(), now());
        InstagramInsights::make()->syncAccount($account, now(), now());

        // Same day, fetched twice -- one row, latest value.
        $this->assertSame(1, SocialInsight::where('metric', 'reach')->count());
    }

    public function test_an_unsupported_metric_is_skipped_without_losing_the_others(): void
    {
        $account = $this->connectedAccount();

        Http::fake([
            // The batch request fails, exactly as Meta does when one metric in
            // a combined request is invalid for the account.
            'graph.instagram.com/*/insights*metric=reach%2Cfollower_count*' => Http::response([
                'error' => ['message' => 'metric[0] must be one of the following values: reach, ...', 'type' => 'OAuthException'],
            ], 400),
        ]);

        // Individual fallback calls: reach succeeds, follower_count fails.
        Http::fake([
            'graph.instagram.com/*/insights*metric=reach&*' => Http::response(['data' => [$this->timeSeries('reach', [5])]]),
            'graph.instagram.com/*/insights*metric=follower_count&*' => Http::response([
                'error' => ['message' => 'This metric is not supported for this account.'],
            ], 400),
        ]);

        $result = InstagramInsights::make()->syncAccount($account, now(), now());

        $this->assertSame(1, SocialInsight::where('metric', 'reach')->count());
        $this->assertSame(0, SocialInsight::where('metric', 'follower_count')->count());
        $this->assertContains('follower_count', $result['skipped']);
    }

    public function test_syncing_media_caches_items_and_their_insights(): void
    {
        $account = $this->connectedAccount();

        Http::fake([
            'graph.instagram.com/*/media?*' => Http::response(['data' => [
                [
                    'id' => 'media-1',
                    'caption' => 'Behind the scenes',
                    'media_type' => 'VIDEO',
                    'media_product_type' => 'REELS',
                    'timestamp' => now()->toIso8601String(),
                    'permalink' => 'https://instagram.com/p/media-1',
                    'thumbnail_url' => 'https://cdn.example/thumb.jpg',
                ],
            ]]),
            'graph.instagram.com/*/media-1/insights*' => Http::response(['data' => [
                ['name' => 'reach', 'values' => [['value' => 8000]]],
                ['name' => 'views', 'values' => [['value' => 12000]]],
                ['name' => 'ig_reels_avg_watch_time', 'values' => [['value' => 4]]],
            ]]),
        ]);

        $result = InstagramInsights::make()->syncMedia($account);

        $item = SocialMediaItem::sole();

        $this->assertSame('media-1', $item->platform_media_id);
        $this->assertSame('Reel', $item->typeLabel());
        $this->assertSame(8000, $item->metricValue('reach'));
        $this->assertSame(12000, $item->metricValue('views'));
        $this->assertSame(4, $item->metricValue('ig_reels_avg_watch_time'));
        $this->assertSame(1, $result['items']);
    }

    public function test_reel_only_metrics_are_not_requested_for_a_feed_post(): void
    {
        $account = $this->connectedAccount();
        $requested = [];

        Http::fake(function ($request) use (&$requested) {
            if (str_contains($request->url(), '/media?')) {
                return Http::response(['data' => [[
                    'id' => 'media-2', 'media_type' => 'IMAGE', 'media_product_type' => 'FEED',
                    'timestamp' => now()->toIso8601String(),
                ]]]);
            }

            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            $requested = explode(',', $query['metric'] ?? '');

            return Http::response(['data' => []]);
        });

        InstagramInsights::make()->syncMedia($account);

        // A feed post asked with a Reels-only metric is refused outright, so
        // it must never be in the request for anything but a Reel.
        $this->assertNotContains('ig_reels_avg_watch_time', $requested);
        $this->assertContains('reach', $requested);
    }

    public function test_a_long_cdn_thumbnail_url_does_not_break_the_media_cache(): void
    {
        // The exact failure mode that broke the first live connection, on a
        // different column. Regression coverage for the pattern, not just the
        // one column it was found on.
        $account = $this->connectedAccount();
        $longUrl = 'https://scontent-bom5-1.cdninstagram.com/v/t51.82787-19/'.str_repeat('a1B2', 200).'.jpg?oh=x&oe=y';

        Http::fake([
            'graph.instagram.com/*/media?*' => Http::response(['data' => [[
                'id' => 'media-3', 'media_type' => 'IMAGE', 'media_product_type' => 'FEED',
                'thumbnail_url' => $longUrl, 'media_url' => $longUrl,
                'timestamp' => now()->toIso8601String(),
            ]]]),
            'graph.instagram.com/*/media-3/insights*' => Http::response(['data' => []]),
        ]);

        InstagramInsights::make()->syncMedia($account);

        $this->assertSame($longUrl, SocialMediaItem::sole()->thumbnail_url);
    }

    // -- The insights screen -----------------------------------------------

    public function test_the_insights_page_shows_only_locally_cached_data(): void
    {
        $client = $this->client();
        $account = $this->connectedAccount($client);

        SocialInsight::record([
            'social_account_id' => $account->id,
            'metric' => 'reach',
            'metric_type' => SocialInsight::TYPE_TIME_SERIES,
            'value' => 1234,
            'period' => 'day',
            'period_start' => now()->toDateString(),
        ]);

        Http::fake(); // Any HTTP call at all fails the test.

        $this->actingAs($this->staff())
            ->get(route('instagram.insights', $client))
            ->assertOk()
            ->assertSee('1,234');

        Http::assertNothingSent();
    }

    public function test_one_clients_insights_are_never_reachable_through_another_clients_url(): void
    {
        $mine = $this->client('SVA Silks');
        $theirs = $this->client('DJ Thanga Maligai');
        $this->connectedAccount($theirs);

        SocialInsight::record([
            'social_account_id' => SocialAccount::sole()->id,
            'metric' => 'reach',
            'value' => 99999,
            'period' => 'day',
            'period_start' => now()->toDateString(),
        ]);

        // scopeBindings() must refuse this outright: {client} in the URL is
        // "mine", but the only social account in the database belongs to
        // "theirs" -- the route has nothing valid to bind to.
        $this->actingAs($this->staff())
            ->get(route('instagram.insights', $mine))
            ->assertOk()
            ->assertDontSee('99,999');
    }

    public function test_viewing_insights_needs_only_view_not_manage(): void
    {
        $client = $this->client();
        $this->connectedAccount($client);

        $this->actingAs($this->staff(['view']))
            ->get(route('instagram.insights', $client))
            ->assertOk();
    }

    public function test_syncing_from_the_page_needs_edit(): void
    {
        $client = $this->client();
        $this->connectedAccount($client);

        $this->actingAs($this->staff(['view']))
            ->post(route('instagram.insights.sync', $client))
            ->assertForbidden();
    }

    public function test_a_guest_reaches_none_of_it(): void
    {
        $client = $this->client();

        $this->get(route('instagram.insights', $client))->assertRedirect(route('login'));
        $this->post(route('instagram.insights.sync', $client))->assertRedirect(route('login'));
    }

    public function test_the_page_still_works_with_nothing_connected(): void
    {
        $this->actingAs($this->staff())
            ->get(route('instagram.insights', $this->client()))
            ->assertOk()
            ->assertSee('No Instagram account is connected');
    }

    public function test_sync_command_does_not_stop_at_the_first_accounts_failure(): void
    {
        InstagramSetting::current()->update(['app_id' => 'x', 'app_secret' => 'y']);

        $broken = $this->connectedAccount($this->client('Broken Client'), platformUserId: 'broken-account-id');
        $working = $this->connectedAccount($this->client('Working Client'), platformUserId: 'working-account-id');

        Http::fake([
            // The wildcard between host and account id stands in for
            // /v23.0/ -- omit it and the pattern never matches the real
            // request, Http::fake() falls through to an actual (sandboxed,
            // doomed) network call, and the test hangs on a timeout rather
            // than failing fast on a wrong assertion.
            'graph.instagram.com/*/broken-account-id/*' => Http::response(
                ['error' => ['message' => 'Invalid OAuth access token', 'code' => 190]], 401
            ),
            'graph.instagram.com/*/working-account-id/*' => Http::response(['data' => []]),
        ]);

        // One account's expired token must not stop the other's sync -- the
        // same guarantee GenerateRecurringInvoices makes per invoice.
        $this->artisan('instagram:sync')->assertExitCode(1);

        $this->assertNotNull($broken->fresh()->last_error);
        $this->assertSame(SocialAccount::STATUS_REVOKED, $broken->fresh()->status);

        $this->assertNull($working->fresh()->last_error);
        $this->assertNotNull($working->fresh()->last_synced_at);
    }
}
