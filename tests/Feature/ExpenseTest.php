<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\User;
use Database\Seeders\ExpenseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    private function emi(array $attributes = []): Expense
    {
        return Expense::create(array_merge([
            'name' => 'Gimbal',
            'type' => Expense::TYPE_EMI,
            'payee' => 'BOI',
            'amount' => 2188,
            'start_month' => '2026-02-01',
            'installments' => 7,
        ], $attributes));
    }

    public function test_emi_is_due_only_inside_its_term(): void
    {
        $emi = $this->emi(); // Feb 2026, 7 installments -> last is Aug 2026

        $this->assertFalse($emi->isDueIn(Carbon::create(2026, 1, 1)), 'before the first month');
        $this->assertTrue($emi->isDueIn(Carbon::create(2026, 2, 1)), 'first month');
        $this->assertTrue($emi->isDueIn(Carbon::create(2026, 8, 1)), 'last month');
        $this->assertFalse($emi->isDueIn(Carbon::create(2026, 9, 1)), 'after the term');

        $this->assertSame('2026-08-01', $emi->lastMonth()->toDateString());
        $this->assertSame(1, $emi->installmentNumberFor(Carbon::create(2026, 2, 1)));
        $this->assertSame(7, $emi->installmentNumberFor(Carbon::create(2026, 8, 1)));
        $this->assertSame(15316.0, $emi->totalCommitment());
    }

    public function test_recurring_expense_is_due_every_month_until_paused(): void
    {
        $salary = Expense::create([
            'name' => 'Kanishka Salary',
            'type' => Expense::TYPE_SALARY,
            'amount' => 15000,
            'is_active' => true,
        ]);

        $this->assertTrue($salary->isDueIn(Carbon::create(2026, 2, 1)));
        $this->assertTrue($salary->isDueIn(Carbon::create(2030, 12, 1)));

        $salary->update(['is_active' => false]);
        $this->assertFalse($salary->fresh()->isDueIn(Carbon::create(2026, 2, 1)));
    }

    public function test_seeded_emis_reproduce_the_real_monthly_totals(): void
    {
        $this->seed(ExpenseSeeder::class);

        // Straight from the user's sheet: Feb 2026 is row 1, Aug 2026 is row 7.
        $expected = [
            '2026-02' => 14852, '2026-03' => 29902, '2026-04' => 32972, '2026-05' => 38872,
            '2026-06' => 38872, '2026-07' => 38872, '2026-08' => 38872, '2026-09' => 36684,
            '2026-10' => 29317, '2026-11' => 21567, '2026-12' => 21567, '2027-01' => 15667,
            '2027-02' => 13167, '2027-03' => 13167, '2027-04' => 9967, '2027-05' => 6300,
            '2027-06' => 6300, '2027-07' => 6300,
        ];

        foreach ($expected as $month => $total) {
            $due = Expense::dueIn(Carbon::parse($month.'-01'))
                ->where('type', Expense::TYPE_EMI)
                ->sum(fn (Expense $e) => (float) $e->amount);

            $this->assertSame((float) $total, $due, "EMI total for {$month}");
        }

        // And nothing is left running past the final installment.
        $this->assertCount(0, Expense::dueIn(Carbon::parse('2027-08-01'))->where('type', Expense::TYPE_EMI));
    }

    public function test_seeded_running_costs_total_63800_a_month(): void
    {
        $this->seed(ExpenseSeeder::class);

        $recurring = Expense::dueIn(Carbon::create(2026, 8, 1))
            ->where('type', '!=', Expense::TYPE_EMI)
            ->sum(fn (Expense $e) => (float) $e->amount);

        $this->assertSame(63800.0, $recurring);
    }

    public function test_month_board_shows_due_paid_and_outstanding(): void
    {
        $emi = $this->emi();
        Expense::create(['name' => 'Rent', 'type' => Expense::TYPE_BILL, 'amount' => 7500, 'is_active' => true]);

        ExpensePayment::create([
            'expense_id' => $emi->id,
            'period' => '2026-02-01',
            'amount_paid' => 2188,
            'paid_on' => '2026-02-05',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('expenses.index', ['month' => '2026-02']));

        $response->assertOk();
        $response->assertSee('February 2026');
        $response->assertSee('Needs attention');
        $response->assertSee('Rent');
        $response->assertDontSee('Gimbal');
        $response->assertViewHas('totalDue', 9688.0);   // 2188 + 7500
        $response->assertViewHas('totalPaid', 2188.0);
        $response->assertViewHas('outstanding', 7500.0);
        $response->assertViewHas('attentionRows', fn ($rows) => $rows->count() === 1 && $rows->first()['expense']->name === 'Rent');
        $response->assertViewHas('glance', fn ($glance) => $glance['cleared_count'] === 1);
    }

    public function test_recording_a_payment_stores_the_actual_amount(): void
    {
        $rent = Expense::create(['name' => 'Rent', 'type' => Expense::TYPE_BILL, 'amount' => 7500, 'is_active' => true]);

        // Real case from the sheet: 7,500 budgeted, 3,500 actually paid.
        $this->actingAs(User::factory()->create())->post(route('expenses.pay', $rent), [
            'month' => '2026-03-01',
            'amount_paid' => 3500,
        ])->assertRedirect(route('expenses.index', ['month' => '2026-03']));

        $this->assertDatabaseHas('expense_payments', [
            'expense_id' => $rent->id,
            'amount_paid' => 3500.00,
        ]);
    }

    public function test_paying_zero_clears_the_record(): void
    {
        $rent = Expense::create(['name' => 'Rent', 'type' => Expense::TYPE_BILL, 'amount' => 7500, 'is_active' => true]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('expenses.pay', $rent), ['month' => '2026-03-01', 'amount_paid' => 3500]);
        $this->assertDatabaseCount('expense_payments', 1);

        $this->actingAs($user)->post(route('expenses.pay', $rent), ['month' => '2026-03-01', 'amount_paid' => 0]);
        $this->assertDatabaseCount('expense_payments', 0);
    }

    public function test_cannot_pay_an_emi_outside_its_term(): void
    {
        $emi = $this->emi(); // ends Aug 2026

        $response = $this->actingAs(User::factory()->create())->post(route('expenses.pay', $emi), [
            'month' => '2027-01-01',
            'amount_paid' => 2188,
        ]);

        $response->assertSessionHas('error');
        $this->assertDatabaseCount('expense_payments', 0);
    }

    public function test_mark_all_paid_fills_only_the_untouched_items(): void
    {
        $emi = $this->emi();
        Expense::create(['name' => 'Rent', 'type' => Expense::TYPE_BILL, 'amount' => 7500, 'is_active' => true]);

        ExpensePayment::create(['expense_id' => $emi->id, 'period' => '2026-02-01', 'amount_paid' => 1000]);

        $this->actingAs(User::factory()->create())
            ->post(route('expenses.pay-all'), ['month' => '2026-02']);

        // The partial 1,000 must survive; only Rent gets added.
        $this->assertDatabaseHas('expense_payments', ['expense_id' => $emi->id, 'amount_paid' => 1000.00]);
        $this->assertDatabaseCount('expense_payments', 2);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('expenses.index'))->assertRedirect(route('login'));
    }
}
