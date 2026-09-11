<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\WhatsappTemplateService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * One-off setup: submit the Client::WHATSAPP_TEMPLATE_DEPLETION template
 * SendDepletionAlerts depends on. Meta has to approve it before that
 * command's WhatsApp step can actually send -- run this once per WhatsApp
 * Business Account. Mirrors SeedMissingContentAlertTemplate; no button --
 * the account manager already knows where Forecast is.
 *
 * Body starts with static text rather than {{1}} -- Meta rejected the
 * first attempt at this template ("Invalid parameter", no further detail)
 * for opening on a placeholder.
 */
class SeedContentDepletionTemplate extends Command
{
    protected $signature = 'app:seed-content-depletion-template';

    protected $description = 'Submit the content_depletion WhatsApp template (used by content:send-depletion-alerts) to Meta for approval';

    public function handle(): int
    {
        try {
            $response = WhatsappTemplateService::make()->create([
                'name' => Client::WHATSAPP_TEMPLATE_DEPLETION,
                'category' => 'UTILITY',
                'language' => 'en_US',
                'body' => 'Content running low: {{1}} is {{2}} — nothing is booked before then.',
                'body_example' => ['SVA Silks', 'running out around 12 Sep (2 left)'],
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
