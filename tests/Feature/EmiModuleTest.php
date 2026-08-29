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

        // Final installment month: six behind us, this one still owed until paid.
        $this->assertSame(6, $emi->installmentsElapsed(Carbon::create(2026, 8, 1)));
        $this->assertSame(1, $emi->remainingInstallments(Carbon::create(2026, 8, 1)));
        $this->assertSame(2188.0, $emi->outstandingAmount(Carbon::create(2026, 8, 1)));
        $this->assertFalse($emi->isFinished(Carbon::create(2026, 8, 1)));

        // Cleared on the calendar after the term.
        $this->assertSame(0, $emi->remainingInstallments(Carbon::create(2026, 9, 1)));
        $this->assertSame(0.0, $emi->outstandingAmount(Carbon::create(2026, 9, 1)));
        $this->assertSame(100, $emi->progressPercent(Carbon::create(2026, 9, 1)));
        $this->assertTrue($emi->isFinished(Carbon::create(2026, 9, 1)));
    }

    public function test_paying_current_month_reduces_outstanding_and_clears_final_emi(): void
    {
        $emi = $this->gimbal();
        $may = Carbon::create(2026, 5, 1);
        $aug = Carbon::create(2026, 8, 1);

        ExpensePayment::create(['expense_id' => $emi->id, 'period' => '2026-05-01', 'amount_paid' => 2188]);
        $emi->load('payments');

        // May paid: three future installments remain (Jun–Aug).
        $this->assertSame(6564.0, $emi->outstandingAmount($may));
        $this->assertSame(4, $emi->installmentsCompleted($may));
        $this->assertFalse($emi->isFinished($may));

        ExpensePayment::create(['expense_id' => $emi->id, 'period' => '2026-08-01', 'amount_paid' => 2188]);
        $emi->unsetRelation('payments');
        $emi->load('payments');

        // Final month paid in full: nothing left, treated as cleared immediately.
        $this->assertSame(0.0, $emi->outstandingAmount($aug));
        $this->assertSame(7, $emi->installmentsCompleted($aug));
        $this->assertSame(100, $emi->progressPercent($aug));
        $this->assertSame(15316.0, $emi->scheduledPaidAmount($aug));
        $this->assertTrue($emi->isFinished($aug));
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

    public function test_index_exposes_edit_and_delete_for_each_emi(): void
    {
        // These routes and controller methods existed but nothing linked to
        // them, so an EMI added with a typo could never be corrected.
        $emi = $this->gimbal();

        $response = $this->actingAs(User::factory()->create())->get(route('emi.index'));

        $response->assertOk();
        $response->assertSee(route('emi.destroy', $emi), false);
        $response->assertSee(route('emi.update', $emi), false);
        $response->assertSee('Delete');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('emi.index'))->assertRedirect(route('login'));
    }

    public function test_emi_amount_stays_locked_without_unlock(): void
    {
        $emi = $this->gimbal();

        $this->actingAs(User::factory()->create())
            ->put(route('emi.update', $emi), [
                'name' => 'Gimbal Pro',
                'payee' => 'BOI',
                'amount' => 9999,
                'start_month' => '2026-02',
                'installments' => 7,
            ])
            ->assertRedirect(route('emi.index'));

        $emi->refresh();
        $this->assertSame(2188.0, (float) $emi->amount);
        $this->assertSame('Gimbal Pro', $emi->name);
    }

    public function test_emi_amount_changes_when_unlocked_and_confirmed(): void
    {
        $emi = $this->gimbal();

        $this->actingAs(User::factory()->create())
            ->put(route('emi.update', $emi), [
                'name' => 'Gimbal',
                'payee' => 'BOI',
                'amount' => 2500,
                'start_month' => '2026-02',
                'installments' => 7,
                'unlock_amount' => '1',
                'confirm_amount_change' => '1',
            ])
            ->assertRedirect(route('emi.index'));

        $this->assertSame(2500.0, (float) $emi->fresh()->amount);
    }

    public function test_can_pay_emi_from_emi_page(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 15));
        $emi = $this->gimbal();

        $this->actingAs(User::factory()->create())
            ->post(route('emi.pay', $emi), [
                'month' => '2026-08-01',
                'amount_paid' => 2188,
            ])
            ->assertRedirect(route('emi.index', ['month' => '2026-08']));

        $this->assertDatabaseHas('expense_payments', [
            'expense_id' => $emi->id,
            'amount_paid' => 2188.00,
        ]);

        Carbon::setTestNow();
    }

    public function test_final_month_paid_emi_moves_to_cleared(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 15));
        $emi = $this->gimbal();
        ExpensePayment::create(['expense_id' => $emi->id, 'period' => '2026-08-01', 'amount_paid' => 2188]);

        $response = $this->actingAs(User::factory()->create())->get(route('emi.index'));

        $response->assertOk();
        $this->assertCount(0, $response->viewData('running')->where('id', $emi->id));
        $this->assertCount(1, $response->viewData('finished')->where('id', $emi->id));
        $response->assertSee('Cleared');
        $response->assertDontSee('Scheduled paid');

        Carbon::setTestNow();
    }

    public function test_emi_that_clears_next_month_shows_this_month_due_only(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 15));

        // Mar–Sep: August is due now, September is the last installment — not extra August due.
        $emi = Expense::create([
            'name' => 'Cam 2 - B',
            'type' => Expense::TYPE_EMI,
            'payee' => 'CANA',
            'amount' => 6250,
            'start_month' => '2026-03-01',
            'installments' => 7,
        ]);

        $aug = Carbon::create(2026, 8, 1);
        $this->assertTrue($emi->isDueIn($aug));
        $this->assertSame(1, $emi->remainingAfterCurrentMonth($aug));
        $this->assertSame('2026-09-01', $emi->lastMonth()->toDateString());
        $this->assertFalse($emi->isFinished($aug));

        $user = User::factory()->create();
        $unpaid = $this->actingAs($user)->get(route('emi.index'));

        $unpaid->assertOk();
        $unpaid->assertViewHas('monthlyLoad', 6250.0);
        $this->assertCount(1, $unpaid->viewData('running')->where('id', $emi->id));
        $unpaid->assertSee('due this month');
        $unpaid->assertSee('ends Sep 2026');
        $unpaid->assertDontSee('this month due only');

        ExpensePayment::create(['expense_id' => $emi->id, 'period' => '2026-08-01', 'amount_paid' => 6250]);
        $emi->unsetRelation('payments');
        $emi->load('payments');

        $this->assertSame(6250.0, $emi->outstandingAmount($aug));
        $this->assertFalse($emi->isFinished($aug));

        $paid = $this->actingAs($user)->get(route('emi.index'));

        $paid->assertOk();
        $this->assertCount(1, $paid->viewData('running')->where('id', $emi->id));
        $this->assertCount(0, $paid->viewData('finished')->where('id', $emi->id));
        $paid->assertSee('Paid this month');
        $paid->assertSee('due Sep 2026');

        Carbon::setTestNow();
    }

    public function test_last_installment_month_is_labelled_this_month_due_only(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 15));
        $emi = $this->gimbal(); // last installment is August

        $response = $this->actingAs(User::factory()->create())->get(route('emi.index'));

        $response->assertOk();
        $response->assertSee('this month due only');
        $this->assertSame(0, $emi->remainingAfterCurrentMonth(Carbon::create(2026, 8, 1)));

        Carbon::setTestNow();
    }
}
