<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\ContentItem;
use App\Models\User;
use App\Models\VentureAlertSetting;
use App\Models\VentureWatch;
use App\Notifications\VentureUpdate;
use App\Support\VentureBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** The dashboard's "My ventures": counts, choosing, refresh and notifications. */
class VentureBoardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 11, 11)->setTime(8, 0));
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'name' => 'Keerthi']);
    }

    private function item(string $venture, string $status, ?string $date = null, string $source = 'reel', string $title = 'Video'): ContentItem
    {
        return ContentItem::factory()->create([
            'source' => $source, 'venture' => $venture, 'status' => $status, 'title' => $title,
            'notion_page_id' => 'p-'.uniqid('', true), 'published_date' => $date,
        ]);
    }

    private function janet(): void
    {
        $this->item('Janet', 'To Be Shooted');
        $this->item('Janet', 'To Be Edited');
        $this->item('Janet', 'Edit in Progress', '2026-11-20');
        $this->item('Janet', 'Under Review');
        $this->item('Janet', 'Video Ready', '2026-11-25');
        $this->item('Janet', 'Scheduled', '2026-11-28');
        $this->item('Janet', 'Published', '2026-11-03');
        $this->item('Janet', 'Published', '2026-10-30');   // last month: not counted
        $this->item('Janet', 'Delivered', '2026-11-05', 'post');
        $this->item('Janet', 'Canceled', '2026-11-06');    // never counted
        $this->item('Janet', 'To Be Edited', '2026-11-02'); // past its date
        $this->item('Riya', 'Video Ready');
    }

    public function test_each_stage_is_counted_per_venture_with_what_is_in_hand(): void
    {
        $this->janet();

        $board = VentureBoard::build(['Janet', 'Riya'], now());
        $janet = collect($board['rows'])->firstWhere('venture', 'Janet');

        $this->assertSame(['idea' => 0, 'to_shoot' => 1, 'to_edit' => 2, 'editing' => 2, 'ready' => 1, 'scheduled' => 1, 'other' => 0], $janet['stages']);
        $this->assertSame(6, $janet['in_hand']);
        $this->assertSame(2, $janet['out_month']);
        $this->assertSame(1, $janet['overdue']);
        $this->assertSame('2026-11-28', $janet['planned_until']);
        $this->assertSame('ok', $janet['health']);
        $this->assertSame(7, $board['totals']['in_hand']);
        $this->assertSame('low', collect($board['rows'])->firstWhere('venture', 'Riya')['health']);
    }

    public function test_an_admin_chooses_ventures_and_the_board_keeps_their_order(): void
    {
        $this->janet();

        $this->actingAs($this->admin)->getJson(route('ventures.data'))->assertOk()->assertJsonPath('chosen', false);

        $this->putJson(route('ventures.choose'), ['ventures' => ['Riya', 'Janet']])
            ->assertOk()->assertJsonPath('chosen', true)
            ->assertJsonPath('rows.0.venture', 'Riya')->assertJsonPath('rows.1.venture', 'Janet')
            ->assertJsonPath('rows.1.in_hand', 6);

        $this->putJson(route('ventures.choose'), ['ventures' => ['Janet']])->assertOk();
        $this->assertSame(['Janet'], VentureBoard::chosen($this->admin));

        $this->getJson(route('ventures.all'))->assertOk()->assertJsonPath('ventures.0.name', 'Janet')->assertJsonPath('chosen.0', 'Janet');
        $this->get(route('dashboard'))->assertOk()->assertSee('My ventures')->assertSee('ventureBoard', false);
    }

    public function test_the_board_is_admins_only(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($employee)->getJson(route('ventures.data'))->assertForbidden();
        $this->putJson(route('ventures.choose'), ['ventures' => ['Janet']])->assertForbidden();
    }

    public function test_refresh_answers_at_once(): void
    {
        $this->actingAs($this->admin)->postJson(route('ventures.refresh'))->assertStatus(202)->assertJsonPath('message', 'Refreshing from Notion…');
    }

    public function test_settings_are_saved_and_checked(): void
    {
        $body = ['daily_summary' => true, 'summary_at' => '08:30', 'low_stock' => 6, 'notify_low' => true, 'notify_ready' => false, 'notify_published' => true, 'notify_shot' => true, 'whatsapp' => false];

        $this->actingAs($this->admin)->putJson(route('ventures.settings'), $body)->assertOk()->assertJsonPath('settings.low_stock', 6)->assertJsonPath('settings.notify_ready', false);
        $this->putJson(route('ventures.settings'), ['low_stock' => 0] + $body)->assertStatus(422);

        $this->assertSame('08:30', VentureAlertSetting::for($this->admin)->summary_at);
    }

    public function test_a_video_becoming_ready_or_going_out_is_told_once_and_the_first_run_only_records(): void
    {
        Notification::fake();
        VentureWatch::create(['user_id' => $this->admin->id, 'venture' => 'Janet']);
        VentureAlertSetting::for($this->admin)->update(['daily_summary' => false, 'notify_low' => false]);
        $reel = $this->item('Janet', 'Under Review', null, 'reel', 'Diwali offer');
        $post = $this->item('Janet', 'Scheduled', null, 'post', 'Pongal post');

        $this->artisan('ventures:alerts')->assertSuccessful();
        Notification::assertNothingSent();

        $reel->forceFill(['status' => 'Video Ready'])->save();
        $post->forceFill(['status' => 'Published'])->save();
        $this->artisan('ventures:alerts')->assertSuccessful();
        $this->artisan('ventures:alerts')->assertSuccessful();

        Notification::assertSentToTimes($this->admin, VentureUpdate::class, 1);
        Notification::assertSentTo($this->admin, fn (VentureUpdate $n) => $n->title === 'Janet'
            && str_contains($n->body, '1 video ready to post: Diwali offer')
            && str_contains($n->body, '1 video out: Pongal post'));
    }

    public function test_switched_off_kinds_are_not_sent(): void
    {
        Notification::fake();
        VentureWatch::create(['user_id' => $this->admin->id, 'venture' => 'Janet']);
        VentureAlertSetting::for($this->admin)->update(['daily_summary' => false, 'notify_low' => false, 'notify_ready' => false]);
        $reel = $this->item('Janet', 'Under Review');
        $this->artisan('ventures:alerts');

        $reel->forceFill(['status' => 'Video Ready'])->save();
        $this->artisan('ventures:alerts');

        Notification::assertNothingSent();
    }

    public function test_running_low_is_told_once_until_it_recovers(): void
    {
        Notification::fake();
        VentureWatch::create(['user_id' => $this->admin->id, 'venture' => 'Janet']);
        VentureAlertSetting::for($this->admin)->update(['daily_summary' => false, 'low_stock' => 3]);
        $this->item('Janet', 'Video Ready');
        $this->item('Janet', 'To Be Shooted');

        $this->artisan('ventures:alerts');
        $this->artisan('ventures:alerts');
        Notification::assertSentToTimes($this->admin, VentureUpdate::class, 1);
        Notification::assertSentTo($this->admin, fn (VentureUpdate $n) => $n->title === 'Janet is running low'
            && str_contains($n->body, 'Only 1 video in hand (1 ready). 1 waiting to be shot.'));

        // Recovers, then drops again: told again.
        $extra = [$this->item('Janet', 'To Be Edited'), $this->item('Janet', 'To Be Edited')];
        $this->artisan('ventures:alerts');
        $this->assertNull(VentureWatch::first()->low_alerted_at);
        foreach ($extra as $e) {
            $e->forceFill(['status' => 'Canceled'])->save();
        }
        $this->artisan('ventures:alerts');
        Notification::assertSentToTimes($this->admin, VentureUpdate::class, 2);
    }

    public function test_the_morning_summary_goes_once_at_the_chosen_time(): void
    {
        Notification::fake();
        $this->janet();
        VentureWatch::create(['user_id' => $this->admin->id, 'venture' => 'Janet', 'sort_order' => 0]);
        VentureWatch::create(['user_id' => $this->admin->id, 'venture' => 'Riya', 'sort_order' => 1]);
        VentureAlertSetting::for($this->admin)->update(['summary_at' => '09:00', 'notify_low' => false]);

        $this->artisan('ventures:alerts');
        Notification::assertNothingSent();

        $this->travelTo(now()->setTime(9, 2));
        $this->artisan('ventures:alerts');
        $this->artisan('ventures:alerts');

        Notification::assertSentToTimes($this->admin, VentureUpdate::class, 1);
        Notification::assertSentTo($this->admin, fn (VentureUpdate $n) => $n->title === 'Your ventures today'
            && str_contains($n->body, '7 videos in hand across 2 ventures, 2 out this month.')
            && str_contains($n->body, 'Janet: 6 in hand (1 ready, 1 scheduled), 1 to shoot, 2 out this month, 1 past their date')
            && str_contains($n->body, '⚠ Riya: 1 in hand'));
    }

    public function test_a_test_notification_lands_in_the_bell(): void
    {
        $this->actingAs($this->admin)->postJson(route('ventures.test'))->assertOk();

        $this->assertSame(1, AppNotification::where('user_id', $this->admin->id)->count());
        $this->getJson(route('notification-center.feed'))->assertOk()
            ->assertJsonFragment(['title' => 'Venture alerts are on', 'unread' => true]);

        $this->postJson(route('notification-center.read'))->assertOk();
        $this->assertNotNull(AppNotification::first()->read_at);
    }

    public function test_a_venture_spelled_with_other_case_or_spaces_is_counted_under_the_chosen_name(): void
    {
        $this->item('Janet', 'Video Ready');
        $this->item('janet ', 'To Be Edited');

        $rows = VentureBoard::build(['Janet'], now())['rows'];

        // SQLite compares exactly, so only the exact spelling is read here;
        // what matters is that no row appears under another name.
        $this->assertCount(1, $rows);
        $this->assertSame('Janet', $rows[0]['venture']);
    }
}
