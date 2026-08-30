<?php

namespace App\Services\WhatsappFlow\Nodes;

use App\Models\WhatsappFlowSession;

/**
 * Pauses the walk without holding a worker or a request open.
 *
 * Unlike every other node, this one saves the session's current_node_id
 * itself -- to the node the graph names as `next`, i.e. the one to resume at
 * once the delay elapses -- and hands FlowEngine back only the delay
 * duration. FlowEngine is the one that owns dispatching
 * AdvanceWhatsappFlowSession, so a node handler is never the thing deciding
 * whether a job reaches the queue.
 *
 * Config: `seconds` (delay length), `next` (node id to resume at).
 *
 * Also stamps `expires_at` to when this wait is actually due to elapse.
 * FlowEngine::handleInbound() reads it back to tell an early inbound message
 * (arriving before AdvanceWhatsappFlowSession fires) apart from a legitimate
 * resume, so a still-`active` session parked here cannot be nudged past its
 * delay just because the contact happened to text again first.
 */
class DelayNode implements NodeHandler
{
    public function handle(WhatsappFlowSession $session, array $nodeConfig): NodeResult
    {
        $seconds = (int) ($nodeConfig['seconds'] ?? 0);

        $session->current_node_id = $nodeConfig['next'] ?? null;
        $session->expires_at = now()->addSeconds($seconds);
        $session->save();

        return NodeResult::waiting($seconds);
    }
}
