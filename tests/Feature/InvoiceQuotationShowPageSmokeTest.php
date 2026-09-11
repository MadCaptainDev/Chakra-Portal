<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\User;
use DOMDocument;
use DOMXPath;
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

        $html = $this->actingAs($user)->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Preview')
            ->assertSee('WhatsApp')
            ->assertSee('Payments')
            ->getContent();

        $this->assertPreviewIframeIsProperlyNested($html, 'Invoice preview');
    }

    public function test_quotation_show_page_renders(): void
    {
        $quotation = Quotation::factory()->create();
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get(route('quotations.show', $quotation))
            ->assertOk()
            ->assertSee('Preview')
            ->assertSee('WhatsApp')
            ->getContent();

        $this->assertPreviewIframeIsProperlyNested($html, 'Quotation preview');
    }

    /**
     * assertSee() alone missed a real bug here: a dropped `>` merged the
     * x-document-preview component's outer measuring <div> and its flex-
     * centering child into one malformed tag. PHP still compiled and
     * rendered that fine (Blade doesn't validate HTML), and every expected
     * string was still "seen" on the page -- the only thing that actually
     * broke was the DOM tree a browser builds from it, which collapsed the
     * centering wrapper out of existence and left the preview permanently
     * un-centered (and, via the same underlying x-init-too-early class of
     * bug, sometimes invisible). Parsing the response with DOMDocument, the
     * same way a browser would, is what actually catches that class of
     * regression.
     */
    private function assertPreviewIframeIsProperlyNested(string $html, string $iframeTitle): void
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $iframes = $xpath->query("//iframe[@title='{$iframeTitle}']");

        $this->assertSame(1, $iframes->length, "Expected exactly one preview iframe titled '{$iframeTitle}'.");

        // iframe -> scaled box -> flex-centering row -> measuring div.
        $flexRow = $iframes->item(0)->parentNode?->parentNode;

        $this->assertNotNull($flexRow, 'Preview iframe is not nested as expected.');
        $this->assertStringContainsString('justify-center', $flexRow->getAttribute('class'));
        $this->assertStringContainsString('items-center', $flexRow->getAttribute('class'));
    }
}
