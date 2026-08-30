<?php

namespace App\Console\Commands;

use App\Models\ClientBrief;
use App\Services\WhatsappTemplateService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * One-off setup: submit the brand_brief_reminder template
 * ClientBriefNudge depends on for clients outside the 24-hour window.
 */
class SeedBrandBriefReminderTemplate extends Command
{
    protected $signature = 'app:seed-brand-brief-reminder-template';

    protected $description = 'Submit the brand_brief_reminder WhatsApp template to Meta for approval';

    public function handle(): int
    {
        $baseUrl = rtrim((string) config('app.url'), '/');

        try {
            $response = WhatsappTemplateService::make()->create([
                'name' => ClientBrief::WHATSAPP_TEMPLATE,
                'category' => 'UTILITY',
                'language' => 'en_US',
                'body' => 'Hi {{1}}, before we start writing could you fill in your brand brief? It takes about ten minutes. Tap below to open it.',
                'body_example' => ['Priya'],
                'footer' => 'Chakra Groups',
                'buttons' => [[
                    'type' => 'URL',
                    'text' => 'Fill Brand Brief',
                    'url' => $baseUrl.'/brief/{{1}}',
                    'example' => [$baseUrl.'/brief/sample-token-1234567890'],
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
