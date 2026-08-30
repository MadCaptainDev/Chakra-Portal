<?php

namespace App\Services\WhatsappFlow\Nodes;

use App\Models\WhatsappFlowSession;
use Illuminate\Support\Facades\Http;

/**
 * Calls out to a webhook a flow's own graph names -- strictly outbound HTTP,
 * nothing else.
 *
 * This is the deliberate, safe replacement for the reference automation
 * spec's raw-SQL node: a flow's JSON config can never reach the database
 * through this class, or through any other node in this package. Do not add
 * a database/query escape hatch here, no matter how the config is shaped.
 *
 * Config: `url`, optional `payload` (posted as-is), `next`. The response is
 * discarded -- a flow that needs the reply to branch on is a future node
 * type, not this one.
 */
class MakeRequestNode implements NodeHandler
{
    public function handle(WhatsappFlowSession $session, array $nodeConfig): NodeResult
    {
        Http::timeout(10)->post((string) ($nodeConfig['url'] ?? ''), $nodeConfig['payload'] ?? []);

        return NodeResult::advance($nodeConfig['next'] ?? null);
    }
}
