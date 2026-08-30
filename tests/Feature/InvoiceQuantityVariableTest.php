<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ContentItem;
use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Models\User;
use App\Services\RecurringInvoiceGenerator;
use App\Support\InvoiceQuantityVariable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InvoiceQuantityVariableTest extends TestCase
{
    use RefreshDatabase;

    private function clientWithPublishedContent(): Client
    {
        $client = Client::factory()->create([
            'name' => 'SVA Silks',
            'notion_venture' => 'SVA Silks',
        ]);

        ContentItem::factory()->create([
            'venture' => 'SVA Silks',
            'source' => ContentItem::SOURCE_REEL,
            'status' => 'Published',
            'published_date' => '2026-08-10',
        ]);
        ContentItem::factory()->create([
            'venture' => 'SVA Silks',
            'source' => ContentItem::SOURCE_REEL,
            'status' => 'Published',
            'published_date' => '2026-08-28',
        ]);
        ContentItem::factory()->create([
            'venture' => 'SVA Silks',
            'source' => ContentItem::SOURCE_POST,
            'status' => 'Published',
            'published_date' => '2026-08-15',
        ]);
        ContentItem::factory()->create([
            'venture' => 'SVA Silks',
            'source' => ContentItem::SOURCE_YOUTUBE,
            'status' => 'Published',
            'published_date' => '2026-07-10',
        ]);

        return $client;
    }

    public function test_counts_published_items_for_the_invoice_month(): void
    {
        $client = $this->clientWithPublishedContent();
        $month = Carbon::parse('2026-08-01');

        $counts = InvoiceQuantityVariable::countsFor($client, $month);

        $this->assertSame(2, $counts['published_reels']);
        $this->assertSame(1, $counts['published_posts']);
        $this->assertSame(0, $counts['published_shorts']);
    }

    public function test_creating_invoice_resolves_quantity_variable_to_a_number(): void
    {
        $user = User::factory()->create();
        $client = $this->clientWithPublishedContent();

        $response = $this->actingAs($user)->post(route('invoices.store'), [
            'client_id' => $client->id,
            'invoice_date' => '2026-08-15',
            'items' => [
                ['description' => 'Insta reels', 'quantity' => '{{published_reels}}', 'unit_price' => 2000],
            ],
        ]);

        $invoice = Invoice::first();
        $response->assertRedirect(route('invoices.show', $invoice));

        $this->assertSame('4000.00', (string) $invoice->total);
        $this->assertSame('2.00', (string) $invoice->items->first()->quantity);
    }

    public function test_preview_endpoint_returns_counts_for_client_and_month(): void
    {
        $user = User::factory()->create();
        $client = $this->clientWithPublishedContent();

        $response = $this->actingAs($user)->get(route('invoices.quantity-variables.preview', [
            'client_id' => $client->id,
            'month' => '2026-08-01',
        ]));

        $response->assertOk();
        $response->assertJson([
            'published_reels' => 2,
            'published_posts' => 1,
            'published_shorts' => 0,
        ]);
    }

    public function test_recurring_schedule_stores_the_token_and_generates_resolved_quantity(): void
    {
        $user = User::factory()->create();
        $client = $this->clientWithPublishedContent();

        $this->actingAs($user)->post(route('recurring.store'), [
            'client_id' => $client->id,
            'frequency' => RecurringInvoice::FREQUENCY_MONTHLY,
            'day_of_month' => today()->day,
            'next_run_on' => today()->format('Y-m-d'),
            'items' => [
                ['description' => 'Insta reels', 'quantity' => '{{published_reels}}', 'unit_price' => 1500],
            ],
        ])->assertRedirect(route('recurring.index'));

        $schedule = RecurringInvoice::first();
        $this->assertSame('{{published_reels}}', (string) $schedule->items->first()->quantity);

        app(RecurringInvoiceGenerator::class)->run();

        $invoice = Invoice::where('recurring_invoice_id', $schedule->id)->first();
        $this->assertNotNull($invoice);
        $this->assertSame('2.00', (string) $invoice->items->first()->quantity);
        $this->assertSame('3000.00', (string) $invoice->total);
    }
}
