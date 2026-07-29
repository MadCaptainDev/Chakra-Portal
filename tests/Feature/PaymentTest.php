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
