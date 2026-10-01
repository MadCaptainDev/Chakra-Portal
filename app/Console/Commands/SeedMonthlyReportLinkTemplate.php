<?php

namespace App\Console\Commands;

use App\Models\MonthlyReportNote;
use App\Services\WhatsappTemplateService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Submits the template "Send via WhatsApp" on a monthly report uses for a
 * number outside WhatsApp's 24-hour window: the report as a link
 * (/r/{token}, PublicMonthlyReportController) rather than an attached PDF,
 * which WhatsApp refuses there. Same shape as invoice_ready_v2 -- Meta
 * allows one dynamic segment after a static base, so the token is the
 * whole of {{1}} in the button.
 */
class SeedMonthlyReportLinkTemplate extends Command
{
    protected $signature = 'app:seed-monthly-report-link-template';

    protected $description = 'Submit the monthly_report_link WhatsApp template (Send via WhatsApp on a monthly report) to Meta for approval';

    public function handle(): int
    {
        $baseUrl = rtrim((string) config('app.url'), '/');

        try {
            $response = WhatsappTemplateService::make()->create([
                'name' => MonthlyReportNote::WHATSAPP_LINK_TEMPLATE,
                'category' => 'UTILITY',
                'language' => 'en_US',
                'body' => 'Hi, {{1}}\'s {{2}} Instagram report is ready. Tap below to open or download the PDF.',
                'body_example' => ['Zira Bridal Studio', 'September 2026'],
                'footer' => 'Chakra Groups',
                'buttons' => [[
                    'type' => 'URL',
                    'text' => 'Open Report',
                    'url' => $baseUrl.'/r/{{1}}',
                    'example' => [$baseUrl.'/r/sample-token-1234567890'],
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
