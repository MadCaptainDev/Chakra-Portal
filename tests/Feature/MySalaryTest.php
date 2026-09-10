<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\SalaryHike;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An employee's own read-only view of their salary and raise history --
 * scoped to the signed-in user, no salaries.* module permission required.
 */
class MySalaryTest extends TestCase
{
    use RefreshDatabase;

    private function employeeWithSalary(float $amount = 15000): User
    {
        $user = User::factory()->employee()->create(['name' => 'Kanishka']);

        Expense::create([
            'user_id' => $user->id,
            'name' => $user->name,
            'type' => Expense::TYPE_SALARY,
            'amount' => $amount,
            'is_active' => true,
        ]);

        return $user->fresh();
    }

    public function test_an_employee_sees_their_own_current_salary_and_hikes(): void
    {
        $user = $this->employeeWithSalary(15000);

        SalaryHike::create([
            'expense_id' => $user->employeeRecord->id,
            'previous_amount' => 12000,
            'new_amount' => 15000,
            'effective_on' => '2026-09-01',
            'reason' => 'Annual review',
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->get(route('my.salary'));

        $response->assertOk();
        $response->assertSee('15,000.00');
        $response->assertSee('12,000.00');
        $response->assertSee('Annual review');
    }

    public function test_an_employee_with_no_linked_salary_sees_an_empty_state(): void
    {
        $user = User::factory()->employee()->create();

        $response = $this->actingAs($user)->get(route('my.salary'));

        $response->assertOk();
        $response->assertViewHas('employee', null);
    }

    public function test_an_employee_cannot_reach_another_employees_salary_page(): void
    {
        $mine = $this->employeeWithSalary(15000);
        $theirs = $this->employeeWithSalary(50000);

        // The page is not parameterised by employee id at all -- it is
        // always "the signed-in user's own record" -- so there is no id to
        // even try swapping in. Confirms it renders only what belongs to
        // whoever is logged in.
        $response = $this->actingAs($mine)->get(route('my.salary'));

        $response->assertOk();
        $response->assertSee('15,000.00');
        $response->assertDontSee('50,000.00');
    }

    public function test_the_hike_form_and_amount_stay_off_the_employee_page(): void
    {
        $user = $this->employeeWithSalary();

        $response = $this->actingAs($user)->get(route('my.salary'));

        $response->assertDontSee(route('salaries.hike', $user->employeeRecord), false);
    }
}
