<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\User;
use Database\Seeders\ExpenseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_bills_total_14800(): void
    {
        $this->seed(ExpenseSeeder::class);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('bills.index', ['month' => '2026-08']));

        $response->assertOk();
        $response->assertViewHas('totalDue', 14800.0);
        $response->assertSee('Electricity');
        $response->assertSee('Rent');
    }

    public function test_paying_less_than_budget_is_flagged(): void
    {
        $rent = Expense::create(['name' => 'Rent', 'type' => Expense::TYPE_BILL, 'amount' => 7500, 'is_active' => true]);
        $user = User::factory()->create();

        // The real case from the sheet: 7,500 budgeted, 3,500 paid.
        $this->actingAs($user)->post(route('bills.pay', $rent), [
            'month' => '2026-03-01',
            'amount_paid' => 3500,
        ])->assertRedirect(route('bills.index', ['month' => '2026-03']));

        $response = $this->actingAs($user)->get(route('bills.index', ['month' => '2026-03']));
        $response->assertOk();
        $response->assertSee('under by 4,000');
        $response->assertViewHas('outstanding', 4000.0);
    }

    public function test_paying_more_than_budget_is_flagged(): void
    {
        $bill = Expense::create(['name' => 'Electricity', 'type' => Expense::TYPE_BILL, 'amount' => 1500, 'is_active' => true]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('bills.pay', $bill), [
            'month' => '2026-03-01',
            'amount_paid' => 2100,
        ]);

        $this->actingAs($user)->get(route('bills.index', ['month' => '2026-03']))
            ->assertSee('over by 600');
    }

    public function test_can_add_a_bill(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('bills.store'), ['name' => 'Water', 'amount' => 500])
            ->assertRedirect(route('bills.index'));

        $this->assertDatabaseHas('expenses', ['name' => 'Water', 'type' => Expense::TYPE_BILL]);
    }

    public function test_a_salary_is_not_reachable_as_a_bill(): void
    {
        $salary = Expense::create(['name' => 'Kanishka', 'type' => Expense::TYPE_SALARY, 'amount' => 15000, 'is_active' => true]);

        $this->actingAs(User::factory()->create())
            ->post(route('bills.pay', $salary), ['month' => '2026-08-01', 'amount_paid' => 100])
            ->assertNotFound();
    }

    public function test_overview_still_totals_everything(): void
    {
        $this->seed(ExpenseSeeder::class);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('expenses.index', ['month' => '2026-08']));

        $response->assertOk();
        // 38,872 EMI + 49,000 salaries + 14,800 bills
        $response->assertViewHas('totalDue', 102672.0);
    }

    public function test_retired_manage_url_redirects_to_the_overview(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/expenses/manage')
            ->assertRedirect('/expenses');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('bills.index'))->assertRedirect(route('login'));
    }
}
