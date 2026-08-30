<?php

namespace App\Services\WhatsappFlow;

use App\Jobs\AdvanceWhatsappFlowSession;
use App\Models\WhatsappFlow;
use App\Models\WhatsappFlowSession;
use App\Models\WhatsappWebhookEvent;
use App\Services\WhatsappFlow\Nodes\AgentTransferNode;
use App\Services\WhatsappFlow\Nodes\ConditionNode;
use App\Services\WhatsappFlow\Nodes\DelayNode;
use App\Services\WhatsappFlow\Nodes\MakeRequestNode;
use App\Services\WhatsappFlow\Nodes\NodeHandler;
use App\Services\WhatsappFlow\Nodes\SendMessageNode;
use App\Services\WhatsappFlow\Nodes\SendTemplateNode;
use App\Services\WhatsappFlow\Nodes\SetLabelNode;
use Illuminate\Support\Arr;

/**
 * Walks a WhatsappFlowSession through its flow's graph, one node at a time.
 *
 * Two entry points, both funnelling into the same run() loop:
 * - handleInbound(): an inbound message either continues that number's
 *   already-active session, or -- if there is none -- starts one from
 *   whichever is_active flow it matches, then runs it.
 * - resume(): AdvanceWhatsappFlowSession calls this once a DelayNode's wait
 *   has elapsed, to pick a still-active session back up.
 *
 * Loop protection lives here and only here, checked at the top of every
 * iteration before a node handler is ever invoked -- a handler that always
 * reports "advance" (by bug, or by a hand-built graph that never reaches an
 * end node) still cannot make this loop run forever, because the caps are
 * enforced independently of whatever the handler returns.
 */
class FlowEngine
{
    public const MAX_ITERATIONS = 50;

    public const MAX_NODE_VISITS = 5;

    public const MAX_EXECUTION_SECONDS = 120;

    /** @var array<string, class-string<NodeHandler>> */
    private const HANDLERS = [
        'send_message' => SendMessageNode::class,
        'send_template' => SendTemplateNode::class,
        'condition' => ConditionNode::class,
        'delay' => DelayNode::class,
        'set_label' => SetLabelNode::class,
        'agent_transfer' => AgentTransferNode::class,
        'make_request' => MakeRequestNode::class,
    ];

    public function handleInbound(WhatsappWebhookEvent $event): void
    {
        if (blank($event->wa_id)) {
            return;
        }

        $session = WhatsappFlowSession::where('wa_id', $event->wa_id)
            ->where('status', 'active')
            ->latest('id')
            ->first();

        if (! $session) {
            $session = $this->startSession($event);
        }

        if (! $session) {
            return;
        }

        $this->run($session);
    }

    /**
     * Picks the session back up after a DelayNode's wait. A no-op if the
     * session has since ended some other way (marked failed/expired between
     * the delay being scheduled and the job running).
     */
    public function resume(WhatsappFlowSession $session): void
    {
        if ($session->status !== 'active') {
            return;
        }

        $this->run($session);
    }

    private function startSession(WhatsappWebhookEvent $event): ?WhatsappFlowSession
    {
        $flow = $this->matchFlow($event);

        if (! $flow) {
            return null;
        }

        $startNodeId = data_get($flow->graph, 'start_node_id');

        if (blank($startNodeId)) {
            return null;
        }

        return WhatsappFlowSession::create([
            'flow_id' => $flow->id,
            'wa_id' => $event->wa_id,
            'current_node_id' => $startNodeId,
            'variables' => [],
            'status' => 'active',
            'iteration_count' => 0,
            'started_at' => now(),
        ]);
    }

    /**
     * `keyword` flows are checked before the `inbound_message` catch-all, so
     * a studio can run one default flow alongside any number of keyword
     * flows without the default swallowing every message before a keyword
     * gets a chance to match.
     */
    private function matchFlow(WhatsappWebhookEvent $event): ?WhatsappFlow
    {
        $text = mb_strtolower((string) ($event->summary ?? ''));

        $candidates = WhatsappFlow::query()
            ->where('is_active', true)
            ->whereIn('trigger_type', ['keyword', 'inbound_message'])
            ->get();

        $keywordMatch = $candidates->first(function (WhatsappFlow $flow) use ($text) {
            if ($flow->trigger_type !== 'keyword') {
                return false;
            }

            $keyword = data_get($flow->trigger_config, 'keyword');

            return filled($keyword) && str_contains($text, mb_strtolower($keyword));
        });

        return $keywordMatch ?? $candidates->first(fn (WhatsappFlow $flow) => $flow->trigger_type === 'inbound_message');
    }

    private function run(WhatsappFlowSession $session): void
    {
        $flow = $session->flow;

        if (! $flow) {
            $session->update(['status' => 'failed']);

            return;
        }

        $maxIterations = (int) (data_get($flow->graph, 'limits.max_iterations') ?? self::MAX_ITERATIONS);
        $maxNodeVisits = (int) (data_get($flow->graph, 'limits.max_node_visits') ?? self::MAX_NODE_VISITS);
        $maxExecutionSeconds = (int) (data_get($flow->graph, 'limits.max_execution_seconds') ?? self::MAX_EXECUTION_SECONDS);

        while (true) {
            if ($session->started_at && $session->started_at->diffInSeconds(now()) > $maxExecutionSeconds) {
                $session->update(['status' => 'failed']);

                return;
            }

            if ($session->iteration_count >= $maxIterations) {
                $session->update(['status' => 'failed']);

                return;
            }

            $nodeId = $session->current_node_id;
            $nodeConfig = $nodeId !== null ? data_get($flow->graph, "nodes.{$nodeId}") : null;

            if ($nodeId === null || ! is_array($nodeConfig)) {
                $session->update(['status' => 'completed', 'current_node_id' => null]);

                return;
            }

            $visits = Arr::get($session->variables, '_visits', []);
            $visits[$nodeId] = ($visits[$nodeId] ?? 0) + 1;

            if ($visits[$nodeId] > $maxNodeVisits) {
                $variables = $session->variables ?? [];
                $variables['_visits'] = $visits;
                $session->update(['status' => 'failed', 'variables' => $variables]);

                return;
            }

            $handlerClass = self::HANDLERS[$nodeConfig['type'] ?? null] ?? null;

            if (! $handlerClass) {
                $variables = $session->variables ?? [];
                $variables['_visits'] = $visits;
                $session->update(['status' => 'failed', 'variables' => $variables]);

                return;
            }

            $variables = $session->variables ?? [];
            $variables['_visits'] = $visits;
            $session->variables = $variables;
            $session->iteration_count += 1;
            $session->last_advanced_at = now();
            $session->save();

            /** @var NodeHandler $handler */
            $handler = app($handlerClass);
            $result = $handler->handle($session, $nodeConfig);

            if ($result->isEnded()) {
                $session->update(['status' => 'completed', 'current_node_id' => null]);

                return;
            }

            if ($result->isWaiting()) {
                // DelayNode has already saved current_node_id to where this
                // session should resume -- dispatching the job is the only
                // thing left for the engine to own here.
                AdvanceWhatsappFlowSession::dispatch($session->id)
                    ->delay(now()->addSeconds($result->delaySeconds ?? 0));

                return;
            }

            if ($result->nextNodeId === null) {
                $session->update(['status' => 'completed', 'current_node_id' => null]);

                return;
            }

            $session->update(['current_node_id' => $result->nextNodeId]);
        }
    }
}
