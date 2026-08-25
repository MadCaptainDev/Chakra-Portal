<?php

namespace Tests\Feature;

use App\Models\TimesheetDay;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Support\TimesheetAnomalies;
use App\Support\TimesheetVenture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The employee's own view of what looks wrong in their timesheet.
 *
 * The point of showing it to them rather than only to an admin: they are the
 * only person who knows what actually happened that day. An admin correcting a
 * guess is worse than the wrong number.
 */
class TimesheetSelfFixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function entry(User $user, array $overrides = []): TimesheetEntry
    {
        return TimesheetEntry::create(array_merge([
            'user_id' => $user->id,
            'worked_on' => today()->toDateString(),
            'task' => 'Editing',
            'task_type' => TimesheetEntry::TASK_EDITING,
            'venture' => TimesheetVenture::ALL_CLIENTS,
            'minutes' => 120,
        ], $overrides));
    }

    public function test_an_employee_is_shown_their_own_suspect_entries(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $this->entry($user, ['minutes' => 1440, 'task' => 'Janet edit']);

        $this->actingAs($user)->get(route('my.timesheet'))
            ->assertOk()
            ->assertSee('a second look')
            ->assertSee('Exactly 24 hours')
            ->assertSee('Fix this');
    }

    public function test_an_employee_is_never_shown_somebody_elses(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $colleague = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Someone Else']);

        $this->entry($colleague, ['minutes' => 1440, 'task' => 'Their broken entry']);

        $this->actingAs($user)->get(route('my.timesheet'))
            ->assertOk()
            ->assertDontSee('Their broken entry')
            ->assertDontSee('Exactly 24 hours');
    }

    public function test_the_scoping_happens_in_the_query_not_afterwards(): void
    {
        $user = User::factory()->create();
        $colleague = User::factory()->create();

        $this->entry($user, ['minutes' => 1440]);
        $this->entry($colleague, ['minutes' => 1440]);

        $flags = TimesheetAnomalies::between(
            today()->startOfMonth(),
            today()->endOfMonth(),
            $user
        );

        // One bug upstream must not be able to leak a colleague's rows.
        $this->assertTrue($flags->every(fn (array $flag) => $flag['user_id'] === $user->id));
    }

    public function test_the_fix_link_points_at_the_entry_it_is_about(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $entry = $this->entry($user, ['minutes' => 1440]);

        // The anchor is what scrolls to the row and opens its editor.
        $this->actingAs($user)->get(route('my.timesheet'))
            ->assertOk()
            ->assertSee('href="#entry-'.$entry->id.'"', false)
            ->assertSee('id="entry-'.$entry->id.'"', false);
    }

    public function test_a_clean_month_shows_no_panel(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $this->entry($user, ['minutes' => 120]);

        $this->actingAs($user)->get(route('my.timesheet'))
            ->assertOk()
            ->assertDontSee('a second look');
    }

    public function test_earlier_months_are_counted_so_a_clean_month_does_not_read_as_done(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $this->entry($user, [
            'worked_on' => today()->startOfMonth()->subMonthNoOverflow()->toDateString(),
            'minutes' => 1440,
        ]);

        $this->actingAs($user)->get(route('my.timesheet'))
            ->assertOk()
            ->assertSee('in earlier months');
    }

    public function test_the_dashboard_nudges_them_towards_the_timesheet(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $this->entry($user, ['minutes' => 1440]);

        $this->actingAs($user)->get(route('my.dashboard'))
            ->assertOk()
            ->assertSee('a second look')
            ->assertSee(route('my.timesheet'), false);
    }

    public function test_the_dashboard_stays_quiet_when_there_is_nothing_to_fix(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $this->entry($user, ['minutes' => 120]);

        $this->actingAs($user)->get(route('my.dashboard'))
            ->assertOk()
            ->assertDontSee('a second look');
    }

    public function test_correcting_the_entry_clears_the_flag(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $entry = $this->entry($user, ['minutes' => 1440]);

        $this->actingAs($user)->put(route('my.timesheet.update', $entry), [
            'worked_on' => $entry->worked_on->toDateString(),
            'task' => 'Editing',
            'task_type' => TimesheetEntry::TASK_EDITING,
            'venture' => TimesheetVenture::ALL_CLIENTS,
            'minutes' => 180,
        ])->assertSessionHasNoErrors();

        $this->actingAs($user)->get(route('my.timesheet'))
            ->assertOk()
            ->assertDontSee('Exactly 24 hours');
    }

    public function test_an_unvalidated_suspect_entry_still_needs_a_second_look(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $this->entry($user, ['minutes' => 1440, 'task' => 'Janet edit']);

        // No TimesheetDay decision yet — the odd figure is still on Second Look.
        $this->actingAs($user)->get(route('my.timesheet'))
            ->assertOk()
            ->assertSee('a second look')
            ->assertSee('Exactly 24 hours');

        $this->actingAs($user)->get(route('my.dashboard'))
            ->assertOk()
            ->assertSee('a second look');
    }

    public function test_manager_approval_removes_the_day_from_second_look(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Manager Person']);
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $user->managers()->attach($manager);

        $this->entry($user, ['minutes' => 1440, 'task' => 'Janet edit']);

        $this->actingAs($manager)->post(route('timesheets.day', $user), [
            'worked_on' => today()->toDateString(),
            'review_state' => TimesheetDay::APPROVED,
        ])->assertRedirect();

        // Sometimes a long day is genuine; once signed off, stop asking.
        $this->actingAs($user)->get(route('my.timesheet'))
            ->assertOk()
            ->assertDontSee('a second look')
            ->assertDontSee('Exactly 24 hours');

        $this->actingAs($user)->get(route('my.dashboard'))
            ->assertOk()
            ->assertDontSee('a second look');
    }

    public function test_admin_approval_removes_the_day_from_second_look(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->entry($user, ['minutes' => 1440, 'task' => 'Janet edit']);

        $this->actingAs($admin)->post(route('timesheets.day', $user), [
            'worked_on' => today()->toDateString(),
            'review_state' => TimesheetDay::APPROVED,
        ])->assertRedirect();

        $this->actingAs($user)->get(route('my.timesheet'))
            ->assertOk()
            ->assertDontSee('a second look')
            ->assertDontSee('Exactly 24 hours');
    }

    public function test_a_rejected_day_still_shows_on_second_look(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $user->managers()->attach($manager);

        $this->entry($user, ['minutes' => 1440, 'task' => 'Janet edit']);

        $this->actingAs($manager)->post(route('timesheets.day', $user), [
            'worked_on' => today()->toDateString(),
            'review_state' => TimesheetDay::REJECTED,
            'review_note' => 'That cannot be a full calendar day of editing.',
        ])->assertRedirect();

        // Sent back means it still needs fixing — keep the Second Look panel.
        $this->actingAs($user)->get(route('my.timesheet'))
            ->assertOk()
            ->assertSee('a second look')
            ->assertSee('Exactly 24 hours');
    }

    public function test_approval_only_hides_that_day_not_other_suspect_days(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');

        $manager = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $user->managers()->attach($manager);

        $this->entry($user, [
            'worked_on' => '2026-08-15',
            'minutes' => 1440,
            'task' => 'Approved odd day',
        ]);
        $this->entry($user, [
            'worked_on' => '2026-08-14',
            'minutes' => 1440,
            'task' => 'Still open odd day',
        ]);

        $this->actingAs($manager)->post(route('timesheets.day', $user), [
            'worked_on' => '2026-08-15',
            'review_state' => TimesheetDay::APPROVED,
        ])->assertRedirect();

        $flags = TimesheetAnomalies::between(
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-08-31'),
            $user
        );

        $this->assertCount(1, $flags);
        $this->assertSame('Still open odd day', $flags->first()['task']);

        $this->actingAs($user)
            ->get(route('my.timesheet', ['month' => '2026-08']))
            ->assertOk()
            ->assertSee('1 entry here')
            ->assertSee('a second look')
            ->assertSee('Still open odd day');

        Carbon::setTestNow();
    }

    public function test_the_wording_does_not_accuse_anybody(): void
    {
        $user = User::factory()->create();
        $this->entry($user, ['minutes' => 1440]);

        $body = $this->actingAs($user)->get(route('my.timesheet'))->getContent();

        // Almost every one of these is a job's length typed where hours were
        // asked for. The screen must not read as if it thinks otherwise.
        foreach (['fraud', 'lying', 'lied', 'false claim', 'dishonest', 'cheat'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $body);
        }
    }
}
