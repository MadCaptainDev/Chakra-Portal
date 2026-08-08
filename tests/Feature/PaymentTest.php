<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_payment_keeps_invoice_unpaid_and_reports_balance(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['subtotal' => 10000, 'total' => 10000]);

        $this->actingAs($user)->post(route('payments.store', $invoice), [
            'amount' => 4000,
            'paid_on' => now()->format('Y-m-d'),
        ]);

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->status);
        $this->assertSame(6000.0, $invoice->balanceDue());
        $this->assertSame(4000.0, $invoice->paidTotal());
    }

    public function test_partial_payment_is_reported_as_partial_not_plain_unpaid(): void
    {
        $user = User::factory()->create();
        $partial = Invoice::factory()->create(['subtotal' => 10000, 'total' => 10000]);
        $untouched = Invoice::factory()->create(['subtotal' => 10000, 'total' => 10000]);

        $this->actingAs($user)->post(route('payments.store', $partial), [
            'amount' => 4000,
            'paid_on' => now()->format('Y-m-d'),
        ]);

        $partial->refresh();
        $untouched->refresh();

        $this->assertTrue($partial->isPartiallyPaid());
        $this->assertSame('partial', $partial->displayStatus());

        // A zero-paid invoice must stay plainly unpaid, or the badge is noise.
        $this->assertFalse($untouched->isPartiallyPaid());
        $this->assertSame('unpaid', $untouched->displayStatus());

        $this->assertSame([$partial->id], Invoice::partiallyPaid()->pluck('id')->all());
    }

    public function test_overdue_takes_precedence_over_partial_in_the_badge(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create([
            'subtotal' => 10000,
            'total' => 10000,
            'due_date' => now()->subWeek(),
        ]);

        $this->actingAs($user)->post(route('payments.store', $invoice), [
            'amount' => 4000,
            'paid_on' => now()->format('Y-m-d'),
        ]);

        $invoice->refresh();
        $this->assertTrue($invoice->isPartiallyPaid());
        $this->assertSame('overdue', $invoice->displayStatus());
    }

    public function test_partial_filter_narrows_the_invoice_list(): void
    {
        $user = User::factory()->create();
        $partial = Invoice::factory()->create([
            'subtotal' => 10000, 'total' => 10000, 'invoice_date' => now(),
        ]);
        $untouched = Invoice::factory()->create([
            'subtotal' => 10000, 'total' => 10000, 'invoice_date' => now(),
        ]);

        $this->actingAs($user)->post(route('payments.store', $partial), [
            'amount' => 4000,
            'paid_on' => now()->format('Y-m-d'),
        ]);

        $response = $this->actingAs($user)->get(route('invoices.index', ['status' => 'partial']));

        $response->assertOk();
        $response->assertSee($partial->invoice_number);
        $response->assertDontSee($untouched->invoice_number);
    }

    public function test_payment_larger_than_the_balance_is_rejected(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['subtotal' => 10000, 'total' => 10000]);

        $this->actingAs($user)->post(route('payments.store', $invoice), [
            'amount' => 4000,
            'paid_on' => now()->format('Y-m-d'),
        ]);

        // 7,000 against a 6,000 balance - a typo, not a real payment.
        $response = $this->actingAs($user)->post(route('payments.store', $invoice), [
            'amount' => 7000,
            'paid_on' => now()->format('Y-m-d'),
        ]);

        $response->assertSessionHasErrors('amount');

        $invoice->refresh();
        $this->assertSame(1, $invoice->payments()->count());
        $this->assertSame(4000.0, $invoice->paidTotal());
    }

    public function test_split_payments_settle_the_invoice_despite_float_drift(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['subtotal' => 10000, 'total' => 10000]);

        foreach ([3333.33, 3333.33, 3333.34] as $amount) {
            $this->actingAs($user)->post(route('payments.store', $invoice), [
                'amount' => $amount,
                'paid_on' => now()->format('Y-m-d'),
            ]);
        }

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertFalse($invoice->isPartiallyPaid());
    }

    public function test_payment_covering_total_flips_invoice_to_paid(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['subtotal' => 10000, 'total' => 10000]);

        $this->actingAs($user)->post(route('payments.store', $invoice), [
            'amount' => 10000,
            'paid_on' => now()->format('Y-m-d'),
        ]);

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame(0.0, $invoice->balanceDue());
    }

    public function test_deleting_a_payment_reverts_status_to_unpaid(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['subtotal' => 10000, 'total' => 10000]);

        $this->actingAs($user)->post(route('payments.store', $invoice), [
            'amount' => 10000,
            'paid_on' => now()->format('Y-m-d'),
        ]);
        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);

        $payment = $invoice->payments()->first();
        $this->actingAs($user)->delete(route('payments.destroy', $payment));

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->status);
        $this->assertSame(0, $invoice->payments()->count());
    }

    public function test_cannot_record_a_payment_on_a_pending_approval_invoice(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->pendingApproval()->create(['subtotal' => 10000, 'total' => 10000]);

        $response = $this->actingAs($user)->post(route('payments.store', $invoice), [
            'amount' => 1000,
            'paid_on' => now()->format('Y-m-d'),
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(0, $invoice->payments()->count());
    }
}
