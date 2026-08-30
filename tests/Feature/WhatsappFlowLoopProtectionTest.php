<?php

namespace Tests\Feature;

use App\Models\WhatsappFlow;
use App\Models\WhatsappFlowSession;
use App\Models\WhatsappWebhookEvent;
use App\Services\WhatsappFlow\FlowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A flow whose graph is a bug (or a malicious upload) -- two nodes pointing
 * at each other forever -- must not be able to turn one inbound message into
 * an infinite loop. FlowEngine's caps are checked at the top of every
 * iteration regardless of what the node handlers report, so this graph is
 * built entirely from ConditionNode, which never signals "ended" on its
 * own: whatever stops this loop has to be the engine's own bookkeeping, not
 * anything a handler cooperated with.
 *
 * The flow's own `graph.limits` override the engine's defaults (50
 * iterations / 5 visits per node / 120 seconds) with much smaller numbers,
 * so this test proves the same protection the defaults provide without
 * spinning through 50 iterations or waiting out a real wall-clock cap.
 */
class WhatsappFlowLoopProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_cyclic_graph_is_aborted_as_failed_within_the_configured_caps_without_hanging(): void
    {
        $flow = WhatsappFlow::create([
            'name' => 'Cyclic test flow',
            'trigger_type' => 'inbound_message',
            'is_active' => true,
            'graph' => [
                'start_node_id' => 'a',
                'limits' => [
                    'max_iterations' => 12,
                    'max_node_visits' => 3,
                    'max_execution_seconds' => 5,
                ],
                'nodes' => [
                    // Never true, so each node always falls through to its
                    // "false" branch -- which is the other node. A -> B -> A
                    // -> B ..., with no node handler ever reporting "ended".
                    'a' => [
                        'type' => 'condition',
                        'variable' => 'never_set',
                        'operator' => 'exists',
                        'next_true' => 'b',
                        'next_false' => 'b',
                    ],
                    'b' => [
                        'type' => 'condition',
                        'variable' => 'never_set',
                        'operator' => 'exists',
                        'next_true' => 'a',
                        'next_false' => 'a',
                    ],
                ],
            ],
        ]);

        $event = WhatsappWebhookEvent::create([
            'type' => WhatsappWebhookEvent::TYPE_MESSAGE,
            'dedupe_key' => 'loop-protection-test',
            'wa_id' => '917000000099',
            'message_type' => 'text',
            'summary' => 'hello',
            'payload' => [],
            'received_at' => now(),
        ]);

        $startedAt = microtime(true);

        (new FlowEngine)->handleInbound($event);

        $elapsedSeconds = microtime(true) - $startedAt;

        // The whole point: this runs in milliseconds, not anywhere near the
        // 5-second execution cap configured above -- proof the engine did
        // not sit there looping or sleeping.
        $this->assertLessThan(2.0, $elapsedSeconds);

        $session = WhatsappFlowSession::where('flow_id', $flow->id)->sole();
        $this->assertSame('failed', $session->status);

        // Bounded by the low caps configured on the flow, not by having run
        // away to (or past) the class defaults.
        $this->assertLessThanOrEqual(12, $session->iteration_count);
        $this->assertGreaterThan(0, $session->iteration_count);
    }
}
