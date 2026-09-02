<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ContentAccount;
use App\Models\ContentAccountVenture;
use App\Models\ContentItem;
use App\Models\Invoice;
use App\Models\MonthlyReportNote;
use App\Models\Shoot;
use App\Models\ShootCrew;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\WhatsappSetting;
use App\Notifications\DailyDigestReady;
use App\Notifications\ShootReminderDue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The three scheduled automations: reports:notify-ready, shoots:send-
 * reminders, digest:send-daily. Each is idempotent by design (a column or
 * a day-boundary guard, not "hope the scheduler only fires once") because
 * a scheduler catch-up or a manual re-run must not re-alert anybody.
 */
class StudioAutomationsTest extends TestCase
{
    use RefreshDatabase;

    private function client(array $overrides = []): Client
    {
        return Client::create(array_merge(['name' => 'SVA Silks and Readymades'], $overrides));
    }

    private function configuredWhatsapp(): void
    {
        WhatsappSetting::current()->update([
            'access_token' => 'EAAG-test-token',
            'phone_number_id' => '556677889900',
        ]);
    }

    // -- reports:notify-ready ---------------------------------------------------

    private function connectedInstagram(Client $client): SocialAccount
    {
        $account = SocialAccount::create([
            'client_id' => $client->id,
            'platform' => SocialAccount::PLATFORM_INSTAGRAM,
            'platform_user_id' => (string) random_int(100000000, 999999999),
            'username' => 'client_'.$client->id,
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);

        $account->forceFill(['access_token' => 'IGQV-token', 'connected_at' => now()])->save();

        return $account->fresh();
    }

    public function test_a_client_with_published_work_last_month_is_notified_once(): void
    {
        $this->configuredWhatsapp();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TEST']]])]);

        $client = $this->client(['phone' => '9876543210']);
        $this->connectedInstagram($client);
        ContentItem::create([
            'source' => 'reel', 'notion_page_id' => 'p1', 'title' => 'Reel',
            'venture' => $client->name, 'status' => 'Published',
            'published_date' => now()->subMonthNoOverflow()->startOfMonth()->addDays(3)->toDateString(),
        ]);

        $this->artisan('reports:notify-ready')->assertExitCode(0);

        Http::assertSent(fn ($request) => $request->data()['type'] === 'template'
            && $request->data()['template']['name'] === MonthlyReportNote::WHATSAPP_TEMPLATE);

        $note = MonthlyReportNote::forClientAndMonth($client, now()->subMonthNoOverflow());
        $this->assertNotNull($note->ready_notified_at);

        // Running it again the same day must not send a second message.
        $this->artisan('reports:notify-ready');
        Http::assertSentCount(1);
    }

    public function test_a_client_with_nothing_published_last_month_is_not_notified(): void
    {
        Http::fake();

        $client = $this->client(['phone' => '9876543210']);
        $this->connectedInstagram($client);
        // No ContentItem at all for last month.

        $this->artisan('reports:notify-ready');

        Http::assertNothingSent();
    }

    public function test_a_client_with_no_phone_on_file_is_skipped(): void
    {
        Http::fake();

        $client = $this->client(['phone' => null]);
        $this->connectedInstagram($client);
        ContentItem::create([
            'source' => 'reel', 'notion_page_id' => 'p1', 'title' => 'Reel',
            'venture' => $client->name, 'status' => 'Published',
            'published_date' => now()->subMonthNoOverflow()->startOfMonth()->addDays(3)->toDateString(),
        ]);

        $this->artisan('reports:notify-ready');

        Http::assertNothingSent();
    }

    // -- shoots:send-reminders -------------------------------------------------

    public function test_crew_on_a_shoot_tomorrow_are_reminded(): void
    {
        Notification::fake();

        $client = $this->client();
        $crewMember = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $shoot = Shoot::create([
            'title' => 'Diwali campaign', 'client_id' => $client->id,
            'starts_at' => now()->addDay(), 'status' => Shoot::STATUS_CONFIRMED,
        ]);
        $crew = ShootCrew::create(['shoot_id' => $shoot->id, 'user_id' => $crewMember->id, 'role' => 'Camera']);

        $this->artisan('shoots:send-reminders')->assertExitCode(0);

        Notification::assertSentTo($crewMember, ShootReminderDue::class);
        $this->assertNotNull($shoot->fresh()->reminder_sent_at);
    }

    public function test_a_shoot_reminder_is_never_sent_twice(): void
    {
        $client = $this->client();
        $crewMember = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $shoot = Shoot::create([
            'title' => 'Diwali campaign', 'client_id' => $client->id,
            'starts_at' => now()->addDay(), 'status' => Shoot::STATUS_CONFIRMED,
        ]);
        ShootCrew::create(['shoot_id' => $shoot->id, 'user_id' => $crewMember->id]);

        $this->artisan('shoots:send-reminders');

        Notification::fake();
        $this->artisan('shoots:send-reminders');

        Notification::assertNothingSentTo($crewMember);
    }

    public function test_a_cancelled_shoot_tomorrow_is_never_reminded(): void
    {
        Notification::fake();

        $client = $this->client();
        $crewMember = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $shoot = Shoot::create([
            'title' => 'Called off', 'client_id' => $client->id,
            'starts_at' => now()->addDay(), 'status' => Shoot::STATUS_CANCELLED,
        ]);
        ShootCrew::create(['shoot_id' => $shoot->id, 'user_id' => $crewMember->id]);

        $this->artisan('shoots:send-reminders');

        Notification::assertNothingSentTo($crewMember);
    }

    public function test_a_shoot_further_out_than_tomorrow_is_not_reminded_yet(): void
    {
        Notification::fake();

        $client = $this->client();
        $crewMember = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $shoot = Shoot::create([
            'title' => 'Next week', 'client_id' => $client->id,
            'starts_at' => now()->addDays(5), 'status' => Shoot::STATUS_CONFIRMED,
        ]);
        ShootCrew::create(['shoot_id' => $shoot->id, 'user_id' => $crewMember->id]);

        $this->artisan('shoots:send-reminders');

        Notification::assertNothingSentTo($crewMember);
    }

    // -- digest:send-daily -------------------------------------------------------

    public function test_admins_are_sent_the_daily_digest(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->artisan('digest:send-daily')->assertExitCode(0);

        Notification::assertSentTo($admin, DailyDigestReady::class);
        Notification::assertNothingSentTo($employee);
    }

    public function test_the_digest_counts_overdue_invoices_and_shoots_this_week(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $client = $this->client();
        $issuer = User::factory()->create(['role' => User::ROLE_ADMIN]);

        Invoice::create([
            'invoice_number' => 'INV-1001', 'client_id' => $client->id, 'created_by' => $issuer->id,
            'invoice_date' => today()->subDays(20)->toDateString(), 'due_date' => today()->subDays(5)->toDateString(),
            'subtotal' => 5000, 'total' => 5000, 'status' => Invoice::STATUS_UNPAID,
        ]);

        Shoot::create([
            'title' => 'This week', 'client_id' => $client->id,
            'starts_at' => now()->addDays(2), 'status' => Shoot::STATUS_CONFIRMED,
        ]);

        $this->artisan('digest:send-daily');

        Notification::assertSentTo($admin, function (DailyDigestReady $notification) {
            return $notification->summary['overdueCount'] === 1
                && $notification->summary['shootsThisWeek'] === 1;
        });
    }

    public function test_the_digest_counts_accounts_behind_their_monthly_target(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $client = $this->client();
        $account = ContentAccount::create(['client_id' => $client->id, 'name' => 'Instagram', 'target_reel' => 10]);
        ContentAccountVenture::create(['content_account_id' => $account->id, 'venture' => $client->name]);

        ContentItem::create([
            'source' => 'reel', 'notion_page_id' => 'p1', 'title' => 'Only one',
            'venture' => $client->name, 'status' => 'Published',
            'published_date' => now()->startOfMonth()->addDay()->toDateString(),
        ]);

        $this->artisan('digest:send-daily');

        Notification::assertSentTo($admin, function (DailyDigestReady $notification) {
            return $notification->summary['behindCount'] === 1;
        });
    }
}
