<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get(route('invoices.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_creating_invoice_computes_subtotal_and_total_with_discount(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();

        $response = $this->actingAs($user)->post(route('invoices.store'), [
            'client_id' => $client->id,
            'invoice_date' => now()->format('Y-m-d'),
            'intro_text' => 'Thanks for your business.',
            'discount_label' => 'First Month Discount',
            'discount_amount' => 6000,
            'items' => [
                ['description' => 'Strategy & Planning', 'quantity' => 1, 'unit_price' => 3000],
                ['description' => 'Script Writing', 'quantity' => 1, 'unit_price' => 3000],
                ['description' => 'Content Creation', 'quantity' => 1, 'unit_price' => 10000],
            ],
        ]);

        $invoice = Invoice::first();
        $response->assertRedirect(route('invoices.show', $invoice));

        $this->assertSame('16000.00', (string) $invoice->subtotal);
        $this->assertSame('10000.00', (string) $invoice->total);
        $this->assertCount(3, $invoice->items);
    }

    public function test_invoice_numbers_auto_increment_and_are_unique(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();

        $payload = [
            'client_id' => $client->id,
            'invoice_date' => now()->format('Y-m-d'),
            'items' => [
                ['description' => 'Service', 'quantity' => 1, 'unit_price' => 100],
            ],
        ];

        $this->actingAs($user)->post(route('invoices.store'), $payload);
        $this->actingAs($user)->post(route('invoices.store'), $payload);

        $numbers = Invoice::orderBy('id')->pluck('invoice_number')->all();

        $this->assertSame(['CP-0001', 'CP-0002'], $numbers);
    }

    public function test_index_sums_the_invoices_on_the_list(): void
    {
        $user = User::factory()->create();
        Invoice::factory()->create([
            'total' => 10000,
            'subtotal' => 10000,
            'invoice_date' => now(),
            'status' => Invoice::STATUS_UNPAID,
        ]);
        Invoice::factory()->pendingApproval()->create([
            'total' => 5000,
            'subtotal' => 5000,
            'invoice_date' => now(),
        ]);
        Invoice::factory()->create([
            'total' => 8000,
            'subtotal' => 8000,
            'invoice_date' => now()->subMonthNoOverflow(),
        ]);

        $response = $this->actingAs($user)->get(route('invoices.index'));

        $response->assertOk();
        $response->assertViewHas('monthTotal', 15000.0);
        $response->assertSee('15,000.00 invoiced');
        $response->assertSee('Sum');
    }

    public function test_index_sum_follows_the_status_filter(): void
    {
        $user = User::factory()->create();
        Invoice::factory()->create([
            'total' => 10000,
            'subtotal' => 10000,
            'invoice_date' => now(),
            'status' => Invoice::STATUS_UNPAID,
        ]);
        Invoice::factory()->create([
            'total' => 4000,
            'subtotal' => 4000,
            'invoice_date' => now(),
            'status' => Invoice::STATUS_PAID,
        ]);

        $response = $this->actingAs($user)->get(route('invoices.index', ['status' => 'unpaid']));

        $response->assertOk();
        $response->assertViewHas('monthTotal', 10000.0);
        $response->assertSee('10,000.00 invoiced');
        $response->assertDontSee('14,000.00 invoiced');
    }

    public function test_pdf_download_returns_a_pdf(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create();

        $response = $this->actingAs($user)->get(route('invoices.pdf', $invoice));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }
}
