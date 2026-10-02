<?php

namespace Tests\Feature;

use App\Models\TimesheetEntry;
use App\Models\User;
use App\Models\WhatsappSetting;
use App\Models\WhatsappWebhookEvent;
use App\Support\TimesheetDayReport;
use App\Support\TimesheetVenture;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The 9 PM WhatsApp report: who entered today's timesheet, who did not.
 */
class TimesheetReportTest extends TestCase
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

    public function test_it_splits_the_team_into_entered_with_hours_and_not_entered(): void
    {
        $sanjai = User::factory()->employee()->create(['name' => 'Sanjai']);
        $gokul = User::factory()->employee()->create(['name' => 'Gokul']);
        User::factory()->employee()->create(['name' => 'Nitis']);
        User::factory()->create(['role' => User::ROLE_ADMIN, 'name' => 'Owner']);

        $this->log($sanjai, 300);
        $this->log($sanjai, 150);
        $this->log($gokul, 60);
        // Cancelled work is not having entered the day.
        $this->log(User::where('name', 'Nitis')->first(), 90, TimesheetEntry::STATUS_CANCELLED);
        $this->log($gokul, 600, null, today()->subDay()->toDateString());

        $report = TimesheetDayReport::for(today());

        $this->assertSame(['Sanjai', 'Gokul'], array_column($report->entered, 'name'));
        $this->assertSame(['7h 30m', '1h'], array_column($report->entered, 'label'));
        $this->assertSame(['Nitis'], $report->missing);

        $text = $report->text();
        $this->assertStringContainsString('2 of 3 entered', $text);
        $this->assertStringContainsString("Not entered (1)*\n• Nitis", $text);
        $this->assertStringContainsString('• Sanjai — 7h 30m', $text);
        $this->assertStringNotContainsString('Owner', $text);
        // Gokul logged yesterday; Sanjai and Nitis are genuinely behind on it.
        $this->assertStringContainsString(
            'Still missing '.today()->subDay()->format('D j M').': Nitis, Sanjai',
            $text,
        );

        $params = $report->templateParameters();
        $this->assertSame(['2', '3', 'Sanjai (7h 30m), Gokul (1h)', 'Nitis'], array_slice($params, 1));
        foreach ($params as $p) {
            $this->assertStringNotContainsString("\n", $p, 'Meta refuses newlines inside a template parameter');
        }
    }

    public function test_an_admin_who_wrote_today_gets_the_full_message(): void
    {
        User::factory()->employee()->create(['name' => 'Sanjai']);
        User::factory()->create(['role' => User::ROLE_ADMIN, 'phone' => '7094126823']);
        $this->theyWroteRecently('917094126823');

        $this->artisan('whatsapp:timesheet-report')->assertSuccessful();

        $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0]->data());
        $this->assertCount(1, $sent);
        $this->assertSame('text', $sent[0]['type']);
        $this->assertStringContainsString('• Sanjai', $sent[0]['text']['body']);
    }

    public function test_outside_the_window_it_still_arrives_as_the_template(): void
    {
        User::factory()->employee()->create(['name' => 'Sanjai']);
        User::factory()->create(['role' => User::ROLE_ADMIN, 'phone' => '7094126823']);

        $this->artisan('whatsapp:timesheet-report')->assertSuccessful();

        $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0]->data());
        $this->assertCount(1, $sent);
        $this->assertSame('template', $sent[0]['type']);
        $this->assertSame(TimesheetDayReport::TEMPLATE, $sent[0]['template']['name']);
        $this->assertSame('Sanjai', $sent[0]['template']['components'][0]['parameters'][4]['text']);
    }

    public function test_it_is_scheduled_for_nine_at_night(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'whatsapp:timesheet-report'));

        $this->assertNotNull($event);
        $this->assertSame('0 21 * * *', $event->expression);
        $this->assertSame(config('app.timezone'), $event->timezone);
    }

    private function log(User $user, int $minutes, ?string $status = null, ?string $day = null): void
    {
        $entry = TimesheetEntry::create([
            'user_id' => $user->id,
            'worked_on' => $day ?? today()->toDateString(),
            'task' => 'Edit',
            'task_type' => TimesheetEntry::TASK_EDITING,
            'venture' => TimesheetVenture::ALL_CLIENTS,
            'minutes' => $minutes,
        ]);

        if ($status) {
            $entry->forceFill(['status' => $status])->save();
        }
    }

    private function theyWroteRecently(string $waId): void
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
            'occurred_at' => now()->subHour(),
            'received_at' => now(),
        ]);
    }
}
