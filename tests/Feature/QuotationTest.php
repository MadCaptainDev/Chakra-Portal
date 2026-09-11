<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get(route('quotations.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_creating_quotation_computes_subtotal_and_total_with_discount(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();

        $response = $this->actingAs($user)->post(route('quotations.store'), [
            'client_id' => $client->id,
            'quotation_date' => now()->format('Y-m-d'),
            'intro_text' => 'Thanks for the opportunity.',
            'discount_label' => 'Bundle Discount',
            'discount_amount' => 1000,
            'items' => [
                ['description' => 'Strategy & Planning', 'quantity' => 1, 'unit_price' => 3000],
                ['description' => 'Content Creation', 'quantity' => 1, 'unit_price' => 10000],
            ],
        ]);

        $quotation = Quotation::first();
        $response->assertRedirect(route('quotations.show', $quotation));

        $this->assertSame('13000.00', (string) $quotation->subtotal);
        $this->assertSame('12000.00', (string) $quotation->total);
        $this->assertCount(2, $quotation->items);
        $this->assertSame(Quotation::STATUS_DRAFT, $quotation->status);
    }

    public function test_quotation_numbers_auto_increment_and_are_unique(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();

        $payload = [
            'client_id' => $client->id,
            'quotation_date' => now()->format('Y-m-d'),
            'items' => [
                ['description' => 'Service', 'quantity' => 1, 'unit_price' => 100],
            ],
        ];

        $this->actingAs($user)->post(route('quotations.store'), $payload);
        $this->actingAs($user)->post(route('quotations.store'), $payload);

        $numbers = Quotation::orderBy('id')->pluck('quotation_number')->all();

        $this->assertSame(['QT-0001', 'QT-0002'], $numbers);
    }

    public function test_accepting_then_converting_creates_an_invoice_with_the_same_items(): void
    {
        $user = User::factory()->create();
        $quotation = Quotation::factory()
            ->has(\App\Models\QuotationItem::factory()->count(2), 'items')
            ->create(['status' => Quotation::STATUS_DRAFT]);
        $quotation->load('items');
        $quotation->recalculateTotals();
        $quotation->save();

        $this->actingAs($user)->post(route('quotations.accept', $quotation))
            ->assertRedirect(route('quotations.show', $quotation));
        $this->assertSame(Quotation::STATUS_ACCEPTED, $quotation->fresh()->status);

        $response = $this->actingAs($user)->post(route('quotations.convert', $quotation));

        $quotation->refresh();
        $this->assertTrue($quotation->isConverted());

        $invoice = Invoice::findOrFail($quotation->converted_invoice_id);
        $response->assertRedirect(route('invoices.show', $invoice));
        $this->assertCount(2, $invoice->items);
        $this->assertSame((string) $quotation->total, (string) $invoice->total);
    }

    public function test_cannot_convert_a_quotation_that_was_not_accepted(): void
    {
        $user = User::factory()->create();
        $quotation = Quotation::factory()->create(['status' => Quotation::STATUS_DRAFT]);

        $response = $this->actingAs($user)->post(route('quotations.convert', $quotation));

        $response->assertRedirect(route('quotations.show', $quotation));
        $this->assertFalse($quotation->fresh()->isConverted());
    }

    public function test_a_converted_quotation_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create();
        $quotation = Quotation::factory()->create([
            'status' => Quotation::STATUS_ACCEPTED,
            'converted_invoice_id' => $invoice->id,
        ]);

        $response = $this->actingAs($user)->delete(route('quotations.destroy', $quotation));

        $response->assertRedirect(route('quotations.show', $quotation));
        $this->assertNotNull($quotation->fresh());
    }
}
