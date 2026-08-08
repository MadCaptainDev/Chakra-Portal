<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\EmployeePoint;
use App\Models\TimesheetEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Employees have logins purely so they can fill in their own timesheet. The
 * whole point of the role is that they reach nothing else -- not the books,
 * not the salaries, and not each other's entries.
 */
class EmployeeAccessTest extends TestCase
{
    use RefreshDatabase;

    private function employee(array $attributes = []): User
    {
        return User::factory()->create($attributes + ['role' => User::ROLE_EMPLOYEE]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public static function adminRoutes(): array
    {
        return [
            'dashboard' => ['/dashboard'],
            'invoices' => ['/invoices'],
            'clients' => ['/clients'],
            'expenses' => ['/expenses'],
            'emi' => ['/emi'],
            'salaries' => ['/salaries'],
            'bills' => ['/bills'],
            'users' => ['/users'],
            'settings' => ['/settings'],
            'recurring' => ['/recurring'],
            'timesheets (admin view)' => ['/timesheets'],
            'announcements' => ['/announcements'],
        ];
    }

    /** @dataProvider adminRoutes */
    public function test_an_employee_is_refused_every_admin_area(string $url): void
    {
        $this->actingAs($this->employee())->get($url)->assertForbidden();
    }

    /** @dataProvider adminRoutes */
    public function test_an_admin_still_reaches_every_admin_area(string $url): void
    {
        $this->actingAs($this->admin())->get($url)->assertOk();
    }

    public function test_an_employee_reaches_their_own_area(): void
    {
        $employee = $this->employee();

        $this->actingAs($employee)->get(route('my.dashboard'))->assertOk();
        $this->actingAs($employee)->get(route('my.timesheet'))->assertOk();
        $this->actingAs($employee)->get(route('my.calendar'))->assertOk();
        $this->actingAs($employee)->get(route('profile.edit'))->assertOk();
    }

    public function test_employees_cannot_touch_each_others_entries(): void
    {
        $alice = $this->employee(['name' => 'Alice']);
        $bob = $this->employee(['name' => 'Bob']);

        $bobsEntry = TimesheetEntry::create([
            'user_id' => $bob->id,
            'worked_on' => today()->toDateString(),
            'task' => 'Shoot',
            'venture' => 'PR',
            'minutes' => 240,
            'status' => TimesheetEntry::STATUS_COMPLETED,
        ]);

        $this->actingAs($alice)
            ->put(route('my.timesheet.update', $bobsEntry), [
                'worked_on' => today()->toDateString(),
                'task' => 'Hijacked',
                'minutes' => 1,
                'status' => 'completed',
            ])
            ->assertNotFound();

        $this->actingAs($alice)
            ->delete(route('my.timesheet.destroy', $bobsEntry))
            ->assertNotFound();

        // Assert the row itself, not just the status code.
        $bobsEntry->refresh();
        $this->assertSame('Shoot', $bobsEntry->task);
        $this->assertSame(240, $bobsEntry->minutes);
        $this->assertDatabaseHas('timesheet_entries', ['id' => $bobsEntry->id]);
    }

    public function test_an_employee_only_sees_their_own_entries(): void
    {
        $alice = $this->employee();
        $bob = $this->employee();

        TimesheetEntry::create([
            'user_id' => $alice->id, 'worked_on' => today()->toDateString(),
            'task' => 'Alice Task', 'minutes' => 60, 'status' => 'completed',
        ]);
        TimesheetEntry::create([
            'user_id' => $bob->id, 'worked_on' => today()->toDateString(),
            'task' => 'Bob Task', 'minutes' => 60, 'status' => 'completed',
        ]);

        $response = $this->actingAs($alice)->get(route('my.timesheet'));

        $response->assertSee('Alice Task');
        $response->assertDontSee('Bob Task');
    }

    public function test_an_employee_only_sees_their_own_points(): void
    {
        $alice = $this->employee();
        $bob = $this->employee();
        $admin = $this->admin();

        EmployeePoint::create([
            'user_id' => $alice->id, 'period' => now()->startOfMonth()->toDateString(),
            'points' => 85, 'note' => 'Alice did well', 'awarded_by' => $admin->id,
        ]);
        EmployeePoint::create([
            'user_id' => $bob->id, 'period' => now()->startOfMonth()->toDateString(),
            'points' => 42, 'note' => 'Bob remark', 'awarded_by' => $admin->id,
        ]);

        $response = $this->actingAs($alice)->get(route('my.dashboard'));

        $response->assertSee('Alice did well');
        $response->assertDontSee('Bob remark');
    }

    public function test_employees_see_only_active_announcements(): void
    {
        $employee = $this->employee();
        $admin = $this->admin();

        Announcement::create(['title' => 'Live Notice', 'body' => 'Visible', 'is_active' => true, 'created_by' => $admin->id]);
        Announcement::create(['title' => 'Draft Notice', 'body' => 'Hidden', 'is_active' => false, 'created_by' => $admin->id]);

        $response = $this->actingAs($employee)->get(route('my.dashboard'));

        $response->assertSee('Live Notice');
        $response->assertDontSee('Draft Notice');
    }

    public function test_an_employee_cannot_award_points(): void
    {
        $employee = $this->employee();
        $other = $this->employee();

        $this->actingAs($employee)
            ->post(route('timesheets.award', $other), [
                'month' => now()->startOfMonth()->toDateString(),
                'points' => 999,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('employee_points', 0);
    }

    public function test_the_root_url_sends_each_role_to_its_own_home(): void
    {
        $this->actingAs($this->admin())->get('/')->assertRedirect(route('dashboard'));
        $this->actingAs($this->employee())->get('/')->assertRedirect(route('my.dashboard'));
    }

    public function test_the_role_column_defaults_to_least_privilege(): void
    {
        // Inserted around the model, as a seeder or a manual query would. A row
        // that forgets to set a role must not come out as an admin.
        \Illuminate\Support\Facades\DB::table('users')->insert([
            'name' => 'Raw Insert',
            'email' => 'raw@example.com',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::where('email', 'raw@example.com')->firstOrFail();

        $this->assertSame(User::ROLE_EMPLOYEE, $user->role);
        $this->assertFalse($user->isAdmin());
    }

    public function test_no_employee_page_links_to_an_area_they_cannot_reach(): void
    {
        $employee = $this->employee();

        foreach (['my.dashboard', 'my.timesheet', 'my.calendar'] as $route) {
            $html = $this->actingAs($employee)->get(route($route))->getContent();

            preg_match_all('~href="https?://[^/]+(/[a-z0-9/_-]*)"~i', $html, $matches);

            foreach (array_unique($matches[1]) as $path) {
                $this->assertNotSame('/dashboard', $path,
                    "{$route} links to the admin dashboard, which 403s for an employee.");
            }
        }
    }

    public function test_a_factory_user_represents_staff(): void
    {
        $this->assertTrue(User::factory()->create()->isAdmin());
        $this->assertFalse(User::factory()->employee()->create()->isAdmin());
    }
}
