<?php

namespace App\Services\WhatsappFlow\Nodes;

use App\Models\WhatsappFlowSession;
use App\Services\WhatsappSender;

/**
 * Sends an approved Meta template -- the node to reach for outside the
 * 24-hour window SendMessageNode is confined to.
 *
 * Config: `template` (name), optional `language` (defaults to en_US) and
 * `body_parameters` (positional strings for {{1}}, {{2}} ...), optional `to`
 * (defaults to the session's wa_id), `next`.
 */
class SendTemplateNode implements NodeHandler
{
    public function handle(WhatsappFlowSession $session, array $nodeConfig): NodeResult
    {
        WhatsappSender::make()->sendTemplate(
            $nodeConfig['to'] ?? $session->wa_id,
            (string) ($nodeConfig['template'] ?? ''),
            $nodeConfig['language'] ?? 'en_US',
            $nodeConfig['body_parameters'] ?? [],
        );

        return NodeResult::advance($nodeConfig['next'] ?? null);
    }
}
