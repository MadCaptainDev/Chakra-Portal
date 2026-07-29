<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceDuplicateTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_clones_items_discount_and_totals_with_a_fresh_number(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create();
        $original = Invoice::factory()->create([
            'client_id' => $client->id,
            'invoice_number' => 'CP-0001',
            'discount_label' => 'Loyalty Discount',
            'discount_amount' => 500,
        ]);
        $original->items()->create(['description' => 'Design Work', 'quantity' => 2, 'unit_price' => 1000, 'line_total' => 2000, 'sort_order' => 0]);
        $original->load('items');
        $original->recalculateTotals();
        $original->save();

        $response = $this->actingAs($user)->post(route('invoices.duplicate', $original));

        $copy = Invoice::where('id', '!=', $original->id)->first();
        $response->assertRedirect(route('invoices.edit', $copy));
        $this->assertSame('CP-0002', $copy->invoice_number);
        $this->assertSame($client->id, $copy->client_id);
        $this->assertSame('Loyalty Discount', $copy->discount_label);
        $this->assertSame('1500.00', (string) $copy->total);
        $this->assertCount(1, $copy->items);
        $this->assertSame('Design Work', $copy->items->first()->description);
        $this->assertSame(Invoice::STATUS_UNPAID, $copy->status);
        $this->assertEquals(today()->format('Y-m-d'), $copy->invoice_date->format('Y-m-d'));
    }
}
