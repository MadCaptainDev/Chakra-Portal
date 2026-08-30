<?php

namespace App\Services\WhatsappFlow\Nodes;

use App\Models\WhatsappConversation;
use App\Models\WhatsappFlowSession;

/**
 * Hands the conversation to a human and ends the flow -- once a person owns
 * the thread, no automation should keep walking the graph underneath them.
 *
 * Config: `user_id` (who to assign). If the wa_id has no WhatsappConversation
 * row yet, there is nothing to assign, but the handoff still ends the flow.
 */
class AgentTransferNode implements NodeHandler
{
    public function handle(WhatsappFlowSession $session, array $nodeConfig): NodeResult
    {
        $userId = $nodeConfig['user_id'] ?? null;

        if ($userId) {
            WhatsappConversation::where('wa_id', $session->wa_id)->update(['assigned_to_id' => $userId]);
        }

        return NodeResult::ended();
    }
}
