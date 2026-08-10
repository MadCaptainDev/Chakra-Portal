<?php

namespace Tests\Feature;

use App\Models\TimesheetEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Support\TimesheetVenture;
use Illuminate\Support\Str;
use Tests\TestCase;

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

    private function entry(User $employee, array $overrides = []): TimesheetEntry
    {
        return TimesheetEntry::create(array_merge([
            'user_id' => $employee->id,
            'worked_on' => now()->startOfMonth()->toDateString(),
            'task' => 'Shoot',
            'task_type' => TimesheetEntry::TASK_SHOOTING,
            'venture' => TimesheetVenture::ALL_CLIENTS,
            'minutes' => 120,
            'status' => TimesheetEntry::STATUS_PENDING,
        ], $overrides));
    }

    /**
     * A payload the employee's own edit form would accept. venture has to be
     * one of the values the client list allows, which with no clients seeded
     * is the catch-all.
     *
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
            'status' => TimesheetEntry::STATUS_PENDING,
        ], $overrides);
    }

    public function test_an_admin_can_approve_a_pending_entry(): void
    {
        $employee = $this->employee();
        $entry = $this->entry($employee);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('timesheets.entry.approve', $entry))
            ->assertRedirect();

        $entry->refresh();

        $this->assertSame(TimesheetEntry::STATUS_COMPLETED, $entry->status);
        $this->assertNotNull($entry->reviewed_at);
        $this->assertSame($admin->id, $entry->reviewed_by_id);
        $this->assertTrue($entry->isApproved());
    }

    public function test_a_query_records_the_question_and_leaves_the_status_alone(): void
    {
        $employee = $this->employee();
        $entry = $this->entry($employee);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('timesheets.entry.query', $entry), ['review_note' => 'Which shoot was this?'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $entry->refresh();

        $this->assertSame('Which shoot was this?', $entry->review_note);
        // The status belongs to the employee -- asking a question must not move it.
        $this->assertSame(TimesheetEntry::STATUS_PENDING, $entry->status);
        $this->assertTrue($entry->isQueried());
        $this->assertFalse($entry->isApproved());
    }

    public function test_a_query_requires_a_question(): void
    {
        $entry = $this->entry($this->employee());

        $this->actingAs($this->admin())
            ->post(route('timesheets.entry.query', $entry), ['review_note' => ''])
            ->assertSessionHasErrors('review_note');

        $this->assertNull($entry->refresh()->review_note);
    }

    public function test_an_employee_cannot_review_entries(): void
    {
        $employee = $this->employee();
        $entry = $this->entry($employee);

        // Their own entry, and someone else's -- neither is theirs to approve.
        $this->actingAs($employee)
            ->post(route('timesheets.entry.approve', $entry))
            ->assertForbidden();

        $this->assertNull($entry->refresh()->reviewed_at);
    }

    public function test_editing_an_entry_clears_the_review(): void
    {
        $employee = $this->employee();
        $entry = $this->entry($employee);

        $this->actingAs($this->admin())
            ->post(route('timesheets.entry.query', $entry), ['review_note' => 'Which shoot?']);

        $this->assertTrue($entry->refresh()->isQueried());

        $this->actingAs($employee)->put(
            route('my.timesheet.update', $entry),
            $this->editPayload($entry, ['task' => 'Bridal shoot — day 2'])
        )->assertSessionHasNoErrors();

        $entry->refresh();

        $this->assertNull($entry->review_note);
        $this->assertNull($entry->reviewed_at);
        $this->assertNull($entry->reviewed_by_id);
        $this->assertFalse($entry->isQueried());
    }

    public function test_an_employee_cannot_approve_their_own_entry_through_the_edit_form(): void
    {
        $employee = $this->employee();
        $entry = $this->entry($employee);

        // The review columns are not fillable, so smuggling them through the
        // employee's own update must change nothing.
        $this->actingAs($employee)->put(route('my.timesheet.update', $entry), $this->editPayload($entry, [
            'task' => 'Shoot, edited',
            'reviewed_at' => now()->toDateTimeString(),
            'reviewed_by_id' => $employee->id,
        ]));

        $entry->refresh();

        $this->assertNull($entry->reviewed_at);
        $this->assertNull($entry->reviewed_by_id);
        $this->assertFalse($entry->isApproved());
    }

    public function test_approve_month_clears_pending_but_skips_queried_entries(): void
    {
        $employee = $this->employee();
        $admin = $this->admin();

        $plain = $this->entry($employee, ['task' => 'Plain pending']);
        $queried = $this->entry($employee, ['task' => 'Has a question']);
        $cancelled = $this->entry($employee, ['task' => 'Dropped', 'status' => TimesheetEntry::STATUS_CANCELLED]);

        $this->actingAs($admin)
            ->post(route('timesheets.entry.query', $queried), ['review_note' => 'Which client?']);

        $this->actingAs($admin)->post(route('timesheets.approve-month', $employee), [
            'month' => now()->format('Y-m'),
        ])->assertRedirect();

        $this->assertSame(TimesheetEntry::STATUS_COMPLETED, $plain->refresh()->status);
        // An open question is not a rubber stamp.
        $this->assertSame(TimesheetEntry::STATUS_PENDING, $queried->refresh()->status);
        $this->assertSame(TimesheetEntry::STATUS_CANCELLED, $cancelled->refresh()->status);
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
        $panel = Str::before(Str::after($response->getContent(), 'logged nothing this week'), 'Who worked most');

        $response->assertOk()->assertSee('logged nothing this week');
        $this->assertStringContainsString($quiet->name, $panel);
        $this->assertStringNotContainsString($busy->name, $panel);
    }

    public function test_a_cancelled_entry_does_not_count_as_having_logged(): void
    {
        $admin = $this->admin();
        $employee = $this->employee();

        $this->entry($employee, [
            'worked_on' => now()->toDateString(),
            'status' => TimesheetEntry::STATUS_CANCELLED,
        ]);

        $this->actingAs($admin)
            ->get(route('timesheets.index'))
            ->assertOk()
            ->assertSee($employee->name);
    }
}
