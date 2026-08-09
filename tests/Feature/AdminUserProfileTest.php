<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminUserProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_edit_employee_profile(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create();
        $employee = User::factory()->employee()->create([
            'name' => 'Old Name',
            'bio' => null,
        ]);

        $file = UploadedFile::fake()->image('staff.jpg', 180, 180);

        $response = $this
            ->actingAs($admin)
            ->put(route('users.update', $employee), [
                'name' => 'New Name',
                'email' => $employee->email,
                'phone' => '9998887777',
                'bio' => 'Edited by admin',
                'role' => User::ROLE_EMPLOYEE,
                'avatar' => $file,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('users.edit', $employee));

        $employee->refresh();

        $this->assertSame('New Name', $employee->name);
        $this->assertSame('Edited by admin', $employee->bio);
        $this->assertSame('9998887777', $employee->phone);
        $this->assertNotNull($employee->avatar_path);
        Storage::disk('public')->assertExists(substr($employee->avatar_path, strlen('storage/')));
    }

    public function test_admin_can_update_linked_profile_from_salary_page(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create();
        $login = User::factory()->employee()->create(['name' => 'Kanishka']);
        $salary = Expense::create([
            'user_id' => $login->id,
            'name' => 'Kanishka',
            'type' => Expense::TYPE_SALARY,
            'role' => 'Editor',
            'amount' => 15000,
            'is_active' => true,
        ]);

        $response = $this
            ->actingAs($admin)
            ->put(route('salaries.update', $salary), [
                'name' => 'Kanishka',
                'role' => 'Lead Editor',
                'phone' => '1112223333',
                'bio' => 'From payroll edit',
                'is_active' => '1',
                'avatar' => UploadedFile::fake()->image('face.png'),
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('salaries.show', $salary));

        $login->refresh();
        $salary->refresh();

        $this->assertSame('Lead Editor', $salary->role);
        $this->assertSame('1112223333', $salary->phone);
        $this->assertSame('From payroll edit', $login->bio);
        $this->assertSame('1112223333', $login->phone);
        $this->assertNotNull($login->avatar_path);
    }

    public function test_employee_cannot_edit_other_users(): void
    {
        $employee = User::factory()->employee()->create();
        $other = User::factory()->employee()->create();

        $this->actingAs($employee)
            ->get(route('users.edit', $other))
            ->assertForbidden();

        $this->actingAs($employee)
            ->put(route('users.update', $other), [
                'name' => 'Hacked',
                'email' => $other->email,
                'role' => User::ROLE_EMPLOYEE,
            ])
            ->assertForbidden();
    }

    public function test_admin_can_set_employee_password(): void
    {
        $admin = User::factory()->create();
        $employee = User::factory()->employee()->create();

        $response = $this
            ->actingAs($admin)
            ->put(route('users.password', $employee), [
                'password' => 'new-secret-pass',
                'password_confirmation' => 'new-secret-pass',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('users.edit', $employee));

        $this->assertTrue(
            \Illuminate\Support\Facades\Hash::check('new-secret-pass', $employee->fresh()->password)
        );
    }

    public function test_employee_cannot_set_another_users_password(): void
    {
        $employee = User::factory()->employee()->create();
        $other = User::factory()->employee()->create();

        $this->actingAs($employee)
            ->put(route('users.password', $other), [
                'password' => 'new-secret-pass',
                'password_confirmation' => 'new-secret-pass',
            ])
            ->assertForbidden();
    }
}
