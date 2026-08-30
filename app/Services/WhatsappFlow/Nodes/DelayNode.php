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
 */
class DelayNode implements NodeHandler
{
    public function handle(WhatsappFlowSession $session, array $nodeConfig): NodeResult
    {
        $session->current_node_id = $nodeConfig['next'] ?? null;
        $session->save();

        return NodeResult::waiting((int) ($nodeConfig['seconds'] ?? 0));
    }
}
