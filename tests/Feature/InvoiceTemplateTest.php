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
        // Escaped: the renderer HTML-escapes the substituted name, so a faker
        // company containing an apostrophe ("Kessler, O'Conner and Champlin")
        // reaches the page as &#039; and a raw comparison fails at random.
        $this->assertStringContainsString(e($invoice->client->name), $response->getContent());
        $this->assertStringContainsString('Posts', $response->getContent());
        $this->assertStringNotContainsString('{{client_name}}', $response->getContent());
    }

    public function test_studio_toggle_overrides_which_logo_the_preview_uses(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(); // plain Production invoice

        $this->fakeLogo('images/company-logo-test.png', 'company');
        $this->fakeLogo('images/app-studio-logo-test.png', 'studio');
        $settings = \App\Models\CompanySetting::current();
        $settings->update([
            'logo_path' => 'images/company-logo-test.png',
            'app_studio_logo_path' => 'images/app-studio-logo-test.png',
        ]);

        // Production toggle, even against a real Production invoice.
        $prod = $this->actingAs($user)->post(route('invoice-template.preview'), [
            'mode' => 'blocks',
            'blocks' => json_encode(InvoiceTemplate::defaultBlocks()),
            'invoice_id' => $invoice->id,
            'studio' => '0',
        ])->getContent();
        $this->assertStringContainsString($settings->logo_data_uri, $prod);
        $this->assertStringNotContainsString($settings->app_studio_logo_data_uri, $prod);

        // App Studio toggle overrides the same Production invoice.
        $studio = $this->actingAs($user)->post(route('invoice-template.preview'), [
            'mode' => 'blocks',
            'blocks' => json_encode(InvoiceTemplate::defaultBlocks()),
            'invoice_id' => $invoice->id,
            'studio' => '1',
        ])->getContent();
        $this->assertStringContainsString($settings->app_studio_logo_data_uri, $studio);

        @unlink(public_path('images/company-logo-test.png'));
        @unlink(public_path('images/app-studio-logo-test.png'));
    }

    /** A 1x1 PNG written under public_path(), padded so two calls differ. */
    private function fakeLogo(string $relativePath, string $variant): void
    {
        $absolute = public_path($relativePath);
        @mkdir(dirname($absolute), recursive: true);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        file_put_contents($absolute, $png.$variant);
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
