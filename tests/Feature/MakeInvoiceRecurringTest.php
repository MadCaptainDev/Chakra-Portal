<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MakeInvoiceRecurringTest extends TestCase
{
    use RefreshDatabase;

    private function invoiceWithItems(array $attributes = []): Invoice
    {
        $invoice = Invoice::factory()->create(array_merge([
            'invoice_number' => 'CP-0007',
            'invoice_date' => '2026-03-12',
            'due_date' => '2026-03-26',
            'intro_text' => 'Retainer for ongoing work.',
            'discount_label' => 'Loyalty Discount',
            'discount_amount' => 500,
        ], $attributes));

        // No ampersand here on purpose: the form hands items to Alpine via
        // Js::from(), which renders "&" as an escaped & inside a JS string
        // literal and makes a plain assertSee() unreadable. The ampersand path
        // is covered against the database in the store test below.
        $invoice->items()->create([
            'description' => 'Strategy and Planning',
            'quantity' => 2,
            'unit_price' => 2500,
            'line_total' => 5000,
            'sort_order' => 0,
        ]);

        return $invoice;
    }

    public function test_make_recurring_prefills_the_form_from_the_invoice(): void
    {
        $invoice = $this->invoiceWithItems();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('recurring.create', ['from_invoice' => $invoice->id]));

        $response->assertOk();
        $response->assertSee('New Recurring Schedule from CP-0007');
        $response->assertSee('nothing is scheduled until you save');
        $response->assertSee('Strategy and Planning', false);
        $response->assertSee('Retainer for ongoing work.');
        $response->assertSee('Loyalty Discount');

        // Assert the prefill itself rather than the escaped JSON the items are
        // serialised into for Alpine, which is unreadable to match on.
        $schedule = $response->original->getData()['schedule'];

        $this->assertFalse($schedule->exists, 'The prefilled schedule must not be persisted.');
        $this->assertSame($invoice->client_id, $schedule->client_id);
        $this->assertSame(RecurringInvoice::FREQUENCY_MONTHLY, $schedule->frequency);
        $this->assertSame(12, $schedule->day_of_month);
        $this->assertSame(14, $schedule->due_days);
        $this->assertSame(500.0, (float) $schedule->discount_amount);

        $this->assertCount(1, $schedule->items);
        $item = $schedule->items->first();
        $this->assertSame('Strategy and Planning', $item->description);
        $this->assertSame(2.0, (float) $item->quantity);
        $this->assertSame(2500.0, (float) $item->unit_price);
    }

    public function test_opening_the_prefilled_form_creates_nothing(): void
    {
        $invoice = $this->invoiceWithItems();

        $this->actingAs(User::factory()->create())
            ->get(route('recurring.create', ['from_invoice' => $invoice->id]))
            ->assertOk();

        // The whole point of using a GET: visiting must never start billing.
        $this->assertDatabaseCount('recurring_invoices', 0);
        $this->assertDatabaseCount('recurring_invoice_items', 0);
    }

    public function test_saving_the_prefilled_form_creates_the_schedule(): void
    {
        $client = Client::factory()->create();
        $invoice = $this->invoiceWithItems(['client_id' => $client->id]);

        $response = $this->actingAs(User::factory()->create())->post(route('recurring.store'), [
            'client_id' => $client->id,
            'label' => "From {$invoice->invoice_number}",
            'frequency' => RecurringInvoice::FREQUENCY_MONTHLY,
            'day_of_month' => 12,
            'due_days' => 14,
            'next_run_on' => '2026-09-12',
            'intro_text' => 'Retainer for ongoing work.',
            'discount_label' => 'Loyalty Discount',
            'discount_amount' => 500,
            'items' => [
                ['description' => 'Strategy & Planning', 'quantity' => 2, 'unit_price' => 2500],
            ],
        ]);

        $response->assertRedirect(route('recurring.index'));

        $schedule = RecurringInvoice::with('items')->firstOrFail();
        $this->assertSame($client->id, $schedule->client_id);
        $this->assertSame('From CP-0007', $schedule->label);
        $this->assertSame(12, $schedule->day_of_month);
        $this->assertSame(14, $schedule->due_days);
        $this->assertCount(1, $schedule->items);
        $this->assertSame('Strategy & Planning', $schedule->items->first()->description);
    }

    public function test_create_page_still_works_without_a_source_invoice(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('recurring.create'));

        $response->assertOk();
        $response->assertSee('New Recurring Schedule');
        $response->assertDontSee('Pre-filled from invoice');
    }

    public function test_unknown_source_invoice_falls_back_to_a_blank_form(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get(route('recurring.create', ['from_invoice' => 999999]));

        $response->assertOk();
        $response->assertDontSee('Pre-filled from invoice');
    }

    public function test_invoice_page_offers_the_make_recurring_action(): void
    {
        $invoice = $this->invoiceWithItems();

        $response = $this->actingAs(User::factory()->create())
            ->get(route('invoices.show', $invoice));

        $response->assertOk();
        $response->assertSee('Make Recurring');
        $response->assertSee(route('recurring.create', ['from_invoice' => $invoice->id]), false);
    }
}
