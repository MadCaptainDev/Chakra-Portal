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
        // Row ids are the plain numbers "1".."4" -- the same tokens a
        // typist already sends -- so one condition per option matches
        // both a tap (message.choice becomes the tapped row's id) and
        // someone who just types the number, via FlowEngine's own
        // message.choice/message.normalized fallback. Semantic ids
        // (e.g. "menu_invoices") would need a second condition node per
        // option for no client-visible benefit, since the CRM log already
        // shows the row's title, not its id.
        $rows = [
            ['id' => '1', 'title' => 'Invoices', 'description' => 'Your recent invoices and payment links'],
            ['id' => '2', 'title' => 'Monthly report', 'description' => "Last month's Instagram performance"],
            ['id' => '3', 'title' => 'Upcoming shoots', 'description' => 'Dates confirmed with the studio'],
            ['id' => '4', 'title' => 'Talk to the studio', 'description' => 'A person will reply here'],
        ];

        $greeting = "Hi {{client.name}}! Welcome to Chakra Groups.\n\nTap *Select Option* below, or just type 1-4.";
        $again = 'Anything else?';
        $fallback = "I didn't catch that — pick an option below, or type 1-4:";

        return [
            // The four exact-match option checks run before the fuzzy
            // greeting checks (previously the other way round). A tap
            // seeds message.normalized with the row's *title* -- see
            // FlowEngine::recordInboundMessage() -- so with the old
            // ordering a title that happened to contain "hi"/"hello"/
            // "menu" as a substring would have been swallowed by the
            // greeting branch before ever reaching its own option check.
            // Running the exact checks first removes that failure mode
            // structurally rather than relying on titles never containing
            // those substrings.
            'start_node_id' => '5',
            'nodes' => [
                '5' => ['type' => 'condition', 'variable' => 'message.choice', 'operator' => 'equals', 'value' => '1', 'next_true' => '6', 'next_false' => '7', '_pos' => ['x' => 80, 'y' => 60]],
                '6' => ['type' => 'client_action', 'action' => 'invoices', 'next' => '10', '_pos' => ['x' => 420, 'y' => 20]],
                '7' => ['type' => 'condition', 'variable' => 'message.choice', 'operator' => 'equals', 'value' => '2', 'next_true' => '8', 'next_false' => '9', '_pos' => ['x' => 80, 'y' => 200]],
                '8' => ['type' => 'client_action', 'action' => 'monthly_report', 'next' => '10', '_pos' => ['x' => 420, 'y' => 160]],
                '9' => ['type' => 'condition', 'variable' => 'message.choice', 'operator' => 'equals', 'value' => '3', 'next_true' => '11', 'next_false' => '12', '_pos' => ['x' => 80, 'y' => 340]],
                '11' => ['type' => 'client_action', 'action' => 'upcoming_shoots', 'next' => '10', '_pos' => ['x' => 420, 'y' => 300]],
                '12' => ['type' => 'condition', 'variable' => 'message.choice', 'operator' => 'equals', 'value' => '4', 'next_true' => '13', 'next_false' => '1', '_pos' => ['x' => 80, 'y' => 480]],
                '13' => ['type' => 'send_message', 'body' => "Thanks — someone from the studio will reply here shortly.\n\nType *menu* anytime for invoices, reports or shoot dates.", 'next' => null, '_pos' => ['x' => 420, 'y' => 440]],
                '10' => ['type' => 'send_list', 'body' => $again, 'rows' => $rows, 'button' => 'Select Option', 'header' => null, 'footer' => 'Type menu anytime', 'next' => null, '_pos' => ['x' => 760, 'y' => 160]],
                '1' => ['type' => 'condition', 'variable' => 'message.normalized', 'operator' => 'contains', 'value' => 'hi', 'next_true' => '2', 'next_false' => '3', '_pos' => ['x' => 80, 'y' => 620]],
                '2' => ['type' => 'send_list', 'body' => $greeting, 'rows' => $rows, 'button' => 'Select Option', 'header' => 'Chakra Groups', 'footer' => 'Type menu anytime', 'next' => null, '_pos' => ['x' => 420, 'y' => 580]],
                '3' => ['type' => 'condition', 'variable' => 'message.normalized', 'operator' => 'contains', 'value' => 'hello', 'next_true' => '2', 'next_false' => '4', '_pos' => ['x' => 80, 'y' => 760]],
                '4' => ['type' => 'condition', 'variable' => 'message.normalized', 'operator' => 'contains', 'value' => 'menu', 'next_true' => '2', 'next_false' => '14', '_pos' => ['x' => 80, 'y' => 900]],
                '14' => ['type' => 'send_list', 'body' => $fallback, 'rows' => $rows, 'button' => 'Select Option', 'header' => null, 'footer' => null, 'next' => null, '_pos' => ['x' => 420, 'y' => 860]],
            ],
        ];
    }
}
