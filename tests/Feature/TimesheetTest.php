<?php

namespace Tests\Feature;

use App\Models\Client;
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

    /**
     * Seed the clients the team actually books time against.
     *
     * @return array{janet: Client, sva: Client, riya: Client, thor: Client}
     */
    private function seedClients(): array
    {
        return [
            'janet' => Client::factory()->create([
                'name' => 'Digital Harvest (Janet Hospitals)',
                'notion_venture' => 'Janet',
            ]),
            'sva' => Client::factory()->create([
                'name' => 'SVA Silks and Readymades',
                'notion_venture' => 'SVA Silks',
            ]),
            'riya' => Client::factory()->create([
                'name' => 'Riya Makeover Artisty',
                'notion_venture' => 'Riya',
            ]),
            'thor' => Client::factory()->create([
                'name' => 'Thor Gym',
                'notion_venture' => null,
            ]),
        ];
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
        $this->seedClients();

        $this->actingAs($employee)->post(route('my.timesheet.store'), [
            'worked_on' => '2026-04-07',
            'task' => 'Shoot',
            'task_type' => 'shooting',
            'venture' => 'Riya',
            'started_at' => '06:00',
            'ended_at' => '22:30',
            'minutes' => 5, // deliberately wrong; the times must win
            'status' => 'completed',
        ])->assertRedirect();

        $entry = TimesheetEntry::firstOrFail();
        $this->assertSame($employee->id, $entry->user_id);
        $this->assertSame(990, $entry->minutes);
        $this->assertSame('16 hrs 30 mins', $entry->durationLabel());
        $this->assertSame('shooting', $entry->task_type);
    }

    public function test_an_entry_without_an_end_time_keeps_its_typed_duration(): void
    {
        // Real rows in the source sheet have a duration but no finish time.
        $this->seedClients();

        $this->actingAs($this->employee())->post(route('my.timesheet.store'), [
            'worked_on' => '2026-04-07',
            'task' => 'Editing',
            'task_type' => 'editing',
            'venture' => 'SVA Silks',
            'started_at' => '11:10',
            'minutes' => 95,
            'status' => 'pending',
        ])->assertRedirect();

        $entry = TimesheetEntry::firstOrFail();
        $this->assertSame(95, $entry->minutes);
        $this->assertSame('editing', $entry->task_type);
    }

    public function test_venture_is_required_when_logging_work(): void
    {
        $this->actingAs($this->employee())->post(route('my.timesheet.store'), [
            'worked_on' => today()->toDateString(),
            'task' => 'Shoot',
            'task_type' => 'shooting',
            'minutes' => 60,
            'status' => 'completed',
        ])->assertSessionHasErrors('venture');

        $this->assertSame(0, TimesheetEntry::count());
    }

    public function test_task_type_is_required(): void
    {
        $this->seedClients();

        $this->actingAs($this->employee())->post(route('my.timesheet.store'), [
            'worked_on' => today()->toDateString(),
            'task' => 'Shoot',
            'venture' => 'Riya',
            'minutes' => 60,
            'status' => 'completed',
        ])->assertSessionHasErrors('task_type');

        $this->assertSame(0, TimesheetEntry::count());
    }

    public function test_unknown_venture_is_rejected(): void
    {
        $this->seedClients();

        $this->actingAs($this->employee())->post(route('my.timesheet.store'), [
            'worked_on' => today()->toDateString(),
            'task' => 'Shoot',
            'task_type' => 'shooting',
            'venture' => 'PR',
            'minutes' => 60,
            'status' => 'completed',
        ])->assertSessionHasErrors('venture');

        $this->assertSame(0, TimesheetEntry::count());
    }

    public function test_an_entry_is_always_filed_against_the_signed_in_user(): void
    {
        $employee = $this->employee();
        $other = $this->employee();
        $this->seedClients();

        // Even if a user_id is posted, it must be ignored.
        $this->actingAs($employee)->post(route('my.timesheet.store'), [
            'user_id' => $other->id,
            'worked_on' => today()->toDateString(),
            'task' => 'Shoot',
            'task_type' => 'shooting',
            'venture' => 'Janet',
            'minutes' => 60,
            'status' => 'completed',
        ]);

        $this->assertSame($employee->id, TimesheetEntry::firstOrFail()->user_id);
    }

    public function test_entries_are_ordered_newest_day_first_then_by_start_time(): void
    {
        $employee = $this->employee();
        $day = now()->startOfMonth();

        TimesheetEntry::create([
            'user_id' => $employee->id,
            'worked_on' => $day->copy()->addDays(2)->toDateString(),
            'task' => 'Late day afternoon',
            'venture' => 'SVA Silks',
            'started_at' => '14:00',
            'minutes' => 60,
            'status' => 'completed',
        ]);
        TimesheetEntry::create([
            'user_id' => $employee->id,
            'worked_on' => $day->copy()->addDays(2)->toDateString(),
            'task' => 'Late day morning',
            'venture' => 'SVA Silks',
            'started_at' => '09:00',
            'minutes' => 60,
            'status' => 'completed',
        ]);
        TimesheetEntry::create([
            'user_id' => $employee->id,
            'worked_on' => $day->toDateString(),
            'task' => 'Earlier day',
            'venture' => 'Riya',
            'started_at' => '10:00',
            'minutes' => 30,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($employee)->get(route('my.timesheet', [
            'month' => $day->format('Y-m'),
        ]));

        $response->assertOk();
        $ids = $response->viewData('entries')->pluck('task')->all();
        $this->assertSame(['Late day morning', 'Late day afternoon', 'Earlier day'], $ids);
        $response->assertViewHas('stats');
    }

    public function test_admin_timesheet_index_includes_team_charts(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $employee = $this->employee();

        TimesheetEntry::create([
            'user_id' => $employee->id,
            'worked_on' => now()->startOfMonth()->toDateString(),
            'task' => 'Shoot',
            'venture' => 'SVA Silks',
            'minutes' => 120,
            'status' => 'completed',
        ]);

        TimesheetEntry::create([
            'user_id' => $employee->id,
            'worked_on' => now()->startOfMonth()->addDays(2)->toDateString(),
            'task' => 'Edit',
            'venture' => 'SVA Silks',
            'minutes' => 240,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($admin)->get(route('timesheets.index'));

        $response->assertOk()
            ->assertViewHas('ranking')
            ->assertViewHas('teamStats')
            ->assertSee('Who worked most')
            ->assertSee('Team hours by day')
            ->assertSee('By client')
            ->assertSee('By type')
            ->assertDontSee('Busiest production days')
            ->assertDontSee('Hours by weekday')
            ->assertDontSee('By status');

        $teamStats = $response->viewData('teamStats');
        $this->assertNotEmpty($teamStats['daily']);
        $this->assertCount((int) now()->daysInMonth, $teamStats['daily']);
        $this->assertSame(360, $teamStats['totalMinutes']);
        $this->assertSame(240, $teamStats['maxDaily']);
        $this->assertGreaterThan(0, $teamStats['daysWorked']);
        $this->assertSame('SVA Silks', $teamStats['ventures'][0]['label'] ?? null);
        $this->assertArrayNotHasKey('statuses', $teamStats);
        $this->assertArrayNotHasKey('busiestDays', $teamStats);
    }

    public function test_admin_employee_detail_includes_by_type_chart(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $employee = $this->employee();

        TimesheetEntry::create([
            'user_id' => $employee->id,
            'worked_on' => now()->startOfMonth()->toDateString(),
            'task' => 'Shoot',
            'task_type' => 'shooting',
            'venture' => 'SVA Silks',
            'minutes' => 120,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($admin)->get(route('timesheets.show', [
            $employee, 'month' => now()->format('Y-m'),
        ]));

        $response->assertOk()
            ->assertSee('Hours per day')
            ->assertSee('By client')
            ->assertSee('By type')
            ->assertSee('Shooting');

        $stats = $response->viewData('stats');
        $this->assertSame(120, $stats['totalMinutes']);
    }

    public function test_timesheet_venture_options_come_from_clients_only(): void
    {
        $employee = $this->employee();
        $this->seedClients();

        $response = $this->actingAs($employee)->get(route('my.timesheet'));

        $response->assertOk();
        $options = $response->viewData('ventureOptions');
        $values = collect($options)->pluck('value')->all();

        $this->assertSame(['Janet', 'Riya', 'SVA Silks', 'Thor Gym', 'All / Multiple Clients'], $values);
        $this->assertContains('Digital Harvest (Janet Hospitals) · Janet', collect($options)->pluck('label')->all());
        $response->assertSee('Client / Venture');
        $response->assertSee('Select client');
        $response->assertSee('All / Multiple Clients');
        $response->assertDontSee('Other…');
    }

    public function test_an_entry_can_be_logged_against_all_multiple_clients(): void
    {
        $employee = $this->employee();
        $this->seedClients();

        $this->actingAs($employee)->post(route('my.timesheet.store'), [
            'worked_on' => today()->toDateString(),
            'task' => 'Team meeting',
            'task_type' => 'other',
            'venture' => \App\Support\TimesheetVenture::ALL_CLIENTS,
            'minutes' => 30,
            'status' => 'completed',
        ])->assertRedirect();

        $this->assertSame('All / Multiple Clients', TimesheetEntry::firstOrFail()->venture);
    }

    public function test_selecting_a_client_venture_is_stored_on_the_entry(): void
    {
        $employee = $this->employee();
        $this->seedClients();

        $this->actingAs($employee)->post(route('my.timesheet.store'), [
            'worked_on' => today()->toDateString(),
            'task' => 'Shoot',
            'task_type' => 'shooting',
            'venture' => 'Riya',
            'minutes' => 60,
            'status' => 'completed',
        ])->assertRedirect();

        $this->assertSame('Riya', TimesheetEntry::firstOrFail()->venture);
    }

    public function test_infer_task_type_from_task_name(): void
    {
        $this->assertSame('shooting', TimesheetEntry::inferTaskType('Photo Shoot'));
        $this->assertSame('shooting', TimesheetEntry::inferTaskType('Story Video Shoot'));
        $this->assertSame('editing', TimesheetEntry::inferTaskType('Editing Corrections'));
        $this->assertSame('editing', TimesheetEntry::inferTaskType('Photo Edit'));
        $this->assertSame('posting', TimesheetEntry::inferTaskType('Post Schedule'));
        $this->assertSame('posting', TimesheetEntry::inferTaskType('Instagram Upload'));
        $this->assertSame('other', TimesheetEntry::inferTaskType('Client Call'));
    }

    public function test_posting_is_a_selectable_task_type(): void
    {
        $employee = $this->employee();
        $this->seedClients();

        $this->actingAs($employee)->post(route('my.timesheet.store'), [
            'worked_on' => today()->toDateString(),
            'task' => 'Upload reels',
            'task_type' => 'posting',
            'venture' => 'Riya',
            'minutes' => 45,
            'status' => 'completed',
        ])->assertRedirect();

        $entry = TimesheetEntry::firstOrFail();
        $this->assertSame('posting', $entry->task_type);

        $response = $this->actingAs($employee)->get(route('my.timesheet'));
        $response->assertOk()->assertSee('Posting');
    }

    public function test_client_venture_chart_excludes_empty_ventures(): void
    {
        $employee = $this->employee();
        $this->seedClients();

        TimesheetEntry::create([
            'user_id' => $employee->id,
            'worked_on' => now()->startOfMonth()->toDateString(),
            'task' => 'Shoot',
            'task_type' => 'shooting',
            'venture' => 'Riya',
            'minutes' => 60,
            'status' => 'completed',
        ]);
        TimesheetEntry::create([
            'user_id' => $employee->id,
            'worked_on' => now()->startOfMonth()->toDateString(),
            'task' => 'Misc',
            'task_type' => 'other',
            'venture' => null,
            'minutes' => 900,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($employee)->get(route('my.timesheet'));
        $stats = $response->viewData('stats');

        $labels = collect($stats['ventures'])->pluck('label')->all();
        $this->assertSame(['Riya Makeover Artisty · Riya'], $labels);
        $this->assertSame(60, $stats['ventures'][0]['minutes']);
        $this->assertSame(960, $stats['totalMinutes']);
        $response->assertSee('Shooting');
        $response->assertSee('Type');
        $response->assertDontSee('Unspecified');
    }

    public function test_messy_historical_ventures_normalise_onto_clients(): void
    {
        Client::factory()->create([
            'name' => 'Digital Harvest (Janet Hospitals)',
            'notion_venture' => 'Janet',
        ]);
        Client::factory()->create([
            'name' => 'DJ THANGA MAALIGAI',
            'notion_venture' => 'DJ',
        ]);
        Client::factory()->create([
            'name' => 'SVA Silks and Readymades',
            'notion_venture' => 'SVA Silks',
        ]);
        Client::factory()->create([
            'name' => 'SVA Gold and Diamonds',
            'notion_venture' => 'SVA Jewells',
        ]);
        Client::factory()->create([
            'name' => 'Riya Makeover Artisty',
            'notion_venture' => 'Riya',
        ]);

        $this->assertSame('Janet', \App\Support\TimesheetVenture::normalize('Janet - Sperm Kit'));
        $this->assertSame('DJ', \App\Support\TimesheetVenture::normalize('DJ - Opening'));
        $this->assertSame('SVA Silks', \App\Support\TimesheetVenture::normalize('SVA Website'));
        $this->assertSame('SVA Silks', \App\Support\TimesheetVenture::normalize('SW - KEERTHI SHIRT'));
        $this->assertSame('SVA Jewells', \App\Support\TimesheetVenture::normalize('SJ - Hall Mark'));
        $this->assertSame('Riya', \App\Support\TimesheetVenture::normalize('Riya-Smudge Proof'));
        $this->assertNull(\App\Support\TimesheetVenture::normalize('PR'));
        $this->assertNull(\App\Support\TimesheetVenture::normalize('IG & YT'));
        $this->assertNull(\App\Support\TimesheetVenture::normalize('All Ventures'));
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

    public function test_dashboard_links_to_the_full_timesheet_instead_of_repeating_its_charts(): void
    {
        $employee = $this->employee();

        TimesheetEntry::create([
            'user_id' => $employee->id,
            'worked_on' => now()->startOfMonth()->toDateString(),
            'task' => 'Shoot',
            'venture' => 'SVA Silks',
            'minutes' => 120,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($employee)->get(route('my.dashboard'));

        $response->assertOk()
            ->assertSee('View full timesheet')
            ->assertDontSee('This month by day')
            ->assertDontSee('Hours per day')
            ->assertDontSee('By client');
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

