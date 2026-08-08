<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Each expense module addresses rows in the shared `expenses` table by id, so
 * every one of them must refuse ids belonging to another type.
 *
 * The EMI controller originally had no such guard, and because its validated()
 * force-sets type = 'emi', a PUT at a salary's id silently converted that
 * employee into an EMI. Asserting the status code alone would not have caught
 * that -- these tests assert the target row is untouched afterwards.
 */
class ExpenseTypeGuardTest extends TestCase
{
    use RefreshDatabase;

    private function salary(): Expense
    {
        return Expense::create([
            'name' => 'Kanishka',
            'type' => Expense::TYPE_SALARY,
            'role' => 'Editor',
            'amount' => 15000,
            'is_active' => true,
        ]);
    }

    private function emi(): Expense
    {
        return Expense::create([
            'name' => 'Gimbal',
            'type' => Expense::TYPE_EMI,
            'payee' => 'BOI',
            'amount' => 2188,
            'start_month' => '2026-02-01',
            'installments' => 7,
        ]);
    }

    public function test_emi_update_refuses_a_salary_and_leaves_it_untouched(): void
    {
        $salary = $this->salary();

        $this->actingAs(User::factory()->create())
            ->put(route('emi.update', $salary), [
                'name' => 'Hijacked',
                'amount' => 999,
                'start_month' => '2026-01',
                'installments' => 5,
            ])
            ->assertNotFound();

        $salary->refresh();
        $this->assertSame(Expense::TYPE_SALARY, $salary->type, 'The salary was converted into an EMI.');
        $this->assertSame('Kanishka', $salary->name);
        $this->assertSame(15000.00, (float) $salary->amount);
        $this->assertNull($salary->installments);
    }

    public function test_emi_delete_refuses_a_salary(): void
    {
        $salary = $this->salary();

        $this->actingAs(User::factory()->create())
            ->delete(route('emi.destroy', $salary))
            ->assertNotFound();

        $this->assertDatabaseHas('expenses', ['id' => $salary->id, 'type' => Expense::TYPE_SALARY]);
    }

    public function test_bill_routes_refuse_an_emi(): void
    {
        $emi = $this->emi();
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('bills.update', $emi), ['name' => 'X', 'amount' => 1])->assertNotFound();
        $this->actingAs($user)->delete(route('bills.destroy', $emi))->assertNotFound();

        $emi->refresh();
        $this->assertSame(Expense::TYPE_EMI, $emi->type);
        $this->assertSame('Gimbal', $emi->name);
    }

    public function test_salary_routes_refuse_a_bill(): void
    {
        $bill = Expense::create(['name' => 'Rent', 'type' => Expense::TYPE_BILL, 'amount' => 7500, 'is_active' => true]);
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('salaries.show', $bill))->assertNotFound();
        $this->actingAs($user)->put(route('salaries.update', $bill), ['name' => 'X', 'amount' => 1])->assertNotFound();
        $this->actingAs($user)->delete(route('salaries.destroy', $bill))->assertNotFound();

        $bill->refresh();
        $this->assertSame(Expense::TYPE_BILL, $bill->type);
    }

    public function test_the_right_type_still_works(): void
    {
        $emi = $this->emi();

        $this->actingAs(User::factory()->create())
            ->put(route('emi.update', $emi), [
                'name' => 'Gimbal MkII',
                'payee' => 'Axis',
                'amount' => 2500,
                'start_month' => '2026-03',
                'installments' => 9,
            ])
            ->assertRedirect(route('emi.index'));

        $emi->refresh();
        $this->assertSame('Gimbal MkII', $emi->name);
        $this->assertSame('Axis', $emi->payee);
        $this->assertSame(9, $emi->installments);
        $this->assertSame('2026-03-01', $emi->start_month->toDateString());
    }
}
