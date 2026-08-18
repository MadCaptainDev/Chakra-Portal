<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\MonthlyReportNote;
use App\Models\Shoot;
use App\Models\SocialAccount;
use App\Models\SocialAudienceSnapshot;
use App\Models\SocialInsight;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\Instagram\InstagramInsights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The monthly Instagram report: the studio screen, its downloadable PDF,
 * the note staff write, and the new audience-demographics sync it reads.
 */
class MonthlyReportTest extends TestCase
{
    use RefreshDatabase;

    private static int $nextPlatformUserId = 17841470000000001;

    private function client(string $name = 'Chakra Production'): Client
    {
        return Client::create(['name' => $name]);
    }

    private function connectedAccount(?Client $client = null): SocialAccount
    {
        $client ??= $this->client();
        $platformUserId = (string) self::$nextPlatformUserId++;

        $account = SocialAccount::create([
            'client_id' => $client->id,
            'platform' => SocialAccount::PLATFORM_INSTAGRAM,
            'platform_user_id' => $platformUserId,
            'username' => 'client_'.$client->id,
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);

        $account->forceFill(['access_token' => 'IGQV-token', 'connected_at' => now()])->save();

        return $account->fresh();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function staff(array $abilities = ['view']): User
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        foreach ($abilities as $ability) {
            UserPermission::create(['user_id' => $user->id, 'module' => 'clients', 'ability' => $ability]);
        }

        return $user->refresh();
    }

    /** The exact shape confirmed empirically against both live connected accounts. */
    private function demographicsResponse(array $dimensionKeys, array $results): array
    {
        return [
            'data' => [[
                'name' => 'follower_demographics',
                'period' => 'lifetime',
                'total_value' => [
                    'breakdowns' => [[
                        'dimension_keys' => $dimensionKeys,
                        'results' => $results,
                    ]],
                ],
            ]],
        ];
    }

    // -- Permission gating ----------------------------------------------------

    public function test_viewing_the_report_needs_only_view(): void
    {
        $client = $this->client();
        $this->connectedAccount($client);

        $this->actingAs($this->staff(['view']))
            ->get(route('instagram.report', $client))
            ->assertOk();
    }

    public function test_a_guest_reaches_none_of_it(): void
    {
        $client = $this->client();

        $this->get(route('instagram.report', $client))->assertRedirect(route('login'));
        $this->post(route('instagram.report.note', $client))->assertRedirect(route('login'));
        $this->get(route('instagram.report.pdf', $client))->assertRedirect(route('login'));
    }

    public function test_saving_the_note_needs_edit_not_just_view(): void
    {
        $client = $this->client();
        $this->connectedAccount($client);

        $this->actingAs($this->staff(['view']))
            ->post(route('instagram.report.note', $client), ['month' => now()->format('Y-m'), 'note' => 'x'])
            ->assertForbidden();
    }

    // -- The note --------------------------------------------------------------

    public function test_saving_a_note_persists_it_and_records_who_saved_it(): void
    {
        $client = $this->client();
        $this->connectedAccount($client);
        $staff = $this->staff(['view', 'edit']);
        $month = now()->subMonthNoOverflow();

        $this->actingAs($staff)->post(route('instagram.report.note', $client), [
            'month' => $month->format('Y-m'),
            'note' => 'July was carried by two reels.',
        ])->assertRedirect();

        $note = MonthlyReportNote::where('client_id', $client->id)->firstOrFail();
        $this->assertSame('July was carried by two reels.', $note->note);
        $this->assertSame($staff->id, $note->updated_by_id);
        $this->assertSame($month->startOfMonth()->toDateString(), $note->month->toDateString());
    }

    public function test_a_blank_note_is_stored_as_null_not_an_empty_string(): void
    {
        $client = $this->client();
        $this->connectedAccount($client);

        $this->actingAs($this->staff(['view', 'edit']))->post(route('instagram.report.note', $client), [
            'month' => now()->format('Y-m'),
            'note' => '',
        ]);

        $this->assertNull(MonthlyReportNote::where('client_id', $client->id)->firstOrFail()->note);
    }

    public function test_notes_for_different_months_do_not_overwrite_each_other(): void
    {
        $client = $this->client();
        $this->connectedAccount($client);
        $staff = $this->staff(['view', 'edit']);

        $this->actingAs($staff)->post(route('instagram.report.note', $client), ['month' => '2026-06', 'note' => 'June note']);
        $this->actingAs($staff)->post(route('instagram.report.note', $client), ['month' => '2026-07', 'note' => 'July note']);

        $this->assertCount(2, MonthlyReportNote::where('client_id', $client->id)->get());
        $this->assertSame('June note', MonthlyReportNote::where('client_id', $client->id)->whereDate('month', '2026-06-01')->value('note'));
        $this->assertSame('July note', MonthlyReportNote::where('client_id', $client->id)->whereDate('month', '2026-07-01')->value('note'));
    }

    public function test_saving_a_note_twice_for_the_same_month_updates_the_row_rather_than_duplicating_it(): void
    {
        // The bug this guards: forClientAndMonth() used to look a row up
        // with a bare "2026-07-01" string, which never matches what
        // Eloquent's date cast actually WRITES ("2026-07-01 00:00:00"), so
        // the lookup found nothing and silently inserted a fresh duplicate
        // row on every save for a month that already had one.
        $client = $this->client();
        $this->connectedAccount($client);
        $staff = $this->staff(['view', 'edit']);

        $this->actingAs($staff)->post(route('instagram.report.note', $client), ['month' => '2026-07', 'note' => 'First draft']);
        $this->actingAs($staff)->post(route('instagram.report.note', $client), ['month' => '2026-07', 'note' => 'Final version']);

        $rows = MonthlyReportNote::where('client_id', $client->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('Final version', $rows->first()->note);
    }

    public function test_a_february_month_parses_correctly_even_requested_on_the_31st(): void
    {
        // The bug this guards: Carbon::createFromFormat('Y-m', '2026-02')
        // inherits TODAY's day of month, which overflows into March on a
        // 31st -- and startOfMonth() on an already-overflowed date gives
        // the wrong month entirely. Parsing "Y-m-d" with an explicit "-01"
        // avoids the overflow before it happens.
        $this->travelTo(now()->setDate(2026, 1, 31));

        $client = $this->client();
        $this->connectedAccount($client);

        $this->actingAs($this->staff(['view', 'edit']))->post(route('instagram.report.note', $client), [
            'month' => '2026-02',
            'note' => 'February note',
        ]);

        $note = MonthlyReportNote::where('client_id', $client->id)->firstOrFail();
        $this->assertSame('2026-02-01', $note->month->toDateString());
    }

    // -- The PDF -----------------------------------------------------------------

    public function test_the_pdf_downloads_as_a_pdf_with_the_clients_real_numbers(): void
    {
        $client = $this->client();
        $account = $this->connectedAccount($client);
        $day = now()->subMonthNoOverflow()->startOfMonth()->addDays(5);

        SocialInsight::record([
            'social_account_id' => $account->id, 'metric' => 'reach', 'metric_type' => SocialInsight::TYPE_TIME_SERIES,
            'value' => 1234, 'period' => 'day', 'period_start' => $day->toDateString(),
        ]);

        $response = $this->actingAs($this->staff(['view']))->get(route('instagram.report.pdf', $client));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_the_pdf_route_404s_when_nothing_is_connected(): void
    {
        $client = $this->client();

        $this->actingAs($this->staff(['view']))
            ->get(route('instagram.report.pdf', $client))
            ->assertNotFound();
    }

    // -- The audience sync ----------------------------------------------------

    public function test_syncing_audience_stores_both_dimension_snapshots(): void
    {
        $account = $this->connectedAccount();

        Http::fake([
            'graph.instagram.com/*/insights*breakdown=age%2Cgender*' => Http::response($this->demographicsResponse(
                ['age', 'gender'],
                [['dimension_values' => ['25-34', 'F'], 'value' => 20], ['dimension_values' => ['18-24', 'M'], 'value' => 13]],
            )),
            'graph.instagram.com/*/insights*breakdown=city*' => Http::response($this->demographicsResponse(
                ['city'],
                [['dimension_values' => ['Kochi, Kerala'], 'value' => 51]],
            )),
        ]);

        $result = InstagramInsights::make()->syncAudience($account);

        $this->assertSame(2, $result['synced']);
        $this->assertSame([], $result['skipped']);

        $ageGender = SocialAudienceSnapshot::where('social_account_id', $account->id)
            ->dimension(SocialAudienceSnapshot::DIMENSION_AGE_GENDER)->firstOrFail();
        $this->assertCount(2, $ageGender->data);

        $city = SocialAudienceSnapshot::where('social_account_id', $account->id)
            ->dimension(SocialAudienceSnapshot::DIMENSION_CITY)->firstOrFail();
        $this->assertSame('Kochi, Kerala', $city->data[0]['dimension_values'][0]);
    }

    public function test_a_failed_dimension_does_not_block_the_other_or_crash_the_sync(): void
    {
        $account = $this->connectedAccount();

        Http::fake([
            'graph.instagram.com/*/insights*breakdown=age%2Cgender*' => Http::response(['error' => [
                'message' => 'Unsupported request', 'type' => 'GraphMethodException', 'code' => 100,
            ]], 400),
            'graph.instagram.com/*/insights*breakdown=city*' => Http::response($this->demographicsResponse(
                ['city'],
                [['dimension_values' => ['Kochi, Kerala'], 'value' => 51]],
            )),
        ]);

        $result = InstagramInsights::make()->syncAudience($account);

        $this->assertSame(1, $result['synced']);
        $this->assertSame(['follower_demographics:age_gender'], $result['skipped']);
        $this->assertSame(1, SocialAudienceSnapshot::where('social_account_id', $account->id)->count());
    }

    public function test_a_re_sync_overwrites_the_snapshot_rather_than_duplicating_it(): void
    {
        $account = $this->connectedAccount();

        SocialAudienceSnapshot::create([
            'social_account_id' => $account->id,
            'dimension' => SocialAudienceSnapshot::DIMENSION_CITY,
            'data' => [['dimension_values' => ['Old City'], 'value' => 1]],
            'fetched_at' => now()->subDays(30),
        ]);

        Http::fake([
            'graph.instagram.com/*/insights*breakdown=age%2Cgender*' => Http::response($this->demographicsResponse(['age', 'gender'], [])),
            'graph.instagram.com/*/insights*breakdown=city*' => Http::response($this->demographicsResponse(
                ['city'],
                [['dimension_values' => ['New City'], 'value' => 99]],
            )),
        ]);

        InstagramInsights::make()->syncAudience($account);

        $this->assertSame(1, SocialAudienceSnapshot::where('social_account_id', $account->id)
            ->where('dimension', SocialAudienceSnapshot::DIMENSION_CITY)->count());
        $this->assertSame('New City', SocialAudienceSnapshot::where('social_account_id', $account->id)
            ->where('dimension', SocialAudienceSnapshot::DIMENSION_CITY)->firstOrFail()->data[0]['dimension_values'][0]);
    }

    // -- SocialAudienceSnapshot's own collapsing math ---------------------------

    public function test_age_breakdown_collapses_the_joint_distribution_to_percentages(): void
    {
        $account = $this->connectedAccount();
        $snapshot = SocialAudienceSnapshot::create([
            'social_account_id' => $account->id,
            'dimension' => SocialAudienceSnapshot::DIMENSION_AGE_GENDER,
            'data' => [
                ['dimension_values' => ['25-34', 'F'], 'value' => 30],
                ['dimension_values' => ['25-34', 'M'], 'value' => 20],
                ['dimension_values' => ['18-24', 'F'], 'value' => 50],
            ],
            'fetched_at' => now(),
        ]);

        $age = collect($snapshot->ageBreakdown())->keyBy('label');
        $this->assertSame(50, $age['25-34']['value']);
        $this->assertSame(50, $age['18-24']['value']);

        $gender = collect($snapshot->genderBreakdown())->keyBy('label');
        $this->assertSame(80, $gender['Women']['value']);
        $this->assertSame(20, $gender['Men']['value']);
    }

    public function test_top_cities_are_ranked_by_raw_follower_count_not_percentage(): void
    {
        $account = $this->connectedAccount();
        $snapshot = SocialAudienceSnapshot::create([
            'social_account_id' => $account->id,
            'dimension' => SocialAudienceSnapshot::DIMENSION_CITY,
            'data' => [
                ['dimension_values' => ['Small Town'], 'value' => 2],
                ['dimension_values' => ['Kochi, Kerala'], 'value' => 51],
            ],
            'fetched_at' => now(),
        ]);

        $top = $snapshot->topCities();
        $this->assertSame('Kochi, Kerala', $top[0]['label']);
        $this->assertSame(51, $top[0]['value']);
    }

    // -- The report screen's content ---------------------------------------------

    public function test_the_report_shows_kpis_and_top_posts_for_the_selected_month(): void
    {
        $client = $this->client();
        $account = $this->connectedAccount($client);
        $day = now()->subMonthNoOverflow()->startOfMonth()->addDays(3);

        SocialInsight::record([
            'social_account_id' => $account->id, 'metric' => 'reach', 'metric_type' => SocialInsight::TYPE_TIME_SERIES,
            'value' => 9999, 'period' => 'day', 'period_start' => $day->toDateString(),
        ]);

        $response = $this->actingAs($this->staff(['view']))->get(route('instagram.report', $client));

        $response->assertOk();
        $response->assertSee('9,999');
    }

    public function test_the_report_shows_a_graceful_empty_state_when_audience_has_never_synced(): void
    {
        $client = $this->client();
        $this->connectedAccount($client);

        $this->actingAs($this->staff(['view']))
            ->get(route('instagram.report', $client))
            ->assertOk()
            ->assertSee("haven't been synced");
    }

    public function test_the_report_shows_shoots_scheduled_that_month(): void
    {
        $client = $this->client();
        $this->connectedAccount($client);
        $day = now()->subMonthNoOverflow()->startOfMonth()->addDays(10);

        Shoot::create([
            'title' => 'Recipe carousel batch', 'client_id' => $client->id,
            'starts_at' => $day, 'ends_at' => $day->copy()->addHours(3),
            'location' => 'Studio', 'status' => Shoot::STATUS_COMPLETED,
        ]);

        $this->actingAs($this->staff(['view']))
            ->get(route('instagram.report', $client))
            ->assertOk()
            ->assertSee('Recipe carousel batch');
    }

    public function test_the_report_still_works_with_nothing_connected(): void
    {
        $client = $this->client();

        $this->actingAs($this->staff(['view']))
            ->get(route('instagram.report', $client))
            ->assertOk()
            ->assertSee('No Instagram account is connected');
    }

    // -- Month resolution ----------------------------------------------------

    public function test_month_resolution_defaults_to_the_previous_calendar_month(): void
    {
        $client = $this->client();
        $this->connectedAccount($client);
        $expected = now()->subMonthNoOverflow()->format('F Y');

        $this->actingAs($this->staff(['view']))
            ->get(route('instagram.report', $client))
            ->assertOk()
            ->assertSee($expected);
    }

    public function test_an_explicit_month_query_param_is_honored(): void
    {
        $client = $this->client();
        $this->connectedAccount($client);

        $this->actingAs($this->staff(['view']))
            ->get(route('instagram.report', ['client' => $client, 'month' => '2026-03']))
            ->assertOk()
            ->assertSee('March 2026');
    }
}
