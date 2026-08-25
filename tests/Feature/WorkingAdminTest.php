<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Todo;
use App\Models\TodoUpdate;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Support\TimesheetVenture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An admin linked to a Salaries row keeps full admin access, but logs hours
 * and personal to-dos through /my/* and appears on team timesheet / to-do boards.
 */
class WorkingAdminTest extends TestCase
{
    use RefreshDatabase;

    private function workingAdmin(string $name = 'Aron Sham'): User
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'name' => $name,
            'email' => 'aron@example.com',
        ]);

        Expense::create([
            'user_id' => $admin->id,
            'name' => $name,
            'type' => Expense::TYPE_SALARY,
            'amount' => 50000,
            'is_active' => true,
        ]);

        return $admin->fresh();
    }

    private function pureAdmin(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'name' => 'Studio Admin',
            'email' => 'admin@example.com',
        ]);
    }

    public function test_a_salary_linked_admin_logs_work_and_a_pure_admin_does_not(): void
    {
        $working = $this->workingAdmin();
        $pure = $this->pureAdmin();

        $this->assertTrue($working->isAdmin());
        $this->assertTrue($working->logsWork());
        $this->assertFalse($working->isEmployee());

        $this->assertTrue($pure->isAdmin());
        $this->assertFalse($pure->logsWork());
    }

    public function test_working_admin_sidebar_offers_personal_todo_and_timesheet_links(): void
    {
        $working = $this->workingAdmin();
        $report = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Report']);
        $working->reports()->attach($report);

        $response = $this->actingAs($working->fresh())->get(route('dashboard'));

        $response->assertOk()
            ->assertSee(route('my.dashboard'))
            ->assertSee(route('my.todos'))
            ->assertSee(route('my.timesheet'))
            ->assertSee(route('my.calendar'))
            ->assertSee(route('my.team'))
            ->assertSee('My Dashboard', false)
            ->assertSee('My To-dos', false)
            ->assertSee('My Timesheet', false)
            ->assertSee('Team To-dos', false)
            ->assertSee(route('dashboard'));
    }

    public function test_pure_admin_sidebar_does_not_offer_my_work_links(): void
    {
        $response = $this->actingAs($this->pureAdmin())->get(route('dashboard'));

        $response->assertOk()
            ->assertDontSee(route('my.todos'))
            ->assertDontSee(route('my.timesheet'))
            ->assertSee('To-dos', false)
            ->assertDontSee('My To-dos', false);
    }

    public function test_working_admin_can_use_personal_timesheet_and_todos(): void
    {
        $working = $this->workingAdmin();

        $this->actingAs($working)->get(route('my.dashboard'))->assertOk();
        $this->actingAs($working)->get(route('my.timesheet'))->assertOk();
        $this->actingAs($working)->get(route('my.todos'))->assertOk();
        $this->actingAs($working)->get(route('my.calendar'))->assertOk();

        $this->actingAs($working)->post(route('my.timesheet.store'), [
            'worked_on' => today()->toDateString(),
            'task' => 'Edit cutdowns',
            'task_type' => 'editing',
            'venture' => TimesheetVenture::ALL_CLIENTS,
            'minutes' => 60,
        ])->assertRedirect();

        $this->assertDatabaseHas('timesheet_entries', [
            'user_id' => $working->id,
            'task' => 'Edit cutdowns',
        ]);
    }

    public function test_a_pure_admin_is_refused_the_personal_my_area(): void
    {
        $pure = $this->pureAdmin();

        $this->actingAs($pure)->get(route('my.dashboard'))->assertForbidden();
        $this->actingAs($pure)->get(route('my.todos'))->assertForbidden();
        $this->actingAs($pure)->get(route('my.timesheet'))->assertForbidden();
        $this->actingAs($pure)->get(route('my.calendar'))->assertForbidden();
    }

    public function test_working_admin_appears_on_admin_timesheet_and_todo_boards(): void
    {
        $working = $this->workingAdmin();
        $viewer = $this->pureAdmin();
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'name' => 'Sanjai']);

        TimesheetEntry::create([
            'user_id' => $working->id,
            'worked_on' => now()->startOfMonth()->toDateString(),
            'task' => 'Aron work',
            'venture' => TimesheetVenture::ALL_CLIENTS,
            'minutes' => 90,
            'status' => TimesheetEntry::STATUS_COMPLETED,
        ]);

        $todo = Todo::create([
            'user_id' => $working->id,
            'assigned_by_id' => $working->id,
            'title' => 'Aron personal todo',
            'venture' => TimesheetVenture::ALL_CLIENTS,
            'starts_on' => today()->toDateString(),
            'due_on' => today()->toDateString(),
        ]);
        TodoUpdate::record($todo, $working, TodoUpdate::CREATED, ['to_status' => $todo->status]);

        $timesheets = $this->actingAs($viewer)->get(route('timesheets.index'));
        $timesheets->assertOk()->assertSee('Aron Sham');
        $this->assertTrue(
            $timesheets->viewData('rows')->contains(fn (array $row) => $row['employee']->id === $working->id)
        );
        $this->assertFalse(
            $timesheets->viewData('rows')->contains(fn (array $row) => $row['employee']->id === $viewer->id)
        );

        $this->actingAs($viewer)->get(route('timesheets.show', $working))->assertOk()->assertSee('Aron work');
        $this->actingAs($viewer)->get(route('timesheets.show', $viewer))->assertNotFound();

        $board = $this->actingAs($viewer)->get(route('todos.index'));
        $board->assertOk()->assertSee('Aron personal todo');
        $this->assertEqualsCanonicalizing(
            [$working->id, $employee->id],
            $board->viewData('team')->pluck('id')->all()
        );
    }
}
