<?php

namespace App\Console\Commands;

use App\Models\Quotation;
use App\Services\WhatsappTemplateService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * One-off setup: submit the Quotation::WHATSAPP_TEMPLATE template
 * QuotationController::sendWhatsapp() depends on. Meta has to approve it
 * before that button on a quotation's show page can actually send -- run
 * this once per WhatsApp Business Account, then watch "Templates ->
 * Refresh from Meta" for it to flip to Approved. Mirrors
 * SeedInvoiceReadyTemplate exactly, one level down (q/{{1}} instead of
 * i/{{1}}).
 */
class SeedQuotationReadyTemplate extends Command
{
    protected $signature = 'app:seed-quotation-ready-template';

    protected $description = 'Submit the quotation_ready WhatsApp template (used by Send via WhatsApp on a quotation) to Meta for approval';

    public function handle(): int
    {
        $baseUrl = rtrim((string) config('app.url'), '/');

        try {
            $response = WhatsappTemplateService::make()->create([
                'name' => Quotation::WHATSAPP_TEMPLATE,
                'category' => 'UTILITY',
                'language' => 'en_US',
                'body' => 'Hi {{1}}, your quotation {{2}} for Rs. {{3}} is ready. Tap below to view or download it.',
                'body_example' => ['Priya', 'QT-0012', '1,00,000.00'],
                'footer' => 'Chakra Groups',
                'buttons' => [[
                    'type' => 'URL',
                    'text' => 'View Quotation',
                    'url' => $baseUrl.'/q/{{1}}',
                    'example' => [$baseUrl.'/q/sample-token-1234567890'],
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
