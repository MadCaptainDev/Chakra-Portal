<?php

namespace App\Services\WhatsappFlow;

use App\Jobs\AdvanceWhatsappFlowSession;
use App\Jobs\AnswerAdminOnWhatsapp;
use App\Models\Client;
use App\Models\User;
use App\Models\WhatsappConversation;
use App\Models\WhatsappFlow;
use App\Models\WhatsappFlowSession;
use App\Models\WhatsappLabel;
use App\Models\WhatsappWebhookEvent;
use App\Services\AdminAgent\AdminAgent;
use App\Services\WhatsappFlow\Nodes\AdminActionNode;
use App\Services\WhatsappFlow\Nodes\AgentTransferNode;
use App\Services\WhatsappFlow\Nodes\ClientActionNode;
use App\Services\WhatsappFlow\Nodes\ConditionNode;
use App\Services\WhatsappFlow\Nodes\CrewActionNode;
use App\Services\WhatsappFlow\Nodes\DelayNode;
use App\Services\WhatsappFlow\Nodes\MakeRequestNode;
use App\Services\WhatsappFlow\Nodes\NodeHandler;
use App\Services\WhatsappFlow\Nodes\SendListNode;
use App\Services\WhatsappFlow\Nodes\SendMessageNode;
use App\Services\WhatsappFlow\Nodes\SendTemplateNode;
use App\Services\WhatsappFlow\Nodes\SetLabelNode;
use App\Support\ClientPortalContent;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use RuntimeException;
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

    /**
     * How long after a staff menu was sent a bare answer to it still counts
     * as an answer to it -- see staffMenuContinuation().
     */
    public const MENU_CONTINUATION_MINUTES = 60;

    /** @var array<string, class-string<NodeHandler>> */
    private const HANDLERS = [
        'send_message' => SendMessageNode::class,
        'send_template' => SendTemplateNode::class,
        'send_list' => SendListNode::class,
        'condition' => ConditionNode::class,
        'delay' => DelayNode::class,
        'set_label' => SetLabelNode::class,
        'agent_transfer' => AgentTransferNode::class,
        'make_request' => MakeRequestNode::class,
        'client_action' => ClientActionNode::class,
        'crew_action' => CrewActionNode::class,
        'admin_action' => AdminActionNode::class,
    ];

    public function handleInbound(WhatsappWebhookEvent $event): void
    {
        if (blank($event->wa_id)) {
            return;
        }

        if ($this->handedToAssistant($event)) {
            return;
        }

        if (Client::findForWhatsappPortal($event->wa_id) !== null) {
            WhatsappFlowSession::query()
                ->where('wa_id', $event->wa_id)
                ->where('status', 'active')
                ->whereHas('flow', fn ($query) => $query->where('trigger_type', '!=', 'client_portal'))
                ->update(['status' => 'completed', 'current_node_id' => null]);
        }

        $session = WhatsappFlowSession::where('wa_id', $event->wa_id)
            ->where('status', 'active')
            ->latest('id')
            ->first();

        if (! $session) {
            $session = $this->startSession($event);
        }

        if (! $session) {
            $this->handleUnmatchedPortalMessage($event);

            return;
        }

        $this->recordInboundMessage($session, $event);

        // A session parked by DelayNode still reads as `active` -- that is
        // what lets AdvanceWhatsappFlowSession resume it later -- so without
        // this check any message the contact sends before that delayed job
        // fires would walk straight through the wait via this same
        // handleInbound() path. The message is still recorded above (not
        // lost), it just does not trigger advancement while the wait is
        // still genuinely outstanding. resume() -- the delayed job's own
        // path -- never calls handleInbound(), so it is never subject to
        // this check; it only ever fires once expires_at has passed anyway.
        if ($session->expires_at !== null && $session->expires_at->isFuture()) {
            return;
        }

        $this->run($session);
    }

    /**
     * Whether the studio's assistant is taking this message instead.
     *
     * The one place a flow gives way to something else. AdminAgent::claimant()
     * answers null for anything that is not an admin typing a question with
     * the assistant switched on and keyed -- so with the assistant off, or a
     * key never pasted, this is a no-op and every flow behaves exactly as it
     * did before it existed.
     *
     * Any menu session open on that number is closed first. Two things
     * answering one thread would interleave their messages, and the menu's
     * answer to a tap the admin made a minute ago is not what they are asking
     * about now.
     */
    private function handedToAssistant(WhatsappWebhookEvent $event): bool
    {
        $admin = AdminAgent::claimant($event);

        if ($admin === null) {
            return false;
        }

        WhatsappFlowSession::query()
            ->where('wa_id', $event->wa_id)
            ->where('status', 'active')
            ->update(['status' => 'completed', 'current_node_id' => null]);

        AnswerAdminOnWhatsapp::dispatch(
            (string) $event->wa_id,
            $admin->id,
            (string) $event->summary,
        );

        return true;
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

        return $flow ? $this->openSession($flow, $event->wa_id) : null;
    }

    /**
     * Opens a session on a flow for one number, without running it.
     *
     * The one place a session is created, whatever started it -- an inbound
     * message, a label, the clock. A flow whose start node was never marked
     * yields null rather than a session parked on nothing.
     *
     * @param  array<string, mixed>  $variables
     */
    private function openSession(WhatsappFlow $flow, string $waId, array $variables = []): ?WhatsappFlowSession
    {
        $graph = is_array($flow->graph) ? $flow->graph : [];
        $startNodeId = $graph['start_node_id'] ?? null;

        if (blank($startNodeId) || blank($waId)) {
            return null;
        }

        return WhatsappFlowSession::create([
            'flow_id' => $flow->id,
            'wa_id' => $waId,
            'current_node_id' => $startNodeId,
            'variables' => array_merge(self::identityVariables($waId), $variables),
            'status' => 'active',
            'iteration_count' => 0,
            'started_at' => now(),
        ]);
    }

    /**
     * Someone labelled a conversation in the inbox: run the flow watching for
     * that label, if one is active.
     *
     * Called only from the inbox's own attach-label action, deliberately NOT
     * from SetLabelNode. A flow that labels a conversation would otherwise be
     * able to trigger a flow that labels a conversation, and the engine's loop
     * protection is per-session -- it counts nodes inside one run and would
     * never see two flows taking turns. Keeping the trigger to the human
     * action makes that loop unconstructable rather than merely unlikely.
     *
     * A number with a session already running is left alone: it is mid-
     * conversation, and cutting in with a second flow would interleave two
     * sets of messages in one thread.
     */
    public function handleLabelApplied(WhatsappConversation $conversation, WhatsappLabel $label): void
    {
        $waId = (string) $conversation->wa_id;

        if (blank($waId)) {
            return;
        }

        $flow = WhatsappFlow::query()
            ->where('is_active', true)
            ->where('trigger_type', 'label_applied')
            ->get()
            ->first(function (WhatsappFlow $candidate) use ($label) {
                $watched = data_get($candidate->trigger_config, 'label');

                return filled($watched) && mb_strtolower(trim($watched)) === mb_strtolower(trim($label->name));
            });

        if (! $flow) {
            return;
        }

        $alreadyTalking = WhatsappFlowSession::query()
            ->where('wa_id', $waId)
            ->where('status', 'active')
            ->exists();

        if ($alreadyTalking) {
            return;
        }

        $session = $this->openSession($flow, $waId, [
            'label' => ['name' => $label->name],
        ]);

        if ($session) {
            $this->run($session);
        }
    }

    /**
     * Starts a scheduled flow for one recipient. ScheduledFlowRunner owns the
     * "is it due, and who for" question; this only opens and runs it.
     */
    public function startScheduled(WhatsappFlow $flow, string $waId): void
    {
        $session = $this->openSession($flow, $waId, [
            'scheduled' => ['at' => now()->toIso8601String()],
        ]);

        if ($session) {
            $this->run($session);
        }
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
        $normalized = mb_strtolower(trim((string) ($event->summary ?? '')));

        // The stable id behind a tap, not its (user-visible, reworded-
        // able) title -- interactive.*_reply.title is what describeMessage()
        // already put in $event->summary, so `normalized` above is
        // unaffected and every existing flow keeps its exact behaviour.
        // button.payload covers a template's own quick-reply buttons, the
        // same shape as an interactive button reply.
        $payload = is_array($event->payload) ? $event->payload : [];
        $replyId = data_get($payload, 'interactive.list_reply.id')
            ?? data_get($payload, 'interactive.button_reply.id')
            ?? data_get($payload, 'button.payload');

        $variables['message'] = [
            'text' => $event->summary,
            'normalized' => $normalized,
            'type' => $event->message_type,
            // One key a condition can branch on regardless of how the
            // answer arrived: the tapped row's id, or the typed text
            // otherwise -- see SendListNode's own doc block.
            'choice' => filled($replyId) ? mb_strtolower(trim((string) $replyId)) : $normalized,
        ];

        // Set only when present, never to null: ConditionNode's `exists`
        // operator (Arr::has()) is true for a present-but-null key, so a
        // flow author relying on `message.reply_id exists` to tell a tap
        // from typed text would otherwise see it "exist" on every message.
        if (filled($replyId)) {
            $variables['message']['reply_id'] = (string) $replyId;
        }

        $session->variables = array_merge($variables, self::identityVariables($event->wa_id));
        $session->save();
    }

    /**
     * Who this number belongs to, as flow variables.
     *
     * Shared by every path that can start a session -- an inbound message, a
     * label applied in the inbox, a scheduled run -- so `client.name` and
     * `crew.id` mean the same thing in a flow however that flow was reached.
     *
     * `crew` is also the gate CrewActionNode and AdminActionNode read: set
     * only for a number belonging to one of the studio's own people, so
     * `crew.id exists` is a flow author's way of saying "this branch is for
     * us, not for a client or a stranger".
     *
     * @return array<string, mixed>
     */
    public static function identityVariables(string $waId): array
    {
        $variables = [];

        if ($client = Client::findForWhatsappPortal($waId)) {
            $variables['client'] = [
                'id' => $client->id,
                'name' => $client->name,
            ];
        }

        if ($crew = User::findForWhatsappCrew($waId)) {
            $variables['crew'] = [
                'id' => $crew->id,
                'name' => $crew->name,
                // Lets one flow serve both without a second flow: branch on
                // `crew.is_admin equals true` for the owner-only rows.
                'is_admin' => $crew->isAdmin(),
            ];
        }

        return $variables;
    }

    /**
     * `keyword` flows are checked before the `inbound_message` catch-all, so
     * a studio can run one default flow alongside any number of keyword
     * flows without the default swallowing every message before a keyword
     * gets a chance to match.
     */
    private function matchFlow(WhatsappWebhookEvent $event): ?WhatsappFlow
    {
        if (Client::findForWhatsappPortal($event->wa_id) !== null) {
            return WhatsappFlow::query()
                ->where('is_active', true)
                ->where('trigger_type', 'client_portal')
                ->orderBy('id')
                ->first();
        }

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

        return $keywordMatch
            ?? $candidates->first(fn (WhatsappFlow $flow) => $flow->trigger_type === 'inbound_message')
            ?? $this->staffMenuContinuation($event);
    }

    /**
     * The staff half of the client_portal short-circuit at the top of
     * matchFlow(): what lets one of the studio's own people answer a menu.
     *
     * A menu node sends and ends -- SendListNode is stateless on purpose, and
     * the tap that follows arrives as a brand new inbound message that has to
     * find its flow again through matchFlow(). A portal client always does:
     * their wa_id alone routes them, whatever they tapped. Staff had no such
     * path. Their flow is reached by keyword ("studio"), and the tap carries
     * the row's title ("Money") -- which contains no keyword, so it matched no
     * flow and was silently dropped. The menu arrived, every answer to it went
     * nowhere, and nothing anywhere said so: the session had already completed,
     * so there was no failed row to find either.
     *
     * So: a staff number that has just been talking to a flow keeps talking to
     * that flow. Three things bound it, because this is the one matcher that
     * needs no keyword and so must not become a catch-all:
     * - only a number belonging to staff, by the same last-ten-digit lookup
     *   CrewPortal and AdminPortal gate on;
     * - only within MENU_CONTINUATION_MINUTES of that flow's last step, so a
     *   message the next morning is a fresh message rather than an answer to
     *   yesterday's menu;
     * - never once a human owns the thread (AgentTransferNode's assignment),
     *   and never from a session that failed -- re-entering a flow that just
     *   threw would only throw again on the same node.
     */
    private function staffMenuContinuation(WhatsappWebhookEvent $event): ?WhatsappFlow
    {
        if (User::findForWhatsappCrew($event->wa_id) === null) {
            return null;
        }

        $assignedTo = WhatsappConversation::query()
            ->where('wa_id', $event->wa_id)
            ->value('assigned_to_id');

        if ($assignedTo !== null) {
            return null;
        }

        $previous = WhatsappFlowSession::query()
            ->where('wa_id', $event->wa_id)
            ->where('status', 'completed')
            ->where('last_advanced_at', '>=', now()->subMinutes(self::MENU_CONTINUATION_MINUTES))
            ->whereHas('flow', fn ($query) => $query
                ->where('is_active', true)
                ->whereIn('trigger_type', ['keyword', 'inbound_message']))
            ->latest('id')
            ->first();

        return $previous?->flow;
    }

    /**
     * Portal-enabled clients never fall through to the generic catch-all.
     * If no automation is active yet, tell them plainly and log it for staff.
     */
    private function handleUnmatchedPortalMessage(WhatsappWebhookEvent $event): void
    {
        $client = Client::findForWhatsappPortal($event->wa_id);

        if ($client === null) {
            return;
        }

        Log::warning('WhatsApp client portal message received but no active automation is configured.', [
            'wa_id' => $event->wa_id,
            'client_id' => $client->id,
            'summary' => $event->summary,
        ]);

        try {
            ClientPortalContent::sendToSession(
                new WhatsappFlowSession(['wa_id' => $event->wa_id, 'variables' => ['client' => ['id' => $client->id, 'name' => $client->name]]]),
                'Hi '.$client->name.' — our WhatsApp menu is being set up. Someone from the studio will reply shortly.',
            );
        } catch (RuntimeException $e) {
            Log::error('Could not reply to portal client.', ['error' => $e->getMessage(), 'wa_id' => $event->wa_id]);
        }
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
