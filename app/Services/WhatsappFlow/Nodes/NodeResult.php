<?php

namespace App\Services\WhatsappFlow\Nodes;

/**
 * What a node handler hands back to FlowEngine: where to go next, or that
 * there is nowhere to go right now.
 *
 * Three shapes, and a handler picks exactly one:
 * - advance($nextNodeId): keep walking immediately. $nextNodeId of null ends
 *   the flow (there is nothing after this node in the graph).
 * - waiting($delaySeconds): stop walking now; DelayNode has already saved
 *   the session's current_node_id itself, so this only needs to carry how
 *   long FlowEngine should wait before queuing the resume.
 * - ended(): the flow is done on purpose (AgentTransferNode handing off to a
 *   human, for instance) -- FlowEngine marks the session completed.
 */
final class NodeResult
{
    private function __construct(
        public readonly string $status,
        public readonly ?string $nextNodeId = null,
        public readonly ?int $delaySeconds = null,
    ) {}

    public static function advance(?string $nextNodeId): self
    {
        return new self('advance', $nextNodeId);
    }

    public static function waiting(int $delaySeconds): self
    {
        return new self('waiting', delaySeconds: $delaySeconds);
    }

    public static function ended(): self
    {
        return new self('ended');
    }

    public function isAdvance(): bool
    {
        return $this->status === 'advance';
    }

    public function isWaiting(): bool
    {
        return $this->status === 'waiting';
    }

    public function isEnded(): bool
    {
        return $this->status === 'ended';
    }
}
