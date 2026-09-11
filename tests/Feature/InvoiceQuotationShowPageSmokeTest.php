<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The tabbed show pages actually render -- catches a broken
 * x-tab-nav/x-document-preview/x-whatsapp-log-table wiring that a
 * narrower unit test wouldn't.
 */
class InvoiceQuotationShowPageSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_show_page_renders(): void
    {
        $invoice = Invoice::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Preview')
            ->assertSee('WhatsApp')
            ->assertSee('Payments');
    }

    public function test_quotation_show_page_renders(): void
    {
        $quotation = Quotation::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('quotations.show', $quotation))
            ->assertOk()
            ->assertSee('Preview')
            ->assertSee('WhatsApp');
    }
}
