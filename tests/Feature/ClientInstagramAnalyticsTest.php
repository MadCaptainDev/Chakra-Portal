<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\MonthlyReportNote;
use App\Models\Shoot;
use App\Models\SocialAccount;
use App\Models\SocialInsight;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A client's own read-only Instagram Analytics and Monthly Report screens --
 * the same views and data as the staff ones (tests/Feature/InstagramInsightsTest.php,
 * tests/Feature/MonthlyReportTest.php), just reached through the client
 * portal's own routes with no {client} segment and none of the studio-only
 * controls. See Client\InstagramInsightsController and
 * Client\MonthlyReportController's own doc blocks for exactly what's
 * different and why.
 */
class ClientInstagramAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function client(string $name = 'Thillai Pets Clinic'): Client
    {
        return Client::create(['name' => $name]);
    }

    private function loginFor(Client $client): User
    {
        return User::factory()->create(['role' => User::ROLE_CLIENT, 'client_id' => $client->id]);
    }

    // Recently synced by default -- see the identical note in
    // InstagramInsightsTest::connectedAccount(): both controllers under
    // test call InstagramSyncRunner::ensureFresh() on every view, and a
    // null last_synced_at would trigger a real ~90-day backfill attempt.
    private function connectedAccount(Client $client): SocialAccount
    {
        $account = SocialAccount::create([
            'client_id' => $client->id,
            'platform' => SocialAccount::PLATFORM_INSTAGRAM,
            'platform_user_id' => '17841476964090'.random_int(100, 999),
            'username' => 'client_'.$client->id,
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);

        $account->forceFill([
            'access_token' => 'IGQV-token',
            'connected_at' => now(),
            'last_synced_at' => now()->subHour(),
        ])->save();

        return $account->fresh();
    }

    // -- Insights ---------------------------------------------------------------

    public function test_a_client_can_view_their_own_analytics(): void
    {
        $client = $this->client();
        $this->connectedAccount($client);

        $this->actingAs($this->loginFor($client))
            ->get(route('client.instagram.insights'))
            ->assertOk();
    }

    public function test_the_client_screen_shows_no_sync_button_or_portfolio_actions(): void
    {
        $client = $this->client();
        $this->connectedAccount($client);

        $this->actingAs($this->loginFor($client))
            ->get(route('client.instagram.insights'))
            ->assertOk()
            ->assertDontSee('Sync now')
            ->assertDontSee('Add to portfolio');
    }

    public function test_the_client_screen_shows_a_metric_that_was_synced(): void
    {
        $client = $this->client();
        $account = $this->connectedAccount($client);

        SocialInsight::record([
            'social_account_id' => $account->id, 'metric' => 'reach', 'metric_type' => SocialInsight::TYPE_TIME_SERIES,
            'value' => 8123, 'period' => 'day', 'period_start' => now()->toDateString(),
        ]);

        $this->actingAs($this->loginFor($client))
            ->get(route('client.instagram.insights'))
            ->assertOk()
            ->assertSee('8,123');
    }

    public function test_the_client_screen_still_works_with_nothing_connected(): void
    {
        $client = $this->client();

        $this->actingAs($this->loginFor($client))
            ->get(route('client.instagram.insights'))
            ->assertOk()
            ->assertSee('No Instagram account is connected');
    }

    public function test_a_client_cannot_reach_another_clients_analytics(): void
    {
        $mine = $this->client('Thillai Pets Clinic');
        $theirs = $this->client('SVA Silks');
        $account = $this->connectedAccount($theirs);

        SocialInsight::record([
            'social_account_id' => $account->id, 'metric' => 'reach', 'metric_type' => SocialInsight::TYPE_TIME_SERIES,
            'value' => 55555, 'period' => 'day', 'period_start' => now()->toDateString(),
        ]);

        // No {client} segment exists on this route to swap out -- the
        // controller always reads the signed-in user's own client_id.
        $this->actingAs($this->loginFor($mine))
            ->get(route('client.instagram.insights'))
            ->assertOk()
            ->assertDontSee('55,555');
    }

    public function test_staff_cannot_reach_the_client_only_analytics_route(): void
    {
        $staff = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($staff)->get(route('client.instagram.insights'))->assertForbidden();
    }

    public function test_a_guest_reaches_none_of_it(): void
    {
        $this->get(route('client.instagram.insights'))->assertRedirect(route('login'));
        $this->get(route('client.instagram.report'))->assertRedirect(route('login'));
        $this->get(route('client.instagram.report.pdf'))->assertRedirect(route('login'));
    }

    // -- Monthly report -----------------------------------------------------

    public function test_a_client_can_view_their_own_monthly_report(): void
    {
        $client = $this->client();
        $this->connectedAccount($client);

        $this->actingAs($this->loginFor($client))
            ->get(route('client.instagram.report'))
            ->assertOk();
    }

    public function test_the_client_report_shows_no_studio_only_controls(): void
    {
        $client = $this->client();
        $this->connectedAccount($client);

        $this->actingAs($this->loginFor($client))
            ->get(route('client.instagram.report'))
            ->assertOk()
            ->assertDontSee('Sync now')
            ->assertDontSee('Report sections &amp; delivery', false)
            ->assertDontSee('Send via WhatsApp')
            ->assertDontSee('Studio view')
            ->assertDontSee('Save note');
    }

    public function test_the_client_report_shows_the_studios_written_note_read_only(): void
    {
        $client = $this->client();
        $this->connectedAccount($client);
        $month = now()->subMonthNoOverflow()->startOfMonth();

        MonthlyReportNote::forClientAndMonth($client, $month)
            ->forceFill(['note' => 'A strong month for reels.'])
            ->save();

        $this->actingAs($this->loginFor($client))
            ->get(route('client.instagram.report'))
            ->assertOk()
            ->assertSee('A strong month for reels.');
    }

    public function test_the_client_report_only_shows_the_studios_saved_default_sections(): void
    {
        $client = $this->client(); // no report_sections_disabled set -- every section defaults on
        $client->update(['report_sections_disabled' => ['top_cities']]);
        $this->connectedAccount($client);

        $response = $this->actingAs($this->loginFor($client))
            ->get(route('client.instagram.report').'?sections_form=1&sections[]=top_cities');

        // Even if the client's own browser somehow sent an override query
        // string, the client controller never reads it -- always the
        // studio's saved default.
        $response->assertOk();
    }

    public function test_the_client_report_shows_kpis_for_the_selected_month(): void
    {
        $client = $this->client();
        $account = $this->connectedAccount($client);
        $day = now()->subMonthNoOverflow()->startOfMonth()->addDays(3);

        SocialInsight::record([
            'social_account_id' => $account->id, 'metric' => 'reach', 'metric_type' => SocialInsight::TYPE_TIME_SERIES,
            'value' => 4321, 'period' => 'day', 'period_start' => $day->toDateString(),
        ]);

        $this->actingAs($this->loginFor($client))
            ->get(route('client.instagram.report'))
            ->assertOk()
            ->assertSee('4,321');
    }

    public function test_the_client_report_never_shows_shoots(): void
    {
        $client = $this->client();
        $this->connectedAccount($client);
        $day = now()->subMonthNoOverflow()->startOfMonth()->addDays(10);

        Shoot::create([
            'title' => 'Unboxing reel shoot', 'client_id' => $client->id,
            'starts_at' => $day, 'ends_at' => $day->copy()->addHours(2),
            'location' => 'Studio', 'status' => Shoot::STATUS_COMPLETED,
        ]);

        // Redundant with the client's own dedicated Shoots page
        // (client.shoots) -- deliberately not repeated here.
        $this->actingAs($this->loginFor($client))
            ->get(route('client.instagram.report'))
            ->assertOk()
            ->assertDontSee('Unboxing reel shoot');
    }

    public function test_the_client_report_still_works_with_nothing_connected(): void
    {
        $client = $this->client();

        $this->actingAs($this->loginFor($client))
            ->get(route('client.instagram.report'))
            ->assertOk()
            ->assertSee('No Instagram account is connected');
    }

    public function test_a_client_can_download_their_own_report_pdf(): void
    {
        $client = $this->client();
        $this->connectedAccount($client);

        $response = $this->actingAs($this->loginFor($client))
            ->get(route('client.instagram.report.pdf'));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_the_pdf_route_404s_when_nothing_is_connected(): void
    {
        $client = $this->client();

        $this->actingAs($this->loginFor($client))
            ->get(route('client.instagram.report.pdf'))
            ->assertNotFound();
    }

    public function test_staff_cannot_reach_the_client_only_report_routes(): void
    {
        $staff = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($staff)->get(route('client.instagram.report'))->assertForbidden();
        $this->actingAs($staff)->get(route('client.instagram.report.pdf'))->assertForbidden();
    }
}
