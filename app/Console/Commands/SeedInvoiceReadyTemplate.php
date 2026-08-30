<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\WhatsappTemplateService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * One-off setup: submit the Invoice::WHATSAPP_TEMPLATE template
 * InvoiceController::sendWhatsapp() depends on. Meta has to approve it
 * before that button on an invoice's show page can actually send -- run
 * this once per WhatsApp Business Account (a fresh WABA, or after the
 * template is deleted from Meta), then watch "Templates -> Refresh from
 * Meta" for it to flip to Approved.
 */
class SeedInvoiceReadyTemplate extends Command
{
    protected $signature = 'app:seed-invoice-ready-template';

    protected $description = 'Submit the invoice_ready WhatsApp template (used by Send via WhatsApp on an invoice) to Meta for approval';

    public function handle(): int
    {
        $baseUrl = rtrim((string) config('app.url'), '/');

        try {
            $response = WhatsappTemplateService::make()->create([
                'name' => Invoice::WHATSAPP_TEMPLATE,
                'category' => 'UTILITY',
                'language' => 'en_US',
                'body' => 'Hi {{1}}, your invoice {{2}} for Rs. {{3}} is ready. Tap below to view or download it.',
                'body_example' => ['Priya', 'CP-0012', '12,500.00'],
                'footer' => 'Chakra Groups',
                'buttons' => [[
                    'type' => 'URL',
                    'text' => 'View Invoice',
                    // Meta only allows one dynamic segment, appended after a
                    // static base -- not the full signed/query-string link a
                    // browser would otherwise want, hence Invoice::publicUrl()'s
                    // plain /i/{token} shape rather than a signed route.
                    'url' => $baseUrl.'/i/{{1}}',
                    'example' => [$baseUrl.'/i/sample-token-1234567890'],
                ]],
            ]);
        } catch (RuntimeException $e) {
            $this->error("Meta rejected the submission: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info('Submitted. Status: '.($response['status'] ?? 'unknown').'. Check Templates -> Refresh from Meta once Meta finishes reviewing it.');

        return self::SUCCESS;
    }
}
