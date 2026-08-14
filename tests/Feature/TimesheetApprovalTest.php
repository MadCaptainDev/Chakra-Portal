<?php

namespace Tests\Feature;

use App\Models\TimesheetDay;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Support\Attendance;
use App\Support\TimesheetVenture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TimesheetApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        return User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Manager Person']);
    }

    private function staff(?User $manager = null): User
    {
        $staff = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        if ($manager) {
            $staff->managers()->attach($manager);
        }

        return $staff;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'worked_on' => now()->toDateString(),
            'task' => 'Shoot',
            'task_type' => TimesheetEntry::TASK_SHOOTING,
            'venture' => TimesheetVenture::ALL_CLIENTS,
            'minutes' => 120,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function decide(User $employee, array $overrides = []): array
    {
        return array_merge([
            'worked_on' => now()->toDateString(),
            'review_state' => TimesheetDay::APPROVED,
        ], $overrides);
    }

    private function decisionFor(User $employee, ?string $date = null): ?TimesheetDay
    {
        return TimesheetDay::where('user_id', $employee->id)
            ->whereDate('worked_on', $date ?? now()->toDateString())
            ->first();
    }

    // ——— Filing today vs filing late ———

    public function test_an_entry_for_today_is_not_marked_late(): void
    {
        $staff = $this->staff($this->manager());

        $this->actingAs($staff)->post(route('my.timesheet.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertFalse(TimesheetEntry::firstOrFail()->was_backdated);
    }

    public function test_an_entry_for_an_earlier_day_is_marked_late(): void
    {
        $staff = $this->staff($this->manager());

        $this->actingAs($staff)->post(route('my.timesheet.store'), $this->payload([
            'worked_on' => now()->subDays(3)->toDateString(),
        ]))->assertSessionHasNoErrors();

        $this->assertTrue(TimesheetEntry::firstOrFail()->was_backdated);
    }

    public function test_editing_an_entry_withdraws_the_decision_on_its_day(): void
    {
        $manager = $this->manager();
        $staff = $this->staff($manager);

        $this->actingAs($staff)->post(route('my.timesheet.store'), $this->payload());
        $entry = TimesheetEntry::firstOrFail();

        $this->actingAs($manager)->post(route('timesheets.day', $staff), $this->decide($staff))->assertRedirect();
        $this->assertNotNull($this->decisionFor($staff));

        $this->actingAs($staff)->put(route('my.timesheet.update', $entry), $this->payload([
            'task' => 'Shoot, corrected',
        ]))->assertSessionHasNoErrors();

        // A day changed since approval is not the day that was approved.
        $this->assertNull($this->decisionFor($staff));
    }

    public function test_adding_an_entry_to_a_decided_day_reopens_it(): void
    {
        $manager = $this->manager();
        $staff = $this->staff($manager);

        $this->actingAs($staff)->post(route('my.timesheet.store'), $this->payload());
        $this->actingAs($manager)->post(route('timesheets.day', $staff), $this->decide($staff));

        $this->actingAs($staff)->post(route('my.timesheet.store'), $this->payload(['task' => 'Second job']))
            ->assertSessionHasNoErrors();

        $this->assertNull($this->decisionFor($staff));
    }

    // ——— Who may decide ———

    public function test_a_manager_can_accept_a_day_for_their_own_report(): void
    {
        $manager = $this->manager();
        $staff = $this->staff($manager);
        TimesheetEntry::create($this->payload(['user_id' => $staff->id]));

        $this->actingAs($manager)->post(route('timesheets.day', $staff), $this->decide($staff))->assertRedirect();

        $decision = $this->decisionFor($staff);
        $this->assertTrue($decision->isApproved());
        $this->assertSame($manager->id, $decision->reviewed_by_id);
    }

    public function test_a_manager_cannot_decide_on_somebody_elses_report(): void
    {
        $manager = $this->manager();
        $stranger = $this->staff($this->manager());
        TimesheetEntry::create($this->payload(['user_id' => $stranger->id]));

        $this->actingAs($manager)->post(route('timesheets.day', $stranger), $this->decide($stranger))
            ->assertForbidden();

        $this->assertNull($this->decisionFor($stranger));
    }

    public function test_an_admin_can_decide_on_anyone(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $staff = $this->staff($this->manager());
        TimesheetEntry::create($this->payload(['user_id' => $staff->id]));

        // Somebody has to be able to cover when a manager is on a shoot.
        $this->actingAs($admin)->post(route('timesheets.day', $staff), $this->decide($staff))->assertRedirect();

        $this->assertTrue($this->decisionFor($staff)->isApproved());
    }

    public function test_rejecting_a_day_requires_a_reason(): void
    {
        $manager = $this->manager();
        $staff = $this->staff($manager);
        TimesheetEntry::create($this->payload(['user_id' => $staff->id]));

        $this->actingAs($manager)
            ->post(route('timesheets.day', $staff), $this->decide($staff, [
                'review_state' => TimesheetDay::REJECTED,
                'review_note' => '',
            ]))
            ->assertSessionHasErrors('review_note');

        $this->assertNull($this->decisionFor($staff));
    }

    public function test_a_rejection_records_the_reason(): void
    {
        $manager = $this->manager();
        $staff = $this->staff($manager);
        TimesheetEntry::create($this->payload(['user_id' => $staff->id]));

        $this->actingAs($manager)->post(route('timesheets.day', $staff), $this->decide($staff, [
            'review_state' => TimesheetDay::REJECTED,
            'review_note' => 'You were on the SVA shoot that afternoon.',
        ]))->assertRedirect();

        $decision = $this->decisionFor($staff);
        $this->assertTrue($decision->isRejected());
        $this->assertSame('You were on the SVA shoot that afternoon.', $decision->review_note);
    }

    public function test_deciding_again_replaces_the_earlier_decision(): void
    {
        $manager = $this->manager();
        $staff = $this->staff($manager);
        TimesheetEntry::create($this->payload(['user_id' => $staff->id]));

        $this->actingAs($manager)->post(route('timesheets.day', $staff), $this->decide($staff, [
            'review_state' => TimesheetDay::REJECTED,
            'review_note' => 'Wrong client.',
        ]));

        $this->actingAs($manager)->post(route('timesheets.day', $staff), $this->decide($staff));

        // A manager changing their mind leaves one decision behind, not two.
        $this->assertSame(1, TimesheetDay::where('user_id', $staff->id)->count());
        $this->assertTrue($this->decisionFor($staff)->isApproved());
        $this->assertNull($this->decisionFor($staff)->review_note);
    }

    public function test_a_form_post_cannot_claim_somebody_else_decided(): void
    {
        $manager = $this->manager();
        $staff = $this->staff($manager);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        TimesheetEntry::create($this->payload(['user_id' => $staff->id]));

        $this->actingAs($manager)->post(route('timesheets.day', $staff), $this->decide($staff, [
            'reviewed_by_id' => $admin->id,
            'reviewed_at' => now()->subYear()->toDateTimeString(),
        ]))->assertRedirect();

        // Who signed a day off is the one fact this table exists to hold.
        $this->assertSame($manager->id, $this->decisionFor($staff)->reviewed_by_id);
    }

    // ——— The team screen ———

    public function test_the_team_screen_is_refused_to_someone_who_manages_nobody(): void
    {
        $this->actingAs($this->staff())->get(route('my.team'))->assertForbidden();
    }

    public function test_a_manager_sees_only_their_own_reports(): void
    {
        $manager = $this->manager();
        $mine = $this->staff($manager);
        $mine->update(['name' => 'My Report']);
        $theirs = $this->staff($this->manager());
        $theirs->update(['name' => 'Their Report']);

        $response = $this->actingAs($manager)->get(route('my.team'));

        $response->assertOk()->assertSee('My Report')->assertDontSee('Their Report');
    }

    // ——— Absence ———

    public function test_sundays_and_future_days_are_never_absences(): void
    {
        $staff = $this->staff();

        // A Sunday, and a date after today, must not count against anybody.
        Carbon::setTestNow(Carbon::parse('2026-08-12'));  // a Wednesday

        $absences = Attendance::absencesFor($staff, Carbon::parse('2026-08-01'));

        $this->assertTrue($absences->every(fn (Carbon $day) => $day->dayOfWeek !== Carbon::SUNDAY));
        $this->assertTrue($absences->every(fn (Carbon $day) => $day->lte(now())));

        Carbon::setTestNow();
    }

    public function test_a_logged_day_is_not_an_absence(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12'));

        $staff = $this->staff();
        TimesheetEntry::create($this->payload([
            'user_id' => $staff->id,
            'worked_on' => '2026-08-11',
        ]));

        $absences = Attendance::absencesFor($staff, Carbon::parse('2026-08-01'))
            ->map->toDateString();

        $this->assertFalse($absences->contains('2026-08-11'));
        // The 10th was a working Monday with nothing on it.
        $this->assertTrue($absences->contains('2026-08-10'));

        Carbon::setTestNow();
    }

    public function test_a_cancelled_entry_does_not_count_as_having_worked(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-12'));

        $staff = $this->staff();
        // status is no longer fillable -- only the spreadsheet importer writes
        // it, so a test that needs a cancelled row sets it the same way.
        TimesheetEntry::create($this->payload([
            'user_id' => $staff->id,
            'worked_on' => '2026-08-11',
        ]))->forceFill(['status' => TimesheetEntry::STATUS_CANCELLED])->save();

        $absences = Attendance::absencesFor($staff, Carbon::parse('2026-08-01'))->map->toDateString();

        $this->assertTrue($absences->contains('2026-08-11'));

        Carbon::setTestNow();
    }

    public function test_a_future_month_has_no_absences_at_all(): void
    {
        $staff = $this->staff();

        $this->assertTrue(
            Attendance::absencesFor($staff, now()->addMonths(2)->startOfMonth())->isEmpty()
        );
    }

    // ——— Password ———

    public function test_staff_cannot_change_their_own_password(): void
    {
        $staff = $this->staff();

        // Hiding the form is not removing it: the endpoint must refuse too.
        $this->actingAs($staff)->put(route('password.update'), [
            'current_password' => 'password',
            'password' => 'new-password-here',
            'password_confirmation' => 'new-password-here',
        ])->assertForbidden();
    }

    public function test_an_admin_can_still_change_their_own_password(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->put(route('password.update'), [
            'current_password' => 'password',
            'password' => 'new-password-here',
            'password_confirmation' => 'new-password-here',
        ])->assertSessionHasNoErrors();
    }

    public function test_nobody_can_be_made_their_own_manager(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $staff = $this->staff();

        $this->actingAs($admin)->put(route('users.update', $staff), [
            'name' => $staff->name,
            'email' => $staff->email,
            'role' => User::ROLE_EMPLOYEE,
            'manager_ids' => [$staff->id],
        ])->assertSessionHasErrors('manager_ids.0');

        $this->assertCount(0, $staff->managers);
    }

    public function test_an_employee_can_have_more_than_one_manager_and_either_can_decide(): void
    {
        $producer = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'The Producer']);
        $lead = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'The Studio Lead']);

        $staff = $this->staff($producer);
        $staff->managers()->attach($lead);

        $this->actingAs($staff)->post(route('my.timesheet.store'), $this->payload());

        // Whichever of them is reachable that day is the one who signs it off.
        $this->actingAs($lead)
            ->post(route('timesheets.day', $staff), $this->decide($staff))
            ->assertRedirect();

        $this->assertSame($lead->id, $this->decisionFor($staff)->reviewed_by_id);

        $this->actingAs($producer)
            ->post(route('timesheets.day', $staff), $this->decide($staff, [
                'review_state' => TimesheetDay::REJECTED,
                'review_note' => 'Hours look short.',
            ]))
            ->assertRedirect();

        $this->assertSame($producer->id, $this->decisionFor($staff)->reviewed_by_id);
    }

    public function test_taking_somebody_off_every_queue_leaves_them_with_no_manager(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $manager = $this->manager();
        $staff = $this->staff($manager);

        // The field is simply absent when nothing is ticked, which has to read
        // as "nobody" rather than "leave it alone".
        $this->actingAs($admin)->put(route('users.update', $staff), [
            'name' => $staff->name,
            'email' => $staff->email,
            'role' => User::ROLE_EMPLOYEE,
        ])->assertSessionHasNoErrors();

        $this->assertCount(0, $staff->refresh()->managers);
        $this->actingAs($manager)->get(route('my.team'))->assertForbidden();
    }
}
