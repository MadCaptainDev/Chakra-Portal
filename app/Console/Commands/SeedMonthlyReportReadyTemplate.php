<?php

namespace App\Console\Commands;

use App\Models\MonthlyReportNote;
use App\Services\WhatsappTemplateService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * One-off setup: submit the MonthlyReportNote::WHATSAPP_TEMPLATE template
 * NotifyReportsReady depends on. Meta has to approve it before that command
 * can actually send anything -- run this once per WhatsApp Business
 * Account, then watch "Templates -> Refresh from Meta" for it to flip to
 * Approved.
 *
 * The button is a plain static login link, not a per-client dynamic one
 * (contrast Invoice::WHATSAPP_TEMPLATE's /i/{{1}} token link): this message
 * only ever says the report exists, never hands over the document itself,
 * so there is nothing per-recipient to encode into the URL -- the client
 * signs in like any other visit.
 */
class SeedMonthlyReportReadyTemplate extends Command
{
    protected $signature = 'app:seed-monthly-report-ready-template';

    protected $description = 'Submit the monthly_report_ready WhatsApp template (used by reports:notify-ready) to Meta for approval';

    public function handle(): int
    {
        $baseUrl = rtrim((string) config('app.url'), '/');

        try {
            $response = WhatsappTemplateService::make()->create([
                'name' => MonthlyReportNote::WHATSAPP_TEMPLATE,
                'category' => 'UTILITY',
                'language' => 'en_US',
                'body' => "Hi {{1}}, your {{2}} report is ready. Log in to your portal to view it.",
                'body_example' => ['Priya', 'September 2026'],
                'footer' => 'Chakra Groups',
                'buttons' => [[
                    'type' => 'URL',
                    'text' => 'View Report',
                    'url' => $baseUrl.'/login',
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
