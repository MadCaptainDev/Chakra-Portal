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

    public function test_a_time_series_day_is_stored_in_the_apps_timezone_not_raw_utc(): void
    {
        $account = $this->connectedAccount();

        // 21:00 UTC = 02:30 the NEXT calendar day in Asia/Kolkata (UTC+5:30).
        // Taking the date straight off the UTC string would file this under
        // the 15th; a person in India reading "reach for the 16th" means the
        // 16th where they are.
        Http::fake(['graph.instagram.com/*' => Http::response(['data' => [[
            'name' => 'reach',
            'values' => [['value' => 777, 'end_time' => '2026-08-15T21:00:00+0000']],
        ]]])]);

        InstagramInsights::make()->syncAccount($account, now(), now());

        $row = SocialInsight::where('metric', 'reach')->sole();

        $this->assertSame('2026-08-16', $row->period_start->toDateString());
    }

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

    public function test_a_negative_sentinel_value_is_skipped_rather_than_crashing_the_sync(): void
    {
        // Found on a real connected account: Meta answered total_value:
        // {"value": -1} for total_interactions and reposts on one otherwise
        // ordinary day -- nowhere documented, presumably "not computable for
        // this day". -1 does not fit the unsigned column value is stored in,
        // and every prior implementation of this test would have crashed the
        // whole sync on it. It must be skipped like an unsupported metric,
        // not stored and not fatal to the day's other metrics.
        $account = $this->connectedAccount();

        Http::fake([
            'graph.instagram.com/*/insights*metric=reach%2Cfollower_count*' => Http::response(['data' => []]),
            'graph.instagram.com/*/insights*metric_type=total_value*' => Http::response(['data' => [
                $this->totalValue('total_interactions', -1),
                $this->totalValue('reposts', -1),
                $this->totalValue('views', 42),
            ]]),
        ]);

        $result = InstagramInsights::make()->syncAccount($account, now(), now());

        $this->assertSame(0, SocialInsight::where('metric', 'total_interactions')->count());
        $this->assertSame(0, SocialInsight::where('metric', 'reposts')->count());
        // The rest of the same batch is still stored.
        $this->assertSame(42, SocialInsight::where('metric', 'views')->value('value'));
        $this->assertGreaterThan(0, $result['synced']);
    }

    public function test_the_page_shows_engagement_broken_down_by_type(): void
    {
        $client = $this->client();
        $account = $this->connectedAccount($client);

        foreach (['likes' => 40, 'comments' => 5, 'shares' => 3, 'saves' => 2, 'reposts' => 1, 'replies' => 0] as $metric => $value) {
            SocialInsight::record([
                'social_account_id' => $account->id,
                'metric' => $metric,
                'metric_type' => SocialInsight::TYPE_TOTAL_VALUE,
                'value' => $value,
                'period' => 'day',
                'period_start' => now()->toDateString(),
            ]);
        }

        $response = $this->actingAs($this->staff())->get(route('instagram.insights', $client))->assertOk();

        // Every component that actually has something to show.
        foreach (['Likes' => '40', 'Comments' => '5', 'Shares' => '3', 'Saves' => '2', 'Reposts' => '1'] as $label => $value) {
            $response->assertSee($label);
            $response->assertSee($value);
        }
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

    // -- Retrying after a transient failure ---------------------------------

    public function test_a_transient_error_status_does_not_permanently_exclude_the_account_from_sync(): void
    {
        // Found in production: a bug on our side (since fixed) crashed one
        // account's sync partway through, which set STATUS_ERROR. The next
        // sync run silently skipped it forever, because scopeConnected()
        // used to require exactly STATUS_CONNECTED. A retry loop that only
        // retries once is not a retry loop -- ERROR must still be tried.
        InstagramSetting::current()->update(['app_id' => 'x', 'app_secret' => 'y']);

        $account = $this->connectedAccount(platformUserId: 'was-broken-now-fine');
        $account->recordFailure('A transient problem, since resolved.', fatal: false);

        $this->assertSame(SocialAccount::STATUS_ERROR, $account->fresh()->status);

        Http::fake(['graph.instagram.com/*/was-broken-now-fine/*' => Http::response(['data' => []])]);

        $this->artisan('instagram:sync')->assertExitCode(0);

        $fresh = $account->fresh();
        $this->assertSame(SocialAccount::STATUS_CONNECTED, $fresh->status);
        $this->assertNull($fresh->last_error);
        $this->assertNotNull($fresh->last_synced_at);
    }

    public function test_a_revoked_account_is_never_synced(): void
    {
        InstagramSetting::current()->update(['app_id' => 'x', 'app_secret' => 'y']);

        $account = $this->connectedAccount(platformUserId: 'genuinely-revoked');
        // disconnect() and the deauthorize webhook both null the token when
        // they revoke -- reproduced directly here rather than through either
        // of those flows, since only the resulting state matters to this test.
        $account->forceFill(['access_token' => null, 'status' => SocialAccount::STATUS_REVOKED])->save();

        Http::fake(); // Any call at all fails the test.

        $this->artisan('instagram:sync');

        Http::assertNothingSent();
    }

    // -- The sync throttle ----------------------------------------------------

    public function test_syncing_twice_in_a_row_is_refused_by_the_throttle(): void
    {
        $client = $this->client();
        $account = $this->connectedAccount($client);
        $account->forceFill(['last_synced_at' => now()->subMinutes(2)])->save();
        // Default throttle is 15 minutes; 2 minutes ago is still inside it.

        Http::fake(); // A second real sync call fails the test.

        $this->actingAs($this->staff(['view', 'edit']))
            ->post(route('instagram.insights.sync', $client))
            ->assertSessionHas('status', fn (string $s) => str_contains($s, 'too recently'));

        Http::assertNothingSent();
    }

    public function test_the_first_ever_settings_row_already_has_the_default_throttle(): void
    {
        // Regression: InstagramSetting::current()'s forceCreate() used to
        // return an in-memory model missing sync_throttle_minutes on the
        // very first call in a fresh install (the DB applied its column
        // default of 15, but the object handed back did not carry it),
        // which made addMinutes(null) silently add nothing to the throttle
        // for that one call. Asserted directly against a brand new row,
        // not through canSyncNow(), so a regression here fails on the cause
        // rather than on a timing-sensitive symptom.
        $this->assertSame(15, \App\Models\InstagramSetting::current()->sync_throttle_minutes);
    }

    public function test_syncing_is_allowed_again_once_the_throttle_window_passes(): void
    {
        $client = $this->client();
        $account = $this->connectedAccount($client);
        $account->forceFill(['last_synced_at' => now()->subMinutes(20)])->save();
        // Default throttle is 15 minutes; 20 minutes ago is outside it.

        Http::fake(['graph.instagram.com/*' => Http::response(['data' => []])]);

        $this->actingAs($this->staff(['view', 'edit']))
            ->post(route('instagram.insights.sync', $client))
            ->assertSessionHas('status', fn (string $s) => str_contains($s, 'Synced'));
    }

    public function test_a_never_synced_account_is_never_throttled(): void
    {
        $account = $this->connectedAccount();

        $this->assertTrue($account->canSyncNow());
    }

    public function test_the_throttle_interval_is_configurable(): void
    {
        InstagramSetting::current()->update(['sync_throttle_minutes' => 5]);

        $client = $this->client();
        $account = $this->connectedAccount($client);
        $account->forceFill(['last_synced_at' => now()->subMinutes(6)])->save();

        // 6 minutes ago is inside a 15-minute default throttle but outside a
        // 5-minute one -- proving the interval is actually read from settings
        // rather than hardcoded.
        $this->assertTrue($account->canSyncNow());
    }

    public function test_the_command_skips_a_throttled_account_but_force_overrides_it(): void
    {
        InstagramSetting::current()->update(['app_id' => 'x', 'app_secret' => 'y']);

        $account = $this->connectedAccount(platformUserId: 'recently-synced');
        $account->forceFill(['last_synced_at' => now()->subMinutes(2)])->save();

        Http::fake(); // Skipped means no call at all.

        $this->artisan('instagram:sync')->assertExitCode(0);
        Http::assertNothingSent();

        Http::fake(['graph.instagram.com/*/recently-synced/*' => Http::response(['data' => []])]);

        $this->artisan('instagram:sync --force')->assertExitCode(0);
        // --force reaches the account this time.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'recently-synced'));
    }

    // -- Content performance filtered by the selected range -----------------

    public function test_content_performance_only_shows_media_posted_within_the_range(): void
    {
        $client = $this->client();
        $account = $this->connectedAccount($client);

        $inRange = SocialMediaItem::create([
            'social_account_id' => $account->id, 'platform_media_id' => 'in-range',
            'media_type' => 'IMAGE', 'caption' => 'Inside the window',
            'posted_at' => now()->subDays(3), 'cached_at' => now(),
        ]);
        $outOfRange = SocialMediaItem::create([
            'social_account_id' => $account->id, 'platform_media_id' => 'out-of-range',
            'media_type' => 'IMAGE', 'caption' => 'Three weeks ago',
            'posted_at' => now()->subDays(21), 'cached_at' => now(),
        ]);

        // The bug as reported: the table ignored the range entirely and
        // always showed the most recent items regardless of what was picked.
        $response = $this->actingAs($this->staff())
            ->get(route('instagram.insights', $client).'?range=7d')
            ->assertOk();

        $response->assertSee('Inside the window');
        $response->assertDontSee('Three weeks ago');
    }
}
