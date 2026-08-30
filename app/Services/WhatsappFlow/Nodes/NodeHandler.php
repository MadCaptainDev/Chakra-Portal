<?php

namespace App\Services\WhatsappFlow\Nodes;

use App\Models\WhatsappFlowSession;

/**
 * What every node type in a flow's graph implements.
 *
 * A handler does exactly one node's work and reports back where the walk
 * goes next -- it never loops, never checks iteration/visit/time caps, and
 * never decides whether the session as a whole is done. That bookkeeping is
 * FlowEngine's alone, so that a handler which never returns `ended` (by bug
 * or by a hand-built graph feeding it garbage) still cannot run the engine
 * forever.
 */
interface NodeHandler
{
    /**
     * @param  array<string, mixed>  $nodeConfig  this node's own entry from
     *                                            the flow's graph -- its `type` plus whatever keys that type needs
     *                                            (a `next` node id, branch targets, message text, and so on).
     */
    public function handle(WhatsappFlowSession $session, array $nodeConfig): NodeResult;
}
