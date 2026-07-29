<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_approving_assigns_number_and_sets_unpaid(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->pendingApproval()->create();

        $response = $this->actingAs($user)->post(route('invoices.approve', $invoice));

        $invoice->refresh();
        $response->assertRedirect(route('invoices.show', $invoice));
        $this->assertSame('CP-0001', $invoice->invoice_number);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->status);
        $this->assertNotNull($invoice->approved_at);
    }

    public function test_cannot_approve_an_already_approved_invoice(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(); // already unpaid

        $response = $this->actingAs($user)->post(route('invoices.approve', $invoice));

        $response->assertSessionHas('error');
    }

    public function test_discarding_deletes_the_pending_invoice(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->pendingApproval()->create();

        $response = $this->actingAs($user)->delete(route('invoices.discard', $invoice));

        $response->assertRedirect(route('invoices.index', ['status' => 'pending_approval']));
        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
    }

    public function test_discarding_does_not_burn_an_invoice_number(): void
    {
        $user = User::factory()->create();
        $client = \App\Models\Client::factory()->create();

        $pending1 = Invoice::factory()->pendingApproval()->create(['client_id' => $client->id]);
        $pending2 = Invoice::factory()->pendingApproval()->create(['client_id' => $client->id]);

        $this->actingAs($user)->delete(route('invoices.discard', $pending1));
        $this->actingAs($user)->post(route('invoices.approve', $pending2));

        $pending2->refresh();
        $this->assertSame('CP-0001', $pending2->invoice_number);
    }

    public function test_cannot_discard_an_already_approved_invoice(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create();

        $response = $this->actingAs($user)->delete(route('invoices.discard', $invoice));

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
    }
}
