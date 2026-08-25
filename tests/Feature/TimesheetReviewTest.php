<?php

namespace Tests\Feature;

use App\Models\TimesheetDay;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Support\TimesheetVenture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Review as the admin screens see it: one decision per day, and what an
 * employee is shown about their own.
 *
 * Who is allowed to decide is TimesheetApprovalTest's ground; this is about
 * what reaches the page.
 */
class TimesheetReviewTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function employee(): User
    {
        return User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function entry(User $employee, array $overrides = []): TimesheetEntry
    {
        return TimesheetEntry::create(array_merge([
            'user_id' => $employee->id,
            'worked_on' => now()->startOfMonth()->toDateString(),
            'task' => 'Shoot',
            'task_type' => TimesheetEntry::TASK_SHOOTING,
            'venture' => TimesheetVenture::ALL_CLIENTS,
            'minutes' => 120,
        ], $overrides));
    }

    /**
     * A payload the employee's own edit form would accept. venture has to be
     * one of the values the client list allows, which with no clients seeded
     * is the catch-all.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function editPayload(TimesheetEntry $entry, array $overrides = []): array
    {
        return array_merge([
            'worked_on' => $entry->worked_on->toDateString(),
            'task' => $entry->task,
            'task_type' => TimesheetEntry::TASK_SHOOTING,
            'venture' => TimesheetVenture::ALL_CLIENTS,
            'minutes' => 120,
        ], $overrides);
    }

    public function test_an_admin_can_accept_a_day_from_the_employee_screen(): void
    {
        $employee = $this->employee();
        $entry = $this->entry($employee);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('timesheets.day', $employee), [
            'worked_on' => $entry->worked_on->toDateString(),
            'review_state' => TimesheetDay::APPROVED,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $decision = TimesheetDay::firstOrFail();

        $this->assertTrue($decision->isApproved());
        $this->assertNotNull($decision->reviewed_at);
        $this->assertSame($admin->id, $decision->reviewed_by_id);
    }

    public function test_an_employee_cannot_decide_on_their_own_day(): void
    {
        $employee = $this->employee();
        $entry = $this->entry($employee);

        $this->actingAs($employee)->post(route('timesheets.day', $employee), [
            'worked_on' => $entry->worked_on->toDateString(),
            'review_state' => TimesheetDay::APPROVED,
        ])->assertForbidden();

        $this->assertSame(0, TimesheetDay::count());
    }

    public function test_an_unknown_review_state_is_refused(): void
    {
        $employee = $this->employee();
        $entry = $this->entry($employee);

        $this->actingAs($this->admin())->post(route('timesheets.day', $employee), [
            'worked_on' => $entry->worked_on->toDateString(),
            'review_state' => 'approved-ish',
        ])->assertSessionHasErrors('review_state');

        $this->assertSame(0, TimesheetDay::count());
    }

    public function test_the_employee_sees_why_their_day_was_sent_back(): void
    {
        $employee = $this->employee();
        $entry = $this->entry($employee, ['worked_on' => now()->toDateString()]);

        $this->actingAs($this->admin())->post(route('timesheets.day', $employee), [
            'worked_on' => $entry->worked_on->toDateString(),
            'review_state' => TimesheetDay::REJECTED,
            'review_note' => 'That was the SVA shoot, not Aachi.',
        ]);

        $this->actingAs($employee)->get(route('my.timesheet'))
            ->assertOk()
            ->assertSee('That was the SVA shoot, not Aachi.');
    }

    public function test_a_day_nobody_has_decided_reads_as_under_review(): void
    {
        $employee = $this->employee();
        $this->entry($employee, ['worked_on' => now()->toDateString()]);

        $this->actingAs($employee)->get(route('my.timesheet'))
            ->assertOk()
            ->assertSee('Under review');
    }

    public function test_an_employee_cannot_set_their_own_entry_status(): void
    {
        $employee = $this->employee();
        $entry = $this->entry($employee);

        // status is no longer part of the form. Smuggling it through the
        // employee's own update must change nothing -- it is only ever written
        // by the spreadsheet importer now.
        $this->actingAs($employee)->put(route('my.timesheet.update', $entry), $this->editPayload($entry, [
            'task' => 'Shoot, edited',
            'status' => TimesheetEntry::STATUS_CANCELLED,
        ]))->assertSessionHasNoErrors();

        $this->assertSame(TimesheetEntry::STATUS_COMPLETED, $entry->refresh()->status);
    }

    public function test_the_team_list_names_who_logged_nothing_this_week(): void
    {
        $admin = $this->admin();
        // Fixed names: faker throws up apostrophes, which come back HTML-escaped
        // and make a substring assertion fail for the wrong reason.
        $quiet = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Quiet Person']);
        $busy = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Busy Person']);

        $this->entry($busy, ['worked_on' => now()->toDateString()]);

        $response = $this->actingAs($admin)->get(route('timesheets.index'));

        // The busy employee's name still appears further down in the ranked
        // list, so the assertion is about who the panel itself names.
        // Decide queue sits between Chase and Charts — stop the panel there.
        $panel = Str::before(Str::after($response->getContent(), 'logged nothing this week'), 'Days to decide');

        $response->assertOk()->assertSee('logged nothing this week');
        $this->assertStringContainsString($quiet->name, $panel);
        $this->assertStringNotContainsString($busy->name, $panel);
    }

    public function test_a_cancelled_entry_does_not_count_as_having_logged(): void
    {
        $admin = $this->admin();
        $employee = $this->employee();

        $this->entry($employee, ['worked_on' => now()->toDateString()])
            ->forceFill(['status' => TimesheetEntry::STATUS_CANCELLED])
            ->save();

        $this->actingAs($admin)
            ->get(route('timesheets.index'))
            ->assertOk()
            ->assertSee($employee->name);
    }
}
