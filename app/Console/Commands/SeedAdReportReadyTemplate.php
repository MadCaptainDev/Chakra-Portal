<?php

namespace App\Console\Commands;

use App\Models\AdReport;
use App\Services\WhatsappTemplateService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Submit the template that sends a client their monthly ads report link.
 *
 * Its own template rather than reusing monthly_report_link_v1: Meta fixes a
 * URL button's base address at approval, and that one is locked to /r/ --
 * the Instagram report -- while this link lives at /results/.
 */
class SeedAdReportReadyTemplate extends Command
{
    protected $signature = 'app:seed-ad-report-ready-template';

    protected $description = 'Submit the ad_report_ready WhatsApp template (sending a monthly ads report link) to Meta for approval';

    public function handle(): int
    {
        $baseUrl = rtrim((string) config('app.url'), '/');

        try {
            $response = WhatsappTemplateService::make()->create([
                'name' => AdReport::WHATSAPP_TEMPLATE,
                'category' => 'UTILITY',
                'language' => 'en_US',
                'body' => 'Hi, {{1}}\'s {{2}} ads report is ready. Tap below to see how your ads performed this month.',
                'body_example' => ['Thillai Pets Clinic', 'September 2026'],
                'footer' => 'Chakra Groups',
                'buttons' => [[
                    'type' => 'URL',
                    'text' => 'Open Report',
                    'url' => $baseUrl.'/results/{{1}}',
                    'example' => [$baseUrl.'/results/sample-token-1234567890'],
                ]],
            ]);
        } catch (RuntimeException $e) {
            $this->error("Meta rejected the submission: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info('Submitted. Status: '.($response['status'] ?? 'unknown').'.');

        return self::SUCCESS;
    }
}
