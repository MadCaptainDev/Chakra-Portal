<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\SocialAccount;
use App\Models\SocialInsight;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * InstagramSyncRunner::ensureFresh() -- the silent auto-sync that
 * show() calls on both the Insights screen and the Monthly Report before
 * reading any cached data. Exercised here through the Insights screen
 * (InstagramInsightsController::show(), checkWindow: true on a custom
 * range), which is the one caller that reaches both of ensureFresh()'s
 * branches.
 */
class InstagramSyncRunnerTest extends TestCase
{
    use RefreshDatabase;

    private static int $nextPlatformUserId = 17841480000000001;

    private function client(string $name = 'Janet Hospitals'): Client
    {
        return Client::create(['name' => $name]);
    }

    // Mirrors InstagramInsightsTest::connectedAccount() -- see that file's
    // comment for why "recently synced" is the default rather than null.
    private function connectedAccount(?Client $client = null, bool $neverSynced = false): SocialAccount
    {
        $client ??= $this->client();
        $platformUserId = (string) self::$nextPlatformUserId++;

        $account = SocialAccount::create([
            'client_id' => $client->id,
            'platform' => SocialAccount::PLATFORM_INSTAGRAM,
            'platform_user_id' => $platformUserId,
            'username' => 'janethospitaltrichy',
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);

        $account->forceFill([
            'access_token' => 'IGQV-token',
            'connected_at' => now(),
            'last_synced_at' => $neverSynced ? null : now()->subHour(),
        ])->save();

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

    /** total_value answers one number for the whole range asked for. */
    private function totalValue(string $metric, int $value): array
    {
        return ['name' => $metric, 'period' => 'day', 'total_value' => ['value' => $value]];
    }

    /**
     * The fakes every backfill/auto-sync run needs: account time-series,
     * account total_value (one call per day, so matched generically), the
     * two audience-demographics calls, and the media list. Specific
     * patterns first -- follower_demographics and reach both also contain
     * "metric_type=total_value" as a substring of their own query strings,
     * so a generic total_value pattern registered first would swallow them.
     */
    private function fakeAWholeSync(): void
    {
        Http::fake([
            'graph.instagram.com/*/insights*metric=follower_demographics*' => Http::response(['data' => [[
                'name' => 'follower_demographics',
                'total_value' => ['breakdowns' => [['results' => []]]],
            ]]]),
            'graph.instagram.com/*/insights*metric=reach%2Cfollower_count*' => Http::response(['data' => [
                // 91 daily points reaches 90 days back from today -- proof
                // the backfill actually covers the promised window, not
                // just however many days happened to be faked.
                $this->timeSeries('reach', array_fill(0, 91, 500)),
                $this->timeSeries('follower_count', array_fill(0, 91, 1000)),
            ]]),
            'graph.instagram.com/*/insights*metric_type=total_value*' => Http::response(['data' => [
                $this->totalValue('views', 5),
            ]]),
            'graph.instagram.com/*/media?*' => Http::response(['data' => []]),
        ]);
    }

    public function test_the_first_ever_view_of_a_never_synced_account_backfills_at_least_ninety_days(): void
    {
        $client = $this->client();
        $account = $this->connectedAccount($client, neverSynced: true);

        $this->fakeAWholeSync();

        // The plain default range (30 days) -- the backfill promise is not
        // limited to whatever range happens to be on screen when the
        // account is opened for the first time.
        $this->actingAs($this->staff())
            ->get(route('instagram.insights', $client))
            ->assertOk();

        $earliest = SocialInsight::where('social_account_id', $account->id)
            ->where('metric', 'reach')
            ->min('period_start');

        $this->assertNotNull($earliest);
        $this->assertLessThanOrEqual(now()->subDays(85)->toDateString(), $earliest);
        $this->assertNotNull($account->fresh()->last_synced_at);
    }

    public function test_a_concurrent_request_during_the_backfill_does_not_double_sync(): void
    {
        $client = $this->client();
        $account = $this->connectedAccount($client, neverSynced: true);

        // The same lock ensureFresh() itself takes, held as if a first
        // request's backfill were already in flight.
        Cache::put('instagram-auto-sync-'.$account->id, true, now()->addSeconds(120));

        Http::fake(); // Any call at all fails the test.

        $this->actingAs($this->staff())
            ->get(route('instagram.insights', $client))
            ->assertOk();

        Http::assertNothingSent();
        $this->assertNull($account->fresh()->last_synced_at);
    }

    public function test_an_already_synced_account_auto_syncs_an_explicit_range_with_no_cached_data(): void
    {
        $client = $this->client();
        $account = $this->connectedAccount($client);

        $from = now()->subDays(60)->toDateString();
        $to = now()->subDays(53)->toDateString();

        $this->fakeAWholeSync();

        $this->actingAs($this->staff())
            ->get(route('instagram.insights', $client).'?range=custom&from='.$from.'&to='.$to)
            ->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'metric=reach'));
        $this->assertNotNull(
            SocialInsight::where('social_account_id', $account->id)->where('metric', 'reach')->first()
        );
    }

    public function test_a_preset_range_with_no_cached_data_is_not_auto_synced(): void
    {
        // checkWindow is false for every preset range (7d/30d/this month/
        // previous month) -- only the custom picker's explicit "Go" reaches
        // the second ensureFresh() branch. An already-synced account
        // landing on the plain default view must not trigger a surprise
        // API call just because that particular 30-day window has nothing
        // cached yet.
        $client = $this->client();
        $this->connectedAccount($client);

        Http::fake(); // Any call at all fails the test.

        $this->actingAs($this->staff())
            ->get(route('instagram.insights', $client))
            ->assertOk();

        Http::assertNothingSent();
    }

    public function test_a_throttled_account_with_a_missing_range_is_not_auto_synced(): void
    {
        $client = $this->client();
        $account = $this->connectedAccount($client);
        $account->forceFill(['last_synced_at' => now()->subMinutes(2)])->save();
        // Default throttle is 15 minutes; 2 minutes ago is still inside it.

        $from = now()->subDays(60)->toDateString();
        $to = now()->subDays(53)->toDateString();

        Http::fake(); // Any call at all fails the test.

        $this->actingAs($this->staff())
            ->get(route('instagram.insights', $client).'?range=custom&from='.$from.'&to='.$to)
            ->assertOk();

        Http::assertNothingSent();
    }

    public function test_a_failed_auto_sync_does_not_break_page_rendering(): void
    {
        $client = $this->client();
        $account = $this->connectedAccount($client, neverSynced: true);

        Http::fake(['graph.instagram.com/*' => Http::response(
            ['error' => ['message' => 'Invalid OAuth access token', 'code' => 190]], 401
        )]);

        $this->actingAs($this->staff())
            ->get(route('instagram.insights', $client))
            ->assertOk();

        $this->assertNotNull($account->fresh()->last_error);
    }
}
