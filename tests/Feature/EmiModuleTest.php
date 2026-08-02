<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpensePayment;
use App\Models\User;
use Database\Seeders\ExpenseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EmiModuleTest extends TestCase
{
    use RefreshDatabase;

    private function gimbal(): Expense
    {
        // Feb 2026, 7 installments of 2,188 -> last is Aug 2026.
        return Expense::create([
            'name' => 'Gimbal',
            'type' => Expense::TYPE_EMI,
            'payee' => 'BOI',
            'amount' => 2188,
            'start_month' => '2026-02-01',
            'installments' => 7,
        ]);
    }

    public function test_schedule_position_at_start_middle_and_end(): void
    {
        $emi = $this->gimbal();

        // Before any installment has fallen due.
        $this->assertSame(0, $emi->installmentsElapsed(Carbon::create(2026, 2, 1)));
        $this->assertSame(7, $emi->remainingInstallments(Carbon::create(2026, 2, 1)));
        $this->assertSame(15316.0, $emi->outstandingAmount(Carbon::create(2026, 2, 1)));
        $this->assertSame(0, $emi->progressPercent(Carbon::create(2026, 2, 1)));

        // Middle.
        $this->assertSame(3, $emi->installmentsElapsed(Carbon::create(2026, 5, 1)));
        $this->assertSame(4, $emi->remainingInstallments(Carbon::create(2026, 5, 1)));
        $this->assertSame(8752.0, $emi->outstandingAmount(Carbon::create(2026, 5, 1)));

        // Final installment month: six behind us, this one still owed.
        $this->assertSame(6, $emi->installmentsElapsed(Carbon::create(2026, 8, 1)));
        $this->assertSame(1, $emi->remainingInstallments(Carbon::create(2026, 8, 1)));
        $this->assertSame(2188.0, $emi->outstandingAmount(Carbon::create(2026, 8, 1)));
        $this->assertFalse($emi->isFinished(Carbon::create(2026, 8, 1)));

        // Cleared.
        $this->assertSame(0, $emi->remainingInstallments(Carbon::create(2026, 9, 1)));
        $this->assertSame(0.0, $emi->outstandingAmount(Carbon::create(2026, 9, 1)));
        $this->assertSame(100, $emi->progressPercent(Carbon::create(2026, 9, 1)));
        $this->assertTrue($emi->isFinished(Carbon::create(2026, 9, 1)));
    }

    public function test_elapsed_never_exceeds_the_term(): void
    {
        $emi = $this->gimbal();

        $this->assertSame(7, $emi->installmentsElapsed(Carbon::create(2030, 1, 1)));
        $this->assertSame(0, $emi->remainingInstallments(Carbon::create(2030, 1, 1)));
    }

    public function test_recorded_payments_are_kept_separate_from_schedule(): void
    {
        $emi = $this->gimbal();

        ExpensePayment::create(['expense_id' => $emi->id, 'period' => '2026-02-01', 'amount_paid' => 2188]);

        // Schedule says three months have gone by; only one has been recorded.
        $this->assertSame(3, $emi->installmentsElapsed(Carbon::create(2026, 5, 1)));
        $this->assertSame(2188.0, $emi->fresh()->recordedPaid());
    }

    public function test_index_shows_outstanding_bank_split_and_timeline(): void
    {
        $this->seed(ExpenseSeeder::class);
        Carbon::setTestNow(Carbon::create(2026, 8, 15));

        $response = $this->actingAs(User::factory()->create())->get(route('emi.index'));

        $response->assertOk();
        $response->assertSee('Total Outstanding');
        $response->assertSee('X5 + Lens');
        $response->assertSee('CANA');

        // August 2026 EMI load, straight from the user's sheet.
        $response->assertViewHas('monthlyLoad', 38872.0);

        // Timeline starts at the current month and tapers to 6,300.
        $timeline = collect($response->viewData('timeline'));
        $this->assertSame(38872.0, $timeline->first()['total']);
        $this->assertSame(6300.0, $timeline->last()['total']);
        $this->assertSame('2027-07', $timeline->last()['month']->format('Y-m'));

        Carbon::setTestNow();
    }

    public function test_finished_emis_are_listed_separately(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 1));
        $this->gimbal(); // cleared in Aug 2026

        $response = $this->actingAs(User::factory()->create())->get(route('emi.index'));

        $response->assertOk();
        $this->assertCount(0, $response->viewData('running'));
        $this->assertCount(1, $response->viewData('finished'));
        $response->assertSee('Cleared');

        Carbon::setTestNow();
    }

    public function test_can_add_an_emi(): void
    {
        $response = $this->actingAs(User::factory()->create())->post(route('emi.store'), [
            'name' => 'Drone',
            'payee' => 'Axis',
            'amount' => 4000,
            'start_month' => '2026-09',
            'installments' => 12,
        ]);

        $response->assertRedirect(route('emi.index'));

        $emi = Expense::where('name', 'Drone')->firstOrFail();
        $this->assertSame(Expense::TYPE_EMI, $emi->type);
        $this->assertSame(12, $emi->installments);
        // Asserted through the cast, not the raw column: SQLite stores this as
        // a datetime string while MySQL stores a plain date.
        $this->assertSame('2026-09-01', $emi->start_month->toDateString());
        $this->assertSame('2027-08-01', $emi->lastMonth()->toDateString());
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('emi.index'))->assertRedirect(route('login'));
    }
}
