<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\User;
use Database\Seeders\ExpenseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalaryModuleTest extends TestCase
{
    use RefreshDatabase;

    private function employee(array $attributes = []): Expense
    {
        return Expense::create(array_merge([
            'name' => 'Kanishka',
            'type' => Expense::TYPE_SALARY,
            'role' => 'Editor',
            'amount' => 15000,
            'is_active' => true,
        ], $attributes));
    }

    public function test_payroll_run_totals_the_seeded_staff(): void
    {
        $this->seed(ExpenseSeeder::class);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('salaries.index', ['month' => '2026-08']));

        $response->assertOk();
        $response->assertViewHas('totalDue', 49000.0);
        $response->assertViewHas('totalPaid', 0.0);
        $response->assertViewHas('outstanding', 49000.0);

        // Names lost their " Salary" suffix in the migration.
        $response->assertSee('Kanishka');
        $response->assertDontSee('Kanishka Salary');
    }

    public function test_paying_one_employee_leaves_the_others_untouched(): void
    {
        $kanishka = $this->employee();
        $this->employee(['name' => 'Sanjai', 'amount' => 8000]);

        $this->actingAs(User::factory()->create())->post(route('salaries.pay', $kanishka), [
            'month' => '2026-08-01',
            'amount_paid' => 15000,
        ])->assertRedirect(route('salaries.index', ['month' => '2026-08']));

        $this->assertDatabaseCount('expense_payments', 1);
        $this->assertDatabaseHas('expense_payments', ['expense_id' => $kanishka->id, 'amount_paid' => 15000.00]);
    }

    public function test_employee_who_left_drops_out_of_the_run(): void
    {
        $this->employee();
        $gone = $this->employee(['name' => 'Nitis', 'amount' => 3000, 'is_active' => false]);

        $response = $this->actingAs(User::factory()->create())->get(route('salaries.index'));

        $response->assertOk();
        $response->assertViewHas('totalDue', 15000.0); // only the active one
        $this->assertCount(1, $response->viewData('left'));
        $this->assertSame($gone->id, $response->viewData('left')->first()->id);
    }

    public function test_employee_record_shows_details_and_history(): void
    {
        $employee = $this->employee(['joined_on' => '2026-01-12', 'phone' => '9876543210']);

        ExpensePayment::create(['expense_id' => $employee->id, 'period' => '2026-07-01', 'amount_paid' => 15000, 'paid_on' => '2026-07-03']);
        ExpensePayment::create(['expense_id' => $employee->id, 'period' => '2026-08-01', 'amount_paid' => 12000, 'paid_on' => '2026-08-02']);

        $response = $this->actingAs(User::factory()->create())->get(route('salaries.show', $employee));

        $response->assertOk();
        $response->assertSee('Kanishka');
        $response->assertSee('Editor');
        $response->assertSee('12 Jan 2026');
        $response->assertSee('9876543210');
        $response->assertSee('July 2026');
        $response->assertSee('August 2026');
        $response->assertViewHas('totalPaid', 27000.0);
        // The 12,000 month is 3,000 short of the 15,000 salary.
        $response->assertSee('short by 3,000');
    }

    public function test_pay_everyone_fills_only_the_unpaid(): void
    {
        $paid = $this->employee();
        $this->employee(['name' => 'Sanjai', 'amount' => 8000]);

        ExpensePayment::create(['expense_id' => $paid->id, 'period' => '2026-08-01', 'amount_paid' => 5000]);

        $this->actingAs(User::factory()->create())
            ->post(route('salaries.pay-all'), ['month' => '2026-08']);

        // The partial 5,000 survives; only Sanjai is added.
        $this->assertDatabaseHas('expense_payments', ['expense_id' => $paid->id, 'amount_paid' => 5000.00]);
        $this->assertDatabaseCount('expense_payments', 2);
    }

    public function test_can_add_an_employee_with_full_details(): void
    {
        $response = $this->actingAs(User::factory()->create())->post(route('salaries.store'), [
            'name' => 'Aron',
            'role' => 'Camera',
            'phone' => '9000000000',
            'joined_on' => '2026-03-01',
            'amount' => 5000,
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('salaries.index'));
        $this->assertDatabaseHas('expenses', [
            'name' => 'Aron',
            'type' => Expense::TYPE_SALARY,
            'role' => 'Camera',
            'phone' => '9000000000',
        ]);
    }

    public function test_a_non_salary_expense_is_not_reachable_as_an_employee(): void
    {
        $bill = Expense::create(['name' => 'Rent', 'type' => Expense::TYPE_BILL, 'amount' => 7500, 'is_active' => true]);

        $this->actingAs(User::factory()->create())
            ->get(route('salaries.show', $bill))
            ->assertNotFound();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('salaries.index'))->assertRedirect(route('login'));
    }
}
