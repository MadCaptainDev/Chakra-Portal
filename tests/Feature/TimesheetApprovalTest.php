<?php

namespace Tests\Feature;

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
        return User::factory()->create([
            'role' => User::ROLE_EMPLOYEE,
            'manager_id' => $manager?->id,
        ]);
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
            'status' => TimesheetEntry::STATUS_COMPLETED,
        ], $overrides);
    }

    // ——— Filing today vs filing late ———

    public function test_an_entry_for_today_needs_no_approval(): void
    {
        $staff = $this->staff($this->manager());

        $this->actingAs($staff)->post(route('my.timesheet.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $entry = TimesheetEntry::firstOrFail();

        // Asking a manager to sign off work logged on the day it was done is
        // ceremony, and ceremony is what stops people filling timesheets in.
        $this->assertFalse($entry->was_backdated);
        $this->assertFalse($entry->needsReview());
    }

    public function test_an_entry_for_an_earlier_day_goes_to_the_manager(): void
    {
        $staff = $this->staff($this->manager());

        $this->actingAs($staff)->post(route('my.timesheet.store'), $this->payload([
            'worked_on' => now()->subDays(3)->toDateString(),
        ]))->assertSessionHasNoErrors();

        $entry = TimesheetEntry::firstOrFail();

        $this->assertTrue($entry->was_backdated);
        $this->assertTrue($entry->needsReview());
    }

    public function test_editing_an_entry_sends_it_back_for_a_decision(): void
    {
        $manager = $this->manager();
        $staff = $this->staff($manager);

        $this->actingAs($staff)->post(route('my.timesheet.store'), $this->payload());
        $entry = TimesheetEntry::firstOrFail();

        $this->actingAs($manager)->post(route('timesheets.entry.approve', $entry))->assertRedirect();
        $this->assertTrue($entry->refresh()->isApproved());

        $this->actingAs($staff)->put(route('my.timesheet.update', $entry), $this->payload([
            'task' => 'Shoot, corrected',
        ]))->assertSessionHasNoErrors();

        $entry->refresh();

        // An entry changed since approval is not the entry that was approved.
        $this->assertNull($entry->review_state);
        $this->assertTrue($entry->needsReview());
    }

    // ——— Who may decide ———

    public function test_a_manager_can_approve_their_own_report(): void
    {
        $manager = $this->manager();
        $staff = $this->staff($manager);
        $entry = TimesheetEntry::create($this->payload(['user_id' => $staff->id]));

        $this->actingAs($manager)->post(route('timesheets.entry.approve', $entry))->assertRedirect();

        $entry->refresh();
        $this->assertTrue($entry->isApproved());
        $this->assertSame($manager->id, $entry->reviewed_by_id);
    }

    public function test_a_manager_cannot_decide_on_somebody_elses_report(): void
    {
        $manager = $this->manager();
        $stranger = $this->staff($this->manager());
        $entry = TimesheetEntry::create($this->payload(['user_id' => $stranger->id]));

        $this->actingAs($manager)->post(route('timesheets.entry.approve', $entry))->assertForbidden();

        $this->assertNull($entry->refresh()->review_state);
    }

    public function test_an_admin_can_decide_on_anyone(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $staff = $this->staff($this->manager());
        $entry = TimesheetEntry::create($this->payload(['user_id' => $staff->id]));

        // Somebody has to be able to cover when a manager is on a shoot.
        $this->actingAs($admin)->post(route('timesheets.entry.approve', $entry))->assertRedirect();

        $this->assertTrue($entry->refresh()->isApproved());
    }

    public function test_rejecting_requires_a_reason(): void
    {
        $manager = $this->manager();
        $staff = $this->staff($manager);
        $entry = TimesheetEntry::create($this->payload(['user_id' => $staff->id]));

        $this->actingAs($manager)
            ->post(route('timesheets.entry.reject', $entry), ['review_note' => ''])
            ->assertSessionHasErrors('review_note');

        $this->assertNull($entry->refresh()->review_state);
    }

    public function test_a_rejection_records_the_reason(): void
    {
        $manager = $this->manager();
        $staff = $this->staff($manager);
        $entry = TimesheetEntry::create($this->payload(['user_id' => $staff->id]));

        $this->actingAs($manager)->post(route('timesheets.entry.reject', $entry), [
            'review_note' => 'You were on the SVA shoot that afternoon.',
        ])->assertRedirect();

        $entry->refresh();
        $this->assertTrue($entry->isRejected());
        $this->assertSame('You were on the SVA shoot that afternoon.', $entry->review_note);
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
        TimesheetEntry::create($this->payload([
            'user_id' => $staff->id,
            'worked_on' => '2026-08-11',
            'status' => TimesheetEntry::STATUS_CANCELLED,
        ]));

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
            'manager_id' => $staff->id,
        ])->assertSessionHasErrors('manager_id');

        $this->assertNull($staff->refresh()->manager_id);
    }
}
