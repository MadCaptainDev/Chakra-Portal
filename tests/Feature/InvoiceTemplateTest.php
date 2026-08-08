<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_view_pdf_template_editor(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('invoice-template.edit'))
            ->assertOk()
            ->assertSee('Invoice PDF Template')
            ->assertSee('Drag blocks')
            ->assertSee('HTML editor');
    }

    public function test_staff_can_save_block_template(): void
    {
        $user = User::factory()->create();
        $blocks = InvoiceTemplate::defaultBlocks();

        $this->actingAs($user)
            ->put(route('invoice-template.update'), [
                'name' => 'Studio layout',
                'mode' => 'blocks',
                'blocks' => json_encode($blocks),
                'html' => null,
                'custom_css' => null,
            ])
            ->assertRedirect(route('invoice-template.edit'));

        $this->assertDatabaseHas('invoice_templates', [
            'name' => 'Studio layout',
            'mode' => 'blocks',
            'is_active' => 1,
        ]);
    }

    public function test_live_preview_returns_html(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create();
        $invoice->items()->create([
            'description' => 'Reels',
            'quantity' => 3,
            'unit_price' => 1000,
            'line_total' => 3000,
            'sort_order' => 0,
        ]);

        $response = $this->actingAs($user)
            ->post(route('invoice-template.preview'), [
                'mode' => 'blocks',
                'blocks' => json_encode(InvoiceTemplate::defaultBlocks()),
                'invoice_id' => $invoice->id,
            ]);

        $response->assertOk();
        $response->assertHeader('content-type', 'text/html; charset=UTF-8');
        $this->assertStringContainsString('INVOICE', $response->getContent());
        $this->assertStringContainsString('Reels', $response->getContent());
        $this->assertStringContainsString('>Qty<', $response->getContent());
        $this->assertStringContainsString('>3<', $response->getContent());
    }

    public function test_html_mode_replaces_placeholders(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create();
        $invoice->items()->create([
            'description' => 'Posts',
            'quantity' => 1,
            'unit_price' => 2000,
            'line_total' => 2000,
            'sort_order' => 0,
        ]);

        $html = '<div class="page-content"><h1>{{client_name}}</h1>{{items_table}}</div>';

        $response = $this->actingAs($user)
            ->post(route('invoice-template.preview'), [
                'mode' => 'html',
                'html' => $html,
                'invoice_id' => $invoice->id,
            ]);

        $response->assertOk();
        $this->assertStringContainsString($invoice->client->name, $response->getContent());
        $this->assertStringContainsString('Posts', $response->getContent());
        $this->assertStringNotContainsString('{{client_name}}', $response->getContent());
    }

    public function test_reset_restores_classic_blocks(): void
    {
        $user = User::factory()->create();
        $template = InvoiceTemplate::active();
        $template->update([
            'name' => 'Custom',
            'mode' => 'html',
            'html' => '<div>custom</div>',
        ]);

        $this->actingAs($user)
            ->post(route('invoice-template.reset'))
            ->assertRedirect(route('invoice-template.edit'));

        $template->refresh();
        $this->assertSame('Classic', $template->name);
        $this->assertSame('blocks', $template->mode);
        $this->assertNull($template->html);
    }
}
