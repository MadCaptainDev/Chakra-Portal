<?php

namespace Tests\Feature;

use App\Models\TimesheetDay;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Support\TeamPulse;
use App\Support\TimesheetVenture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TimesheetDecideQueueTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function entry(User $user, array $overrides = []): TimesheetEntry
    {
        return TimesheetEntry::create(array_merge([
            'user_id' => $user->id,
            'worked_on' => now()->toDateString(),
            'task' => 'Edit reel',
            'task_type' => TimesheetEntry::TASK_EDITING,
            'venture' => TimesheetVenture::ALL_CLIENTS,
            'minutes' => 120,
            'status' => TimesheetEntry::STATUS_COMPLETED,
        ], $overrides));
    }

    public function test_index_lists_undecided_days_in_decide_queue(): void
    {
        $admin = $this->admin();
        $staff = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Queue Person']);
        $this->entry($staff, ['task' => 'Colour grade']);

        $this->actingAs($admin)
            ->get(route('timesheets.index'))
            ->assertOk()
            ->assertSee('Days to decide')
            ->assertSee('Queue Person')
            ->assertSee('Colour grade')
            ->assertViewHas('pendingDays');
    }

    public function test_approved_day_leaves_the_decide_queue(): void
    {
        $admin = $this->admin();
        $staff = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Done Person']);
        $this->entry($staff);

        $this->actingAs($admin)
            ->post(route('timesheets.day', $staff), [
                'worked_on' => now()->toDateString(),
                'review_state' => TimesheetDay::APPROVED,
            ])
            ->assertRedirect();

        $html = $this->actingAs($admin)->get(route('timesheets.index'))->assertOk()->getContent();
        $queue = Str::before(Str::after($html, 'Days to decide'), 'Who worked most');

        if (! str_contains($html, 'Who worked most')) {
            $queue = Str::after($html, 'Days to decide');
        }

        $this->assertStringContainsString("You're caught up", $queue);
        $this->assertStringNotContainsString('Done Person', $queue);
    }

    public function test_manager_only_sees_reports_in_decide_queue(): void
    {
        $manager = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Manager One']);
        $report = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'My Report']);
        $other = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Other Staff']);
        $report->managers()->attach($manager);
        $this->entry($report);
        $this->entry($other);

        $this->assertTrue($manager->managesTimesheetOf($report));
        $this->assertFalse($manager->managesTimesheetOf($other));

        $pending = TeamPulse::pendingDays(
            User::whoLogWork()->orderBy('name')->get(),
            now()->startOfMonth(),
            $manager
        );

        $names = $pending->map(fn (array $row) => $row['employee']->name)->all();
        $this->assertContains('My Report', $names);
        $this->assertNotContains('Other Staff', $names);
    }

    public function test_reject_from_index_requires_a_reason(): void
    {
        $admin = $this->admin();
        $staff = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $this->entry($staff);

        $this->actingAs($admin)
            ->from(route('timesheets.index'))
            ->post(route('timesheets.day', $staff), [
                'worked_on' => now()->toDateString(),
                'review_state' => TimesheetDay::REJECTED,
            ])
            ->assertSessionHasErrors('review_note');
    }
}
