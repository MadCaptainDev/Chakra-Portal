<?php

namespace App\Jobs;

use App\Models\WhatsappFlowSession;
use App\Services\WhatsappFlow\FlowEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The delayed half of a DelayNode: fires once its wait has elapsed and hands
 * the session straight back to FlowEngine to keep walking from wherever
 * DelayNode left current_node_id.
 *
 * Constructed with the session's id rather than the model, the way every
 * other job in this app is -- so a delay measured in hours does not hold a
 * stale, possibly-since-changed row in the queue payload.
 */
class AdvanceWhatsappFlowSession implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $sessionId) {}

    public function handle(FlowEngine $engine): void
    {
        $session = WhatsappFlowSession::find($this->sessionId);

        if (! $session) {
            return;
        }

        $engine->resume($session);
    }
}
