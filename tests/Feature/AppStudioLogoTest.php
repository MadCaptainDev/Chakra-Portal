<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\SaasProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which logo one invoice renders with -- Chakra App Studio's own mark for
 * an invoice tagged with a saas_product_id, Production's otherwise, and
 * Production's again as a fallback if App Studio's has never been
 * uploaded. See CompanySetting::logoDataUriFor(), which both
 * invoices/blocks/header.blade.php (block mode) and
 * InvoiceDocumentRenderer::replacePlaceholders() (HTML mode) call through.
 */
class AppStudioLogoTest extends TestCase
{
    use RefreshDatabase;

    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    /**
     * A 1x1 PNG written under public_path(), the only place dataUriFor()
     * reads from. $variant just pads a comment into the file so two calls
     * produce byte-distinguishable files -- their data URIs need to differ
     * for a test to tell "the App Studio logo" apart from "the Production
     * one" rather than two copies of the same bytes.
     */
    private function fakeLogo(string $relativePath, string $variant = ''): string
    {
        $absolute = public_path($relativePath);
        @mkdir(dirname($absolute), recursive: true);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        file_put_contents($absolute, $png.$variant);
        $this->tempFiles[] = $absolute;

        return $relativePath;
    }

    private function invoiceFor(?SaasProduct $product): Invoice
    {
        return Invoice::factory()->create(['saas_product_id' => $product?->id]);
    }

    public function test_an_ordinary_invoice_uses_the_production_logo(): void
    {
        $settings = CompanySetting::current();
        $settings->update(['logo_path' => $this->fakeLogo('test-logos/production.png')]);

        $invoice = $this->invoiceFor(null);

        $this->assertSame($settings->logo_data_uri, $settings->logoDataUriFor($invoice));
    }

    public function test_an_app_studio_invoice_uses_the_app_studio_logo_when_one_is_set(): void
    {
        $settings = CompanySetting::current();
        $settings->update([
            'logo_path' => $this->fakeLogo('test-logos/production.png', '-production'),
            'app_studio_logo_path' => $this->fakeLogo('test-logos/app-studio.png', '-app-studio'),
        ]);

        $client = Client::create(['name' => 'Acme']);
        $product = SaasProduct::create(['client_id' => $client->id, 'name' => 'Acme App']);
        $invoice = $this->invoiceFor($product);

        $logo = $settings->logoDataUriFor($invoice);

        $this->assertSame($settings->app_studio_logo_data_uri, $logo);
        $this->assertNotSame($settings->logo_data_uri, $logo);
    }

    public function test_an_app_studio_invoice_falls_back_to_the_production_logo_when_none_is_uploaded_yet(): void
    {
        $settings = CompanySetting::current();
        $settings->update(['logo_path' => $this->fakeLogo('test-logos/production.png')]);
        $this->assertNull($settings->app_studio_logo_path);

        $client = Client::create(['name' => 'Acme']);
        $product = SaasProduct::create(['client_id' => $client->id, 'name' => 'Acme App']);
        $invoice = $this->invoiceFor($product);

        $this->assertSame($settings->logo_data_uri, $settings->logoDataUriFor($invoice));
    }
}
