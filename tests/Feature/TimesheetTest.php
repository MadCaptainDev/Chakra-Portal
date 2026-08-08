<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\TimesheetEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimesheetTest extends TestCase
{
    use RefreshDatabase;

    private function employee(): User
    {
        return User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
    }

    public function test_duration_is_derived_from_24_hour_times(): void
    {
        // The old spreadsheet wrote these as 12-hour with no AM/PM, which is
        // exactly why the app stores minutes rather than trusting the clock.
        $this->assertSame(240, TimesheetEntry::minutesBetween('10:00', '14:00'));   // "10:00-02:00 = 4 hrs"
        $this->assertSame(990, TimesheetEntry::minutesBetween('06:00', '22:30'));   // "06:00-10:30 = 16 hrs 30 mins"
        $this->assertSame(1050, TimesheetEntry::minutesBetween('06:00', '23:30'));  // "17 hrs 30 mins"
        $this->assertSame(435, TimesheetEntry::minutesBetween('10:30', '17:45'));   // "7 hrs 15 mins"
        $this->assertSame(30, TimesheetEntry::minutesBetween('09:00', '09:30'));
    }

    public function test_a_finish_before_the_start_is_read_as_passing_midnight(): void
    {
        $this->assertSame(90, TimesheetEntry::minutesBetween('23:00', '00:30'));
    }

    public function test_missing_times_leave_the_duration_to_the_caller(): void
    {
        $this->assertNull(TimesheetEntry::minutesBetween('10:00', null));
        $this->assertNull(TimesheetEntry::minutesBetween(null, '14:00'));
    }

    public function test_durations_are_formatted_the_way_the_team_writes_them(): void
    {
        $this->assertSame('16 hrs 30 mins', TimesheetEntry::formatMinutes(990));
        $this->assertSame('4 hrs', TimesheetEntry::formatMinutes(240));
        $this->assertSame('1 hr', TimesheetEntry::formatMinutes(60));
        $this->assertSame('45 mins', TimesheetEntry::formatMinutes(45));
        $this->assertSame('1 min', TimesheetEntry::formatMinutes(1));
        $this->assertSame('—', TimesheetEntry::formatMinutes(0));
    }

    public function test_storing_an_entry_computes_minutes_from_the_times(): void
    {
        $employee = $this->employee();

        $this->actingAs($employee)->post(route('my.timesheet.store'), [
            'worked_on' => '2026-04-07',
            'task' => 'Shoot',
            'venture' => 'PR',
            'started_at' => '06:00',
            'ended_at' => '22:30',
            'minutes' => 5, // deliberately wrong; the times must win
            'status' => 'completed',
        ])->assertRedirect();

        $entry = TimesheetEntry::firstOrFail();
        $this->assertSame($employee->id, $entry->user_id);
        $this->assertSame(990, $entry->minutes);
        $this->assertSame('16 hrs 30 mins', $entry->durationLabel());
    }

    public function test_an_entry_without_an_end_time_keeps_its_typed_duration(): void
    {
        // Real rows in the source sheet have a duration but no finish time.
        $this->actingAs($this->employee())->post(route('my.timesheet.store'), [
            'worked_on' => '2026-04-07',
            'task' => 'Editing',
            'started_at' => '11:10',
            'minutes' => 95,
            'status' => 'pending',
        ])->assertRedirect();

        $this->assertSame(95, TimesheetEntry::firstOrFail()->minutes);
    }

    public function test_an_entry_is_always_filed_against_the_signed_in_user(): void
    {
        $employee = $this->employee();
        $other = $this->employee();

        // Even if a user_id is posted, it must be ignored.
        $this->actingAs($employee)->post(route('my.timesheet.store'), [
            'user_id' => $other->id,
            'worked_on' => today()->toDateString(),
            'task' => 'Shoot',
            'minutes' => 60,
            'status' => 'completed',
        ]);

        $this->assertSame($employee->id, TimesheetEntry::firstOrFail()->user_id);
    }

    public function test_cancelled_work_is_logged_but_does_not_count_towards_hours(): void
    {
        $employee = $this->employee();

        foreach ([['completed', 120], ['pending', 60], ['cancelled', 300]] as [$status, $minutes]) {
            TimesheetEntry::create([
                'user_id' => $employee->id,
                'worked_on' => today()->toDateString(),
                'task' => ucfirst($status),
                'minutes' => $minutes,
                'status' => $status,
            ]);
        }

        $response = $this->actingAs($employee)->get(route('my.timesheet'));

        $response->assertOk();
        $response->assertViewHas('totalMinutes', 180); // 120 + 60, not the cancelled 300
        $response->assertSee('Cancelled'); // still listed
    }

    public function test_the_calendar_lays_out_whole_weeks(): void
    {
        $employee = $this->employee();

        TimesheetEntry::create([
            'user_id' => $employee->id,
            'worked_on' => '2026-04-07',
            'task' => 'Shoot',
            'minutes' => 240,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($employee)->get(route('my.calendar', ['month' => '2026-04']));

        $response->assertOk();
        $response->assertSee('April 2026');
        $response->assertSee('Shoot');

        foreach ($response->viewData('weeks') as $week) {
            $this->assertCount(7, $week, 'Every calendar row must be a full week.');
        }
    }

    public function test_admin_sees_team_totals_and_can_award_points(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $employee = $this->employee();

        TimesheetEntry::create([
            'user_id' => $employee->id,
            'worked_on' => now()->startOfMonth()->toDateString(),
            'task' => 'Shoot',
            'minutes' => 480,
            'status' => 'completed',
        ]);

        $this->actingAs($admin)->get(route('timesheets.index'))
            ->assertOk()
            ->assertViewHas('totalMinutes', 480)
            ->assertSee($employee->name);

        $this->actingAs($admin)->post(route('timesheets.award', $employee), [
            'month' => now()->startOfMonth()->toDateString(),
            'points' => 85,
            'note' => 'Strong month',
        ])->assertRedirect();

        $this->assertDatabaseHas('employee_points', [
            'user_id' => $employee->id,
            'points' => 85,
            'awarded_by' => $admin->id,
        ]);

        // The employee sees it on their own dashboard.
        $this->actingAs($employee)->get(route('my.dashboard'))->assertSee('Strong month');
    }

    public function test_an_employee_login_can_be_linked_to_a_salaries_record(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $record = Expense::create([
            'name' => 'Kanishka',
            'type' => Expense::TYPE_SALARY,
            'role' => 'Editor',
            'amount' => 15000,
            'is_active' => true,
        ]);

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Kanishka',
            'email' => 'kanishka@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => User::ROLE_EMPLOYEE,
            'employee_id' => $record->id,
        ])->assertRedirect(route('users.index'));

        $user = User::where('email', 'kanishka@example.com')->firstOrFail();
        $this->assertSame(User::ROLE_EMPLOYEE, $user->role);
        $this->assertSame($record->id, $user->employeeRecord->id);
        $this->assertSame('Editor', $user->employeeRecord->role);
    }

    public function test_linking_cannot_steal_a_record_that_already_has_a_login(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $existing = $this->employee();

        $record = Expense::create([
            'user_id' => $existing->id,
            'name' => 'Kanishka',
            'type' => Expense::TYPE_SALARY,
            'amount' => 15000,
            'is_active' => true,
        ]);

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Impostor',
            'email' => 'impostor@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => User::ROLE_EMPLOYEE,
            'employee_id' => $record->id,
        ]);

        $this->assertSame($existing->id, $record->fresh()->user_id);
    }

    public function test_guests_are_redirected_from_the_employee_area(): void
    {
        $this->get(route('my.timesheet'))->assertRedirect(route('login'));
        $this->get(route('my.calendar'))->assertRedirect(route('login'));
        $this->get(route('my.dashboard'))->assertRedirect(route('login'));
    }
}
