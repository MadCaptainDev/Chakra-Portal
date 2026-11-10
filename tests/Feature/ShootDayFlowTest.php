<?php

namespace Tests\Feature;

use App\Models\EquipmentItem;
use App\Models\Shoot;
use App\Models\ShootCrew;
use App\Models\ShootEvent;
use App\Models\ShootKit;
use App\Models\User;
use App\Notifications\ShootRunningLate;
use App\Services\KitCustody;
use App\Services\KitNotReady;
use App\Services\ShootRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

/**
 * The shoot day around the shooting itself: kit before leaving, cancelling,
 * where the crew go after the wrap, footage onto a drive, and the alerts
 * when any of it runs late.
 */
class ShootDayFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['shoots.require_kit' => true]);
    }

    private function shoot(array $attributes = []): Shoot
    {
        return Shoot::create(array_merge([
            'title' => 'Squad Architects',
            'starts_at' => now()->addHour(),
            'status' => Shoot::STATUS_CONFIRMED,
        ], $attributes));
    }

    private function crew(Shoot $shoot, ?User $user = null): User
    {
        $user = $user ?: User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        ShootCrew::create(['shoot_id' => $shoot->id, 'user_id' => $user->id, 'role' => 'Camera']);

        return $user;
    }

    private function kit(Shoot $shoot, string $name = 'Sony FX30'): ShootKit
    {
        $item = EquipmentItem::create(['name' => $name, 'quantity' => 1, 'is_active' => true, 'status' => EquipmentItem::STATUS_AVAILABLE]);

        return $shoot->kits()->create(['equipment_item_id' => $item->id, 'quantity' => 1]);
    }

    /** A shoot wrapped by $crew with its kit out, ready for the after-wrap steps. */
    private function wrapped(?User &$crew = null, array $attributes = []): Shoot
    {
        $shoot = $this->shoot($attributes);
        $crew = $this->crew($shoot);
        $this->kit($shoot);
        KitCustody::pickUpAll($shoot, $crew);
        ShootRun::start($shoot, $crew);
        ShootRun::finish($shoot->fresh(), $crew);

        return $shoot->fresh();
    }

    // ---- Kit is mandatory ----

    public function test_a_shoot_with_no_kit_listed_cannot_leave(): void
    {
        $shoot = $this->shoot();
        $crew = $this->crew($shoot);

        $this->expectException(KitNotReady::class);
        $this->expectExceptionMessage('no kit listed');

        ShootRun::depart($shoot, $crew);
    }

    public function test_a_shoot_cannot_leave_or_start_until_every_item_is_picked_up(): void
    {
        $shoot = $this->shoot();
        $crew = $this->crew($shoot);
        $camera = $this->kit($shoot);
        $this->kit($shoot, 'Gimbal');

        KitCustody::pickUp($camera, $crew);

        $this->actingAs($crew)->post(route('my.shoots.depart', $shoot))
            ->assertSessionHas('error', 'One kit item is not picked up yet. Tick it before you leave.');
        $this->actingAs($crew)->post(route('my.shoots.start', $shoot))->assertSessionHas('error');

        $this->assertFalse($shoot->fresh()->hasDeparted());
        $this->assertFalse($shoot->fresh()->isInProgress());
    }

    public function test_once_the_kit_is_picked_up_the_shoot_leaves_and_the_timeline_says_so(): void
    {
        $shoot = $this->shoot();
        $crew = $this->crew($shoot);
        $this->kit($shoot);
        $this->kit($shoot, 'Gimbal');

        $this->actingAs($crew)->post(route('my.shoots.kit.pick', $shoot))->assertRedirect();
        $this->actingAs($crew)->post(route('my.shoots.depart', $shoot))->assertSessionMissing('error');

        $this->assertTrue($shoot->fresh()->hasDeparted());
        $this->assertSame(
            [ShootEvent::KIT_PICKED, ShootEvent::DEPARTED],
            $shoot->events()->pluck('type')->all(),
        );
        $this->assertSame($crew->id, $shoot->events()->first()->user_id);
    }

    public function test_the_crew_can_add_kit_from_the_runner(): void
    {
        $shoot = $this->shoot();
        $crew = $this->crew($shoot);
        $item = EquipmentItem::create(['name' => 'LC Light', 'quantity' => 2, 'is_active' => true, 'status' => EquipmentItem::STATUS_AVAILABLE]);

        $this->actingAs($crew)->get(route('my.shoots.run', $shoot))
            ->assertOk()->assertSee('Every shoot needs its kit listed')->assertSee('LC Light');

        $this->actingAs($crew)->post(route('my.shoots.kit.add', $shoot), ['items' => [$item->id]])->assertRedirect();

        $this->assertSame([$item->id], $shoot->kits()->pluck('equipment_item_id')->all());
    }

    public function test_somebody_not_on_the_shoot_cannot_touch_its_kit(): void
    {
        $shoot = $this->shoot();
        $this->kit($shoot);
        $stranger = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($stranger)->post(route('my.shoots.kit.pick', $shoot))->assertNotFound();
        $this->actingAs($stranger)->post(route('my.shoots.cancel', $shoot), ['reason' => 'x'])->assertNotFound();
    }

    public function test_an_admin_can_let_a_shoot_leave_without_the_kit_check(): void
    {
        $shoot = $this->shoot();
        $crew = $this->crew($shoot);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->post(route('shoots.kit.waive', $shoot), ['reason' => 'Phone-only shoot'])->assertRedirect();

        ShootRun::depart($shoot->fresh(), $crew);

        $this->assertTrue($shoot->fresh()->hasDeparted());
        $this->assertSame('Phone-only shoot', $shoot->events()->where('type', ShootEvent::KIT_WAIVED)->value('note'));
    }

    public function test_a_crew_member_cannot_waive_the_kit_check(): void
    {
        $shoot = $this->shoot();
        $crew = $this->crew($shoot);

        $this->expectException(RuntimeException::class);

        ShootRun::waiveKit($shoot, $crew, 'In a hurry');
    }

    // ---- Cancelling ----

    public function test_a_shoot_cancelled_mid_run_frees_its_crew_for_the_next_one(): void
    {
        $first = $this->shoot(['title' => 'Morning']);
        $second = $this->shoot(['title' => 'Afternoon']);
        $crew = $this->crew($first);
        $this->crew($second, $crew);
        foreach ([$first, $second] as $s) {
            $this->kit($s, 'Camera '.$s->id);
            KitCustody::pickUpAll($s, $crew);
        }

        ShootRun::start($first, $crew);
        $this->actingAs($crew)->post(route('my.shoots.cancel', $first), ['reason' => 'Client postponed'])
            ->assertRedirect(route('my.shoots.run', $first));

        $first->refresh();
        $this->assertTrue($first->isCancelled());
        $this->assertFalse($first->isInProgress());
        $this->assertSame('Client postponed', $first->cancel_reason);
        $this->assertSame($crew->id, $first->cancelled_by_id);
        $this->assertSame(0, ShootRun::runningFor($crew)->count());

        ShootRun::start($second, $crew);
        $this->assertTrue($second->fresh()->isInProgress());
    }

    public function test_cancelling_needs_a_reason_and_a_wrapped_shoot_cannot_be_cancelled(): void
    {
        $shoot = $this->wrapped($crew);

        $this->actingAs($crew)->post(route('my.shoots.cancel', $shoot), [])->assertSessionHasErrors('reason');
        $this->actingAs($crew)->post(route('my.shoots.cancel', $shoot), ['reason' => 'Too late'])->assertSessionHas('error');

        $this->assertSame(Shoot::STATUS_COMPLETED, $shoot->fresh()->status);
    }

    public function test_a_cancelled_shoot_cannot_be_started(): void
    {
        $shoot = $this->shoot();
        $crew = $this->crew($shoot);
        ShootRun::cancel($shoot, $crew, 'Rain');

        $this->expectExceptionMessage('This shoot was cancelled.');

        ShootRun::start($shoot->fresh(), $crew);
    }

    public function test_cancelling_from_the_shoot_form_is_stamped_and_logged_too(): void
    {
        $shoot = $this->shoot();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin);
        $shoot->update(['status' => Shoot::STATUS_CANCELLED]);

        $this->assertNotNull($shoot->fresh()->cancelled_at);
        $this->assertSame($admin->id, $shoot->fresh()->cancelled_by_id);
        $this->assertSame(1, $shoot->events()->where('type', ShootEvent::CANCELLED)->count());
    }

    // ---- After the wrap ----

    public function test_after_the_wrap_the_crew_can_return_to_the_office_and_the_times_are_kept(): void
    {
        $shoot = $this->wrapped($crew);

        $this->actingAs($crew)->get(route('my.shoots.run', $shoot))
            ->assertOk()->assertSee('Returning to office')->assertSee('Footage on the drive')->assertSee('Kit returned');

        $this->actingAs($crew)->post(route('my.shoots.office', $shoot))->assertRedirect();
        $row = $shoot->crew()->first();
        $this->assertSame(ShootCrew::AFTER_OFFICE, $row->after_wrap);
        $this->assertNotNull($row->returning_at);
        $this->assertNull($row->back_at_office_at);

        $this->travel(25)->minutes();
        $this->actingAs($crew)->post(route('my.shoots.office', $shoot), ['arrived' => 1])->assertRedirect();

        $row->refresh();
        $this->assertNotNull($row->back_at_office_at);
        $this->assertTrue($row->back_at_office_at->gt($row->returning_at));
        $this->assertSame(
            [ShootEvent::RETURNING, ShootEvent::BACK_AT_OFFICE],
            $shoot->events()->whereIn('type', [ShootEvent::RETURNING, ShootEvent::BACK_AT_OFFICE])->pluck('type')->all(),
        );
    }

    public function test_the_crew_cannot_return_before_the_wrap(): void
    {
        $shoot = $this->shoot();
        $crew = $this->crew($shoot);

        $this->actingAs($crew)->post(route('my.shoots.office', $shoot))->assertSessionHas('error', 'Wrap this shoot first.');
    }

    public function test_going_straight_to_the_next_shoot_takes_the_kit_along(): void
    {
        $first = $this->wrapped($crew, ['title' => 'Morning', 'starts_at' => now()->subHours(2)]);
        $next = $this->shoot(['title' => 'Afternoon', 'starts_at' => now()->addMinutes(30)]);
        $this->crew($next, $crew);

        $this->actingAs($crew)->get(route('my.shoots.run', $first))->assertSee('On the way to the next shoot')->assertSee('Afternoon');

        $this->actingAs($crew)->post(route('my.shoots.next', $first))->assertRedirect(route('my.shoots.run', $next));

        $next->refresh()->load('kits');
        $this->assertTrue($next->hasDeparted());
        // The next shoot had no list of its own, so it inherits the kit, already picked up.
        $this->assertTrue($next->isPacked());
        $this->assertTrue($first->fresh()->load('kits')->isFullyReturned());
        $this->assertSame($next->id, $first->crew()->first()->next_shoot_id);
        $this->assertSame(1, $first->events()->where('type', ShootEvent::NEXT_SHOOT)->count());

        // And it can start without anybody picking anything up again.
        ShootRun::start($next, $crew);
        $this->assertTrue($next->fresh()->isInProgress());
    }

    public function test_there_is_no_next_shoot_when_the_only_other_one_is_cancelled_or_tomorrow(): void
    {
        $first = $this->wrapped($crew);
        $this->crew($this->shoot(['status' => Shoot::STATUS_CANCELLED]), $crew);
        $this->crew($this->shoot(['starts_at' => now()->addDay()->setTime(10, 0)]), $crew);

        $this->assertNull(ShootRun::nextFor($first, $crew));
        $this->actingAs($crew)->post(route('my.shoots.next', $first))->assertSessionHas('error', 'You have no other shoot today.');
    }

    // ---- Footage ----

    public function test_footage_is_filed_only_when_every_video_is_ticked(): void
    {
        $shoot = $this->shoot();
        $crew = $this->crew($shoot);
        $this->kit($shoot);
        KitCustody::pickUpAll($shoot, $crew);
        ShootRun::start($shoot, $crew);
        $one = ShootRun::recordVideo($shoot->fresh(), $crew, 'Site walkthrough');
        $this->travel(5)->minutes();
        $two = ShootRun::recordVideo($shoot->fresh(), $crew, 'Founder interview');
        ShootRun::finish($shoot->fresh(), $crew);

        $this->actingAs($crew)->post(route('my.shoots.footage', $shoot), ['drive' => 'Keerthi SSD', 'videos' => [$one->id]])
            ->assertSessionHas('error');
        $this->assertFalse($shoot->fresh()->hasFootageFiled());

        $this->actingAs($crew)->post(route('my.shoots.footage', $shoot), [
            'drive' => 'Keerthi SSD', 'url' => 'https://drive.google.com/drive/folders/abc', 'videos' => [$one->id, $two->id],
        ])->assertSessionMissing('error');

        $shoot->refresh();
        $this->assertTrue($shoot->hasFootageFiled());
        $this->assertSame('Keerthi SSD', $shoot->footage_drive);
        $this->assertSame($crew->id, $shoot->footage_filed_by_id);
        $this->assertNotNull($two->fresh()->footage_copied_at);
        $this->assertSame('2 videos on Keerthi SSD', $shoot->events()->where('type', ShootEvent::FOOTAGE_FILED)->value('note'));
    }

    public function test_footage_needs_a_drive_from_the_list(): void
    {
        $shoot = $this->wrapped($crew);

        $this->actingAs($crew)->post(route('my.shoots.footage', $shoot), ['drive' => 'My laptop'])->assertSessionHasErrors('drive');
    }

    // ---- Kit back ----

    public function test_returning_kit_with_a_problem_is_recorded_on_the_timeline(): void
    {
        $shoot = $this->wrapped($crew);
        $line = $shoot->kits()->first();

        $this->actingAs($crew)->post(route('my.shoots.kit.return', $shoot), [
            'kit_id' => $line->id, 'condition' => ShootKit::CONDITION_DAMAGED, 'condition_note' => 'Lens cap cracked',
        ])->assertRedirect();

        $this->assertSame(ShootKit::CONDITION_DAMAGED, $line->fresh()->condition);
        $event = $shoot->events()->where('type', ShootEvent::KIT_RETURNED)->first();
        $this->assertStringContainsString('Sony FX30 (damaged)', $event->note);
    }

    // ---- The admin's timeline ----

    public function test_the_shoot_page_shows_the_day_in_order(): void
    {
        $shoot = $this->wrapped($crew);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get(route('shoots.show', $shoot))
            ->assertOk()
            ->assertSeeInOrder(['Shoot day timeline', 'Kit picked up', 'Reached location', 'Shoot started', 'Wrapped'])
            ->assertSee('Footage not on a drive yet')
            ->assertSee('Kit still out');
    }

    // ---- Late alerts ----

    public function test_admins_are_told_once_when_a_shoot_has_not_started(): void
    {
        Notification::fake();
        $this->travelTo(now()->setTime(11, 0));
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $shoot = $this->shoot(['starts_at' => now()->subMinutes(20)]);
        $onTime = $this->shoot(['title' => 'Later', 'starts_at' => now()->subMinutes(5)]);

        $this->artisan('shoots:late-alerts')->assertSuccessful();
        $this->artisan('shoots:late-alerts')->assertSuccessful();

        Notification::assertSentToTimes($admin, ShootRunningLate::class, 1);
        Notification::assertSentTo($admin, fn (ShootRunningLate $n) => $n->shoot->is($shoot) && $n->kind === 'start'
            && str_contains($n->message, '20 min late'));
        $this->assertNotNull($shoot->fresh()->late_start_alert_at);
        $this->assertNull($onTime->fresh()->late_start_alert_at);
        $this->assertSame(1, $shoot->events()->where('type', ShootEvent::LATE_ALERT)->count());
    }

    public function test_admins_are_told_about_an_overrun_unfiled_footage_and_kit_still_out(): void
    {
        Notification::fake();
        $this->travelTo(now()->setTime(9, 0));
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        // Still rolling half an hour past its booked end.
        $overrun = $this->shoot(['title' => 'Overrun', 'starts_at' => now(), 'ends_at' => now()->addHours(2)]);
        $crew = $this->crew($overrun);
        $this->kit($overrun);
        KitCustody::pickUpAll($overrun, $crew);
        ShootRun::start($overrun, $crew);

        // Wrapped at 9, nothing filed, kit still out.
        $wrapped = $this->wrapped($other, ['title' => 'Wrapped', 'starts_at' => now()]);

        $this->travelTo(now()->setTime(11, 31));
        $this->artisan('shoots:late-alerts')->assertSuccessful();
        Notification::assertSentTo($admin, fn (ShootRunningLate $n) => $n->kind === 'overrun' && $n->shoot->is($overrun));
        Notification::assertNotSentTo($admin, fn (ShootRunningLate $n) => $n->kind === 'footage');

        $this->travelTo(now()->setTime(12, 5));
        $this->artisan('shoots:late-alerts')->assertSuccessful();
        Notification::assertSentTo($admin, fn (ShootRunningLate $n) => $n->kind === 'footage' && $n->shoot->is($wrapped));
        Notification::assertNotSentTo($admin, fn (ShootRunningLate $n) => $n->kind === 'kit');

        $this->travelTo(now()->setTime(21, 5));
        $this->artisan('shoots:late-alerts')->assertSuccessful();
        Notification::assertSentTo($admin, fn (ShootRunningLate $n) => $n->kind === 'kit' && $n->shoot->is($wrapped));
    }

    public function test_a_cancelled_or_date_only_shoot_is_never_late(): void
    {
        Notification::fake();
        $this->travelTo(now()->setTime(15, 0));
        User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->shoot(['starts_at' => now()->subHour(), 'status' => Shoot::STATUS_CANCELLED]);
        $this->shoot(['starts_at' => now()->startOfDay()]);

        $this->artisan('shoots:late-alerts')->assertSuccessful();

        Notification::assertNothingSent();
    }
}
