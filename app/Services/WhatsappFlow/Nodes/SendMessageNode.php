<?php

namespace App\Services\WhatsappFlow\Nodes;

use App\Models\WhatsappFlowSession;
use App\Services\WhatsappSender;

/**
 * Sends free text to the number the session belongs to.
 *
 * Config: `body` (the text), optional `to` (defaults to the session's
 * wa_id -- a graph would only override it to reach someone other than the
 * person who triggered the flow), `next`.
 */
class SendMessageNode implements NodeHandler
{
    public function handle(WhatsappFlowSession $session, array $nodeConfig): NodeResult
    {
        WhatsappSender::make()->sendText(
            $nodeConfig['to'] ?? $session->wa_id,
            (string) ($nodeConfig['body'] ?? ''),
        );

        return NodeResult::advance($nodeConfig['next'] ?? null);
    }
}
