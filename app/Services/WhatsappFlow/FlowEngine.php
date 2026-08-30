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
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Walks a WhatsappFlowSession through its flow's graph, one node at a time.
 *
 * Two entry points, both funnelling into the same run() loop:
 * - handleInbound(): an inbound message either continues that number's
 *   already-active session, or -- if there is none -- starts one from
 *   whichever is_active flow it matches, seeds the session's variables with
 *   the message that just arrived, then runs it.
 * - resume(): AdvanceWhatsappFlowSession calls this once a DelayNode's wait
 *   has elapsed, to pick a still-active session back up.
 *
 * Loop protection lives here and only here, checked at the top of every
 * iteration before a node handler is ever invoked -- a handler that always
 * reports "advance" (by bug, or by a hand-built graph that never reaches an
 * end node) still cannot make this loop run forever. Two things make that
 * true rather than just intended: the caps a flow's own graph can request
 * are clamped to never exceed this class's defaults (a flow cannot raise
 * its own ceiling), and the counters the caps are checked against are kept
 * in run()-local variables rather than re-read from $session between
 * iterations, so a handler that overwrites $session->variables wholesale
 * cannot reset the visit count for the run already in progress.
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

        $this->recordInboundMessage($session, $event);

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

        $graph = is_array($flow->graph) ? $flow->graph : [];
        $startNodeId = $graph['start_node_id'] ?? null;

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
     * Seeds the session's variables with the message that just arrived, so
     * ConditionNode has something real to branch on the moment a flow
     * reaches one -- `message.text`/`message.type` are the two things
     * every inbound webhook event already carries. Merged into whatever is
     * already there rather than replacing `variables` wholesale, so this
     * never clobbers `_visits` or a variable an earlier node already set
     * for a session that is mid-flow.
     */
    private function recordInboundMessage(WhatsappFlowSession $session, WhatsappWebhookEvent $event): void
    {
        $variables = $session->variables ?? [];
        $variables['message'] = [
            'text' => $event->summary,
            'type' => $event->message_type,
        ];
        $session->variables = $variables;
        $session->save();
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
            $this->fail($session, 'The session\'s flow no longer exists.');

            return;
        }

        $graph = is_array($flow->graph) ? $flow->graph : [];
        $limits = is_array($graph['limits'] ?? null) ? $graph['limits'] : [];
        $nodes = is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [];

        // A flow's own graph may only ever tighten these, never raise them
        // -- min() against the class default is what makes that a fact
        // rather than a convention. Without it, a flow's JSON (ultimately
        // user-authored, once a visual editor writes it) could set
        // max_iterations to a huge number and disable this engine's own
        // protection from the inside.
        $maxIterations = min((int) ($limits['max_iterations'] ?? self::MAX_ITERATIONS), self::MAX_ITERATIONS);
        $maxNodeVisits = min((int) ($limits['max_node_visits'] ?? self::MAX_NODE_VISITS), self::MAX_NODE_VISITS);
        $maxExecutionSeconds = min((int) ($limits['max_execution_seconds'] ?? self::MAX_EXECUTION_SECONDS), self::MAX_EXECUTION_SECONDS);

        // Authoritative for this run. Seeded from the session's persisted
        // state (so a resumed session remembers its history across the gap
        // a DelayNode leaves), then only ever advanced locally and written
        // back to $session for persistence/observability -- never re-read
        // from $session mid-run, so nothing a handler does to
        // $session->variables can reset them out from under this loop.
        $iterations = $session->iteration_count;
        $visits = Arr::get($session->variables, '_visits', []);
        $visits = is_array($visits) ? $visits : [];

        // Bounds only *this* synchronous walk, starting fresh every time
        // run() is entered -- including a resume long after a real delay.
        // $session->started_at is the session's lifetime clock and must
        // never be used here: a session that legitimately waited an hour on
        // a DelayNode would otherwise fail the instant it resumed, before a
        // single node had a chance to run.
        $runStartedAt = microtime(true);

        while (true) {
            if (microtime(true) - $runStartedAt > $maxExecutionSeconds) {
                $this->fail($session, 'Exceeded max_execution_seconds for a single run.', $iterations, $visits);

                return;
            }

            if ($iterations >= $maxIterations) {
                $this->fail($session, 'Exceeded max_iterations.', $iterations, $visits);

                return;
            }

            $nodeId = $session->current_node_id;

            if ($nodeId === null) {
                $this->complete($session);

                return;
            }

            $nodeConfig = $nodes[$nodeId] ?? null;

            if (! is_array($nodeConfig)) {
                $this->fail($session, "Flow graph has no node '{$nodeId}'.", $iterations, $visits);

                return;
            }

            $visits[$nodeId] = ($visits[$nodeId] ?? 0) + 1;

            if ($visits[$nodeId] > $maxNodeVisits) {
                $this->fail($session, "Exceeded max_node_visits on node '{$nodeId}'.", $iterations, $visits);

                return;
            }

            $handlerClass = self::HANDLERS[$nodeConfig['type'] ?? null] ?? null;

            if (! $handlerClass) {
                $this->fail(
                    $session,
                    "Unknown node type '".($nodeConfig['type'] ?? '(none)')."' on node '{$nodeId}'.",
                    $iterations,
                    $visits,
                );

                return;
            }

            $iterations++;

            $variables = $session->variables ?? [];
            $variables['_visits'] = $visits;
            $session->variables = $variables;
            $session->iteration_count = $iterations;
            $session->last_advanced_at = now();
            $session->save();

            try {
                /** @var NodeHandler $handler */
                $handler = app($handlerClass);
                $result = $handler->handle($session, $nodeConfig);
            } catch (Throwable $e) {
                // A node handler throwing (WhatsApp not configured, a
                // MakeRequestNode timeout/DNS failure, ...) must not leave
                // the session sitting `active` on the node that just threw
                // -- left alone, the very next inbound message from this
                // number would re-enter here and re-throw on the same node
                // forever, burning the visit cap on failures instead of
                // progress once this is wired into the webhook path.
                Log::error('WhatsApp flow node handler threw.', [
                    'session_id' => $session->id,
                    'flow_id' => $session->flow_id,
                    'node_id' => $nodeId,
                    'exception' => $e->getMessage(),
                ]);

                $session->update(['status' => 'failed', 'last_error' => $e->getMessage()]);

                return;
            }

            if ($result->isEnded()) {
                $this->complete($session);

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
                $this->complete($session);

                return;
            }

            $session->update(['current_node_id' => $result->nextNodeId]);
        }
    }

    /**
     * Ends a session as `failed`, recording why. $iterationCount/$visits are
     * only passed by the loop-protection abort paths (there is run-local
     * bookkeeping to persist back); the one abort that can happen before
     * the loop even starts -- the session's flow having been deleted out
     * from under it -- has none to give, so both are optional.
     *
     * @param  array<string, int>|null  $visits
     */
    private function fail(WhatsappFlowSession $session, string $reason, ?int $iterationCount = null, ?array $visits = null): void
    {
        Log::warning('WhatsApp flow session aborted.', [
            'session_id' => $session->id,
            'flow_id' => $session->flow_id,
            'node_id' => $session->current_node_id,
            'reason' => $reason,
        ]);

        $attributes = ['status' => 'failed', 'last_error' => $reason];

        if ($iterationCount !== null) {
            $attributes['iteration_count'] = $iterationCount;
        }

        if ($visits !== null) {
            $variables = $session->variables ?? [];
            $variables['_visits'] = $visits;
            $attributes['variables'] = $variables;
        }

        $session->update($attributes);
    }

    private function complete(WhatsappFlowSession $session): void
    {
        $session->update(['status' => 'completed', 'current_node_id' => null]);
    }
}
