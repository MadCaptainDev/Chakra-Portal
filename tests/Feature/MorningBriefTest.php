<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Shoot;
use App\Models\User;
use App\Models\WhatsappSetting;
use App\Models\WhatsappWebhookEvent;
use App\Support\AdminPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The morning brief: the studio's day on the owner's phone before anybody
 * asks for it.
 *
 * Two things worth testing separately -- what it says, and who it reaches.
 * The second is the one with a rule in it: Meta refuses free-form text to
 * anybody who has not written in a day, so a brief sent at half seven to a
 * number outside its window is a brief nobody gets and an error in the log.
 */
class MorningBriefTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        WhatsappSetting::current()->update([
            'access_token' => 'EAAG-test-token',
            'phone_number_id' => '556677889900',
        ]);

        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.test']]], 200)]);
    }

    /** No Shoot factory in this project, so one is built by hand. */
    private function shoot(string $title): Shoot
    {
        return Shoot::create([
            'title' => $title,
            'starts_at' => today()->setTime(11, 15),
            'status' => Shoot::STATUS_PLANNED,
        ]);
    }

    private function admin(string $phone = '7094126823'): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN, 'phone' => $phone]);
    }

    /** Their window is open only if they have written within the day. */
    private function theyWroteRecently(string $waId = '917094126823', ?string $at = null): void
    {
        WhatsappWebhookEvent::create([
            'object' => 'whatsapp_business_account',
            'field' => 'messages',
            'type' => WhatsappWebhookEvent::TYPE_MESSAGE,
            'dedupe_key' => hash('sha256', uniqid('', true)),
            'wa_id' => $waId,
            'message_type' => 'text',
            'summary' => 'hello',
            'payload' => [],
            'occurred_at' => $at ? now()->parse($at) : now()->subHour(),
            'received_at' => now(),
        ]);
    }

    private function sent(): array
    {
        return collect(Http::recorded())
            ->map(fn (array $pair) => (string) data_get($pair[0]->data(), 'text.body'))
            ->filter()
            ->values()
            ->all();
    }

    // ——— What it says ———

    public function test_the_brief_leads_with_the_day_and_what_is_shooting(): void
    {
        $this->shoot('Zira Shoot');

        $brief = AdminPortal::brief();

        $this->assertStringContainsString(now()->format('l j F'), $brief);
        $this->assertStringContainsString('Zira Shoot', $brief);
    }

    public function test_a_shoot_with_nobody_on_it_is_called_out(): void
    {
        $this->shoot('Zira Shoot');

        // The one thing in a morning brief worth acting on before coffee.
        $this->assertStringContainsString('nobody crewed', AdminPortal::brief());
    }

    public function test_the_money_lines_separate_outstanding_from_overdue(): void
    {
        $client = Client::factory()->create();
        $paid = Invoice::factory()->create(['client_id' => $client->id, 'total' => 50000, 'status' => Invoice::STATUS_PAID]);
        $paid->payments()->create(['amount' => 50000, 'paid_on' => today(), 'recorded_by' => $this->admin()->id]);
        Invoice::factory()->create([
            'client_id' => $client->id, 'total' => 40000,
            'status' => Invoice::STATUS_UNPAID, 'due_date' => today()->subDays(9),
        ]);

        $brief = AdminPortal::brief();

        $this->assertStringContainsString('Collected this month: ₹50,000', $brief);
        $this->assertStringContainsString('₹40,000 is overdue', $brief);
    }

    public function test_a_quiet_day_still_reads_as_a_brief(): void
    {
        $brief = AdminPortal::brief();

        // Three lines on a quiet day is what keeps it being read. It must not
        // say "nothing" four times over.
        $this->assertStringContainsString('Nothing shooting today.', $brief);
        $this->assertStringNotContainsString('Did not log', $brief);
    }

    // ——— Who it reaches ———

    public function test_an_admin_inside_the_window_gets_it(): void
    {
        $this->admin();
        $this->theyWroteRecently();

        $this->artisan('whatsapp:morning-brief')->assertSuccessful();

        $this->assertCount(1, $this->sent());
        $this->assertStringContainsString(now()->format('l j F'), $this->sent()[0]);
    }

    public function test_an_admin_outside_the_window_is_skipped_rather_than_failed(): void
    {
        $this->admin();
        $this->theyWroteRecently(at: now()->subDays(3)->toDateTimeString());

        $this->artisan('whatsapp:morning-brief')
            ->expectsOutputToContain('outside the 24-hour window')
            ->assertSuccessful();

        // Meta would refuse this before it left. Attempting it buys a log
        // line and teaches nobody anything.
        $this->assertSame([], $this->sent());
    }

    public function test_employees_are_not_sent_the_studios_money(): void
    {
        User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'phone' => '9000000002']);
        $this->theyWroteRecently('919000000002');

        $this->artisan('whatsapp:morning-brief')->assertSuccessful();

        $this->assertSame([], $this->sent());
    }

    public function test_an_admin_with_no_phone_number_is_simply_passed_over(): void
    {
        User::factory()->create(['role' => User::ROLE_ADMIN, 'phone' => null]);

        $this->artisan('whatsapp:morning-brief')->assertSuccessful();

        $this->assertSame([], $this->sent());
    }

    public function test_the_dry_run_sends_nothing(): void
    {
        $this->admin();
        $this->theyWroteRecently();

        $this->artisan('whatsapp:morning-brief --dry')->assertSuccessful();

        $this->assertSame([], $this->sent());
    }
}
