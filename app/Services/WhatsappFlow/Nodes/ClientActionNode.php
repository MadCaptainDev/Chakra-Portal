<?php

namespace App\Services\WhatsappFlow\Nodes;

use App\Models\WhatsappFlowSession;
use App\Support\ClientPortalContent;
use RuntimeException;

/**
 * Sends client-specific content: invoices, monthly report, or upcoming shoots.
 *
 * Only works when the session belongs to an activated client portal number
 * (client.id is injected by FlowEngine on client_portal flows).
 */
class ClientActionNode implements NodeHandler
{
    public function handle(WhatsappFlowSession $session, array $nodeConfig): NodeResult
    {
        $client = ClientPortalContent::clientForSession($session);

        if ($client === null) {
            throw new RuntimeException('No activated client is linked to this WhatsApp number.');
        }

        $body = match ($nodeConfig['action'] ?? '') {
            'invoices' => ClientPortalContent::invoices($client),
            'monthly_report' => ClientPortalContent::monthlyReport($client),
            'upcoming_shoots' => ClientPortalContent::upcomingShoots($client),
            default => throw new RuntimeException('Choose what this Client Action node should send.'),
        };

        ClientPortalContent::sendToSession($session, $body);

        return NodeResult::advance($nodeConfig['next'] ?? null);
    }
}
