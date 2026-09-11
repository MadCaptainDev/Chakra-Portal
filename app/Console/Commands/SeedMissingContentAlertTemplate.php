<?php

namespace App\Console\Commands;

use App\Models\Shoot;
use App\Services\WhatsappTemplateService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * One-off setup: submit the Shoot::WHATSAPP_TEMPLATE_MISSING_CONTENT
 * template SendMissingContentAlerts depends on. Meta has to approve it
 * before that command's WhatsApp step can actually send -- run this once
 * per WhatsApp Business Account. Mirrors SeedShootReminderTemplate; no
 * button -- this is an FYI to go add the footage, not a document to open.
 */
class SeedMissingContentAlertTemplate extends Command
{
    protected $signature = 'app:seed-missing-content-alert-template';

    protected $description = 'Submit the shoot_content_missing WhatsApp template (used by shoots:send-missing-content-alerts) to Meta for approval';

    public function handle(): int
    {
        try {
            $response = WhatsappTemplateService::make()->create([
                'name' => Shoot::WHATSAPP_TEMPLATE_MISSING_CONTENT,
                'category' => 'UTILITY',
                'language' => 'en_US',
                'body' => 'Heads up: {{1}} ({{2}}) is completed but nothing has been added to the Reel Planner yet.',
                'body_example' => ['SVA Silks shoot', 'Wed 3 Sep'],
                'footer' => 'Chakra Groups',
            ]);
        } catch (RuntimeException $e) {
            $this->error("Meta rejected the submission: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info('Submitted. Status: '.($response['status'] ?? 'unknown').'. Check Templates -> Refresh from Meta once Meta finishes reviewing it.');

        return self::SUCCESS;
    }
}
