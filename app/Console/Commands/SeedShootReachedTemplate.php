<?php

namespace App\Console\Commands;

use App\Models\Shoot;
use App\Services\WhatsappTemplateService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * One-off setup: submit the Shoot::WHATSAPP_TEMPLATE_REACHED template that
 * App\Services\ShootReachedAlert sends. Meta has to approve it before the
 * alert can actually arrive -- until then each send is logged as failed and
 * the crew's tap still works. Mirrors SeedMissingContentAlertTemplate: an
 * FYI, no button.
 */
class SeedShootReachedTemplate extends Command
{
    protected $signature = 'app:seed-shoot-reached-template';

    protected $description = 'Submit the shoot_crew_reached WhatsApp template (sent when crew reach a shoot location) to Meta for approval';

    public function handle(): int
    {
        try {
            $response = WhatsappTemplateService::make()->create([
                'name' => Shoot::WHATSAPP_TEMPLATE_REACHED,
                'category' => 'UTILITY',
                'language' => 'en_US',
                // Meta refuses a body that starts or ends with a variable.
                'body' => 'Crew update: {{1}} has reached the location for {{2}} ({{3}}) at {{4}}. The shoot will start soon.',
                'body_example' => ['Sanjai', 'SVA Silks reels', 'Anna Nagar', '10:42 AM'],
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
