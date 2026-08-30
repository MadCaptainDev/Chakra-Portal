<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\WhatsappFlow;
use App\Models\WhatsappSetting;
use Illuminate\Console\Command;

/**
 * Install, activate, and report status for the client WhatsApp portal automation.
 */
class EnsureClientPortalAutomation extends Command
{
    protected $signature = 'whatsapp:ensure-client-portal {--force : Replace the automation graph}';

    protected $description = 'Create/activate the client self-service WhatsApp automation and print a readiness report';

    public function handle(): int
    {
        $this->call('app:seed-client-portal-automation', [
            '--force' => $this->option('force'),
        ]);

        $flow = WhatsappFlow::query()->where('trigger_type', 'client_portal')->first();

        if ($flow === null) {
            $this->error('Could not create the client portal automation.');

            return self::FAILURE;
        }

        if (! $flow->is_active) {
            $flow->update(['is_active' => true]);
            $this->info("Activated automation #{$flow->id} ({$flow->name}).");
        } else {
            $this->info("Automation #{$flow->id} ({$flow->name}) is already active.");
        }

        $settings = WhatsappSetting::current();
        $this->line('WhatsApp sending: '.($settings->canSend() ? 'configured' : 'NOT configured (Settings → WhatsApp)'));

        $clients = Client::query()->whatsappPortalEnabled()->get();

        if ($clients->isEmpty()) {
            $this->warn('No clients have WhatsApp self-service portal enabled yet.');
            $this->line('Edit a client → enable “WhatsApp self-service portal” and save their phone number.');

            return self::SUCCESS;
        }

        $this->line('Portal-enabled clients:');
        foreach ($clients as $client) {
            $waId = \App\Services\WhatsappSender::normalise($client->phone);
            $matched = Client::findForWhatsappPortal($waId);
            $status = $matched?->id === $client->id ? 'OK' : 'PHONE MISMATCH';
            $this->line("  - {$client->name}: stored={$client->phone} → wa_id={$waId} [{$status}]");
        }

        return self::SUCCESS;
    }
}
