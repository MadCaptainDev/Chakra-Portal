<?php

namespace App\Console\Commands;

use App\Models\WhatsappFlow;
use Illuminate\Console\Command;

/**
 * Install the default client self-service automation (editable under Automations).
 */
class SeedClientPortalAutomation extends Command
{
    protected $signature = 'app:seed-client-portal-automation {--force : Replace an existing client portal automation} {--no-activate : Leave the automation inactive after seeding}';

    protected $description = 'Create or refresh the default Client self-service WhatsApp automation';

    public function handle(): int
    {
        $existing = WhatsappFlow::query()->where('trigger_type', 'client_portal')->first();

        if ($existing && ! $this->option('force')) {
            $this->info("Already exists: \"{$existing->name}\" (id {$existing->id}). Use --force to replace its graph.");

            return self::SUCCESS;
        }

        $graph = $this->defaultGraph();

        if ($existing) {
            $existing->update([
                'name' => 'Client self-service menu',
                'graph' => $graph,
                'version' => $existing->version + 1,
                'is_active' => $this->option('no-activate') ? $existing->is_active : true,
            ]);
            $flow = $existing->fresh();
            $this->info("Updated automation #{$flow->id}. Open Automations to edit the canvas.");
        } else {
            $flow = WhatsappFlow::create([
                'name' => 'Client self-service menu',
                'trigger_type' => 'client_portal',
                'trigger_config' => null,
                'graph' => $graph,
                'is_active' => ! $this->option('no-activate'),
                'version' => 1,
            ]);
            $this->info("Created automation #{$flow->id}.".($flow->is_active ? ' It is active.' : ' Activate it under WhatsApp CRM → Automations.'));
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultGraph(): array
    {
        $menu = <<<'TEXT'
Hi {{client.name}}! Welcome to Chakra Groups.

Reply with a number:
1. Invoices
2. Monthly report
3. Upcoming shoots
4. Talk to the studio

Type menu anytime to see this again.
TEXT;

        return [
            'start_node_id' => '1',
            'nodes' => [
                '1' => ['type' => 'condition', 'variable' => 'message.normalized', 'operator' => 'contains', 'value' => 'hi', 'next_true' => '2', 'next_false' => '3', '_pos' => ['x' => 80, 'y' => 80]],
                '2' => ['type' => 'send_message', 'body' => $menu, 'next' => null, '_pos' => ['x' => 400, 'y' => 40]],
                '3' => ['type' => 'condition', 'variable' => 'message.normalized', 'operator' => 'contains', 'value' => 'hello', 'next_true' => '2', 'next_false' => '4', '_pos' => ['x' => 80, 'y' => 220]],
                '4' => ['type' => 'condition', 'variable' => 'message.normalized', 'operator' => 'contains', 'value' => 'menu', 'next_true' => '2', 'next_false' => '5', '_pos' => ['x' => 80, 'y' => 360]],
                '5' => ['type' => 'condition', 'variable' => 'message.normalized', 'operator' => 'equals', 'value' => '1', 'next_true' => '6', 'next_false' => '7', '_pos' => ['x' => 80, 'y' => 500]],
                '6' => ['type' => 'client_action', 'action' => 'invoices', 'next' => '10', '_pos' => ['x' => 400, 'y' => 460]],
                '7' => ['type' => 'condition', 'variable' => 'message.normalized', 'operator' => 'equals', 'value' => '2', 'next_true' => '8', 'next_false' => '9', '_pos' => ['x' => 80, 'y' => 640]],
                '8' => ['type' => 'client_action', 'action' => 'monthly_report', 'next' => '10', '_pos' => ['x' => 400, 'y' => 600]],
                '9' => ['type' => 'condition', 'variable' => 'message.normalized', 'operator' => 'equals', 'value' => '3', 'next_true' => '11', 'next_false' => '12', '_pos' => ['x' => 80, 'y' => 780]],
                '11' => ['type' => 'client_action', 'action' => 'upcoming_shoots', 'next' => '10', '_pos' => ['x' => 400, 'y' => 740]],
                '12' => ['type' => 'condition', 'variable' => 'message.normalized', 'operator' => 'equals', 'value' => '4', 'next_true' => '13', 'next_false' => '14', '_pos' => ['x' => 80, 'y' => 920]],
                '13' => ['type' => 'send_message', 'body' => "Thanks — someone from the studio will reply here shortly.\n\nType *menu* anytime for invoices, reports or shoot dates.", 'next' => null, '_pos' => ['x' => 400, 'y' => 880]],
                '14' => ['type' => 'send_message', 'body' => "I didn't catch that.\n\nReply 1–4, or type *menu* to see the options again.", 'next' => null, '_pos' => ['x' => 400, 'y' => 1020]],
                '10' => ['type' => 'send_message', 'body' => 'Type *menu* for more options.', 'next' => null, '_pos' => ['x' => 720, 'y' => 600]],
            ],
        ];
    }
}
