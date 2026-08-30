<?php

namespace Tests\Unit;

use App\Jobs\AdvanceWhatsappFlowSession;
use App\Models\User;
use App\Models\WhatsappConversation;
use App\Models\WhatsappFlow;
use App\Models\WhatsappFlowSession;
use App\Models\WhatsappLabel;
use App\Models\WhatsappSetting;
use App\Models\WhatsappWebhookEvent;
use App\Services\WhatsappFlow\FlowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Each test drives FlowEngine directly against a hand-built graph array --
 * never through the webhook HTTP route -- exercising exactly one node type
 * per case. Most tests seed a session already sitting on the node under
 * test (rather than relying on trigger matching to get there), because that
 * is the part of the engine each of these cases actually wants to prove.
 */
class FlowEngineTest extends TestCase
{
    use RefreshDatabase;

    private function configuredSender(): void
    {
        WhatsappSetting::current()->update([
            'access_token' => 'EAAG-test-token',
            'phone_number_id' => '556677889900',
        ]);
    }

    /** @param  array<string, mixed>  $graph */
    private function flow(array $graph, string $triggerType = 'inbound_message'): WhatsappFlow
    {
        return WhatsappFlow::create([
            'name' => 'Test flow',
            'trigger_type' => $triggerType,
            'graph' => $graph,
            'is_active' => true,
        ]);
    }

    private function inboundEvent(string $waId, string $summary = 'hello'): WhatsappWebhookEvent
    {
        return WhatsappWebhookEvent::create([
            'type' => WhatsappWebhookEvent::TYPE_MESSAGE,
            'dedupe_key' => 'test-'.$waId.'-'.uniqid('', true),
            'wa_id' => $waId,
            'message_type' => 'text',
            'summary' => $summary,
            'payload' => [],
            'received_at' => now(),
        ]);
    }

    /**
     * Seeds a session already parked on the given node, so a test can drive
     * the engine straight at the node type it is checking without also
     * having to exercise trigger matching.
     *
     * @param  array<string, mixed>  $variables
     */
    private function sessionAt(WhatsappFlow $flow, string $waId, string $nodeId, array $variables = []): WhatsappFlowSession
    {
        return WhatsappFlowSession::create([
            'flow_id' => $flow->id,
            'wa_id' => $waId,
            'current_node_id' => $nodeId,
            'variables' => $variables,
            'status' => 'active',
            'iteration_count' => 0,
            'started_at' => now(),
        ]);
    }

    // -- SendMessageNode -------------------------------------------------

    public function test_send_message_node_sends_free_text_and_ends_the_flow(): void
    {
        $this->configuredSender();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);

        $flow = $this->flow([
            'start_node_id' => 'greet',
            'nodes' => [
                'greet' => ['type' => 'send_message', 'body' => 'Hello from the flow!', 'next' => null],
            ],
        ], triggerType: 'keyword');
        $flow->update(['trigger_config' => ['keyword' => 'hello']]);

        (new FlowEngine)->handleInbound($this->inboundEvent('917000000001', 'hello there'));

        Http::assertSent(fn (Request $request) => $request->data()['type'] === 'text'
            && $request->data()['text']['body'] === 'Hello from the flow!'
            && $request->data()['to'] === '917000000001');

        $session = WhatsappFlowSession::sole();
        $this->assertSame('completed', $session->status);
        $this->assertNull($session->current_node_id);
        $this->assertSame(1, $session->iteration_count);
    }

    // -- SendTemplateNode --------------------------------------------------

    public function test_send_template_node_sends_an_approved_template(): void
    {
        $this->configuredSender();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.2']]])]);

        $flow = $this->flow([
            'start_node_id' => 'tpl',
            'nodes' => [
                'tpl' => [
                    'type' => 'send_template',
                    'template' => 'shoot_reminder',
                    'language' => 'en',
                    'body_parameters' => ['Friday'],
                    'next' => null,
                ],
            ],
        ]);
        $session = $this->sessionAt($flow, '917000000002', 'tpl');

        (new FlowEngine)->handleInbound($this->inboundEvent('917000000002'));

        Http::assertSent(fn (Request $request) => $request->data()['type'] === 'template'
            && $request->data()['template']['name'] === 'shoot_reminder'
            && $request->data()['template']['language']['code'] === 'en');

        $this->assertSame('completed', $session->fresh()->status);
    }

    // -- ConditionNode -------------------------------------------------

    public function test_condition_node_branches_true_when_the_operator_matches(): void
    {
        $this->configuredSender();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.3']]])]);

        $flow = $this->flow([
            'start_node_id' => 'check',
            'nodes' => [
                'check' => [
                    'type' => 'condition',
                    'variable' => 'wants_help',
                    'operator' => 'equals',
                    'value' => 'yes',
                    'next_true' => 'on_true',
                    'next_false' => 'on_false',
                ],
                'on_true' => ['type' => 'send_message', 'body' => 'Connecting you now.', 'next' => null],
                'on_false' => ['type' => 'send_message', 'body' => 'Okay, let us know if that changes.', 'next' => null],
            ],
        ]);
        $session = $this->sessionAt($flow, '917000000003', 'check', ['wants_help' => 'yes']);

        (new FlowEngine)->handleInbound($this->inboundEvent('917000000003'));

        Http::assertSent(fn (Request $request) => $request->data()['text']['body'] === 'Connecting you now.');
        $this->assertSame('completed', $session->fresh()->status);
    }

    public function test_condition_node_branches_false_when_the_operator_does_not_match(): void
    {
        $this->configuredSender();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.4']]])]);

        $flow = $this->flow([
            'start_node_id' => 'check',
            'nodes' => [
                'check' => [
                    'type' => 'condition',
                    'variable' => 'wants_help',
                    'operator' => 'equals',
                    'value' => 'yes',
                    'next_true' => 'on_true',
                    'next_false' => 'on_false',
                ],
                'on_true' => ['type' => 'send_message', 'body' => 'Connecting you now.', 'next' => null],
                'on_false' => ['type' => 'send_message', 'body' => 'Okay, let us know if that changes.', 'next' => null],
            ],
        ]);
        $session = $this->sessionAt($flow, '917000000004', 'check', ['wants_help' => 'no']);

        (new FlowEngine)->handleInbound($this->inboundEvent('917000000004'));

        Http::assertSent(fn (Request $request) => $request->data()['text']['body'] === 'Okay, let us know if that changes.');
        $this->assertSame('completed', $session->fresh()->status);
    }

    // -- DelayNode -------------------------------------------------

    public function test_delay_node_persists_the_resume_node_and_queues_the_advance_job(): void
    {
        Bus::fake();

        $flow = $this->flow([
            'start_node_id' => 'wait',
            'nodes' => [
                'wait' => ['type' => 'delay', 'seconds' => 3600, 'next' => 'after_wait'],
                'after_wait' => ['type' => 'agent_transfer', 'user_id' => null, 'next' => null],
            ],
        ]);
        $session = $this->sessionAt($flow, '917000000005', 'wait');

        (new FlowEngine)->handleInbound($this->inboundEvent('917000000005'));

        $session->refresh();
        // Still active -- a delay stops the walk, it does not end the session.
        $this->assertSame('active', $session->status);
        $this->assertSame('after_wait', $session->current_node_id);

        Bus::assertDispatched(fn (AdvanceWhatsappFlowSession $job) => $job->sessionId === $session->id);
    }

    // -- SetLabelNode -------------------------------------------------

    public function test_set_label_node_attaches_a_label_by_name_to_the_conversation(): void
    {
        $conversation = WhatsappConversation::create(['wa_id' => '917000000006']);

        $flow = $this->flow([
            'start_node_id' => 'tag',
            'nodes' => [
                'tag' => ['type' => 'set_label', 'label' => 'VIP', 'next' => null],
            ],
        ]);
        $session = $this->sessionAt($flow, '917000000006', 'tag');

        (new FlowEngine)->handleInbound($this->inboundEvent('917000000006'));

        $this->assertSame('completed', $session->fresh()->status);
        $this->assertTrue($conversation->fresh()->labels()->where('name', 'VIP')->exists());
        $this->assertSame('VIP', WhatsappLabel::sole()->name);
    }

    // -- AgentTransferNode -------------------------------------------------

    public function test_agent_transfer_node_assigns_the_conversation_and_ends_the_session(): void
    {
        $agent = User::factory()->create();
        $conversation = WhatsappConversation::create(['wa_id' => '917000000007']);

        $flow = $this->flow([
            'start_node_id' => 'handoff',
            'nodes' => [
                'handoff' => ['type' => 'agent_transfer', 'user_id' => $agent->id, 'next' => null],
            ],
        ]);
        $session = $this->sessionAt($flow, '917000000007', 'handoff');

        (new FlowEngine)->handleInbound($this->inboundEvent('917000000007'));

        $this->assertSame('completed', $session->fresh()->status);
        $this->assertNull($session->fresh()->current_node_id);
        $this->assertSame($agent->id, $conversation->fresh()->assigned_to_id);
    }

    // -- MakeRequestNode -------------------------------------------------

    public function test_make_request_node_posts_outbound_and_nothing_else(): void
    {
        Http::fake(['example.test/*' => Http::response(['ok' => true])]);

        $flow = $this->flow([
            'start_node_id' => 'notify',
            'nodes' => [
                'notify' => [
                    'type' => 'make_request',
                    'url' => 'https://example.test/hooks/flow',
                    'payload' => ['wa_id' => '917000000008', 'event' => 'flow_started'],
                    'next' => null,
                ],
            ],
        ]);
        $session = $this->sessionAt($flow, '917000000008', 'notify');

        (new FlowEngine)->handleInbound($this->inboundEvent('917000000008'));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://example.test/hooks/flow'
            && $request->data() === ['wa_id' => '917000000008', 'event' => 'flow_started']);
        $this->assertSame('completed', $session->fresh()->status);
    }

    // -- Trigger matching and session continuation --------------------------

    public function test_a_keyword_flow_only_starts_when_the_keyword_is_present(): void
    {
        Http::fake();

        $flow = $this->flow([
            'start_node_id' => 'n1',
            'nodes' => ['n1' => ['type' => 'agent_transfer', 'user_id' => null, 'next' => null]],
        ], triggerType: 'keyword');
        $flow->update(['trigger_config' => ['keyword' => 'pricing']]);

        (new FlowEngine)->handleInbound($this->inboundEvent('917000000009', 'what time is my shoot'));
        $this->assertSame(0, WhatsappFlowSession::count());

        (new FlowEngine)->handleInbound($this->inboundEvent('917000000009', 'What is your PRICING like?'));
        $this->assertSame(1, WhatsappFlowSession::count());
    }

    public function test_a_completed_sessions_flow_does_not_block_a_later_message_from_starting_a_fresh_session(): void
    {
        Http::fake();

        $flow = $this->flow([
            'start_node_id' => 'ask',
            'nodes' => [
                'ask' => ['type' => 'agent_transfer', 'user_id' => null, 'next' => null],
            ],
        ], triggerType: 'inbound_message');

        (new FlowEngine)->handleInbound($this->inboundEvent('917000000010', 'hi'));
        $this->assertSame(1, WhatsappFlowSession::count());
        $this->assertSame('completed', WhatsappFlowSession::sole()->status);

        // A further message with no active session left, and only the same
        // catch-all flow available, starts a fresh session rather than
        // reusing the completed one.
        (new FlowEngine)->handleInbound($this->inboundEvent('917000000010', 'hi again'));
        $this->assertSame(2, WhatsappFlowSession::count());
    }

    // -- Inbound message seeds variables (review finding 5, option a) -------

    public function test_handle_inbound_seeds_the_just_arrived_message_into_variables_for_condition_node_to_read(): void
    {
        $this->configuredSender();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.5']]])]);

        $flow = $this->flow([
            'start_node_id' => 'check',
            'nodes' => [
                'check' => [
                    'type' => 'condition',
                    'variable' => 'message.text',
                    'operator' => 'contains',
                    'value' => 'urgent',
                    'next_true' => 'on_urgent',
                    'next_false' => 'on_calm',
                ],
                'on_urgent' => ['type' => 'send_message', 'body' => 'Escalating now.', 'next' => null],
                'on_calm' => ['type' => 'send_message', 'body' => 'Noted, thanks.', 'next' => null],
            ],
        ], triggerType: 'inbound_message');

        // No variables seeded by hand this time -- handleInbound() itself
        // is responsible for putting the inbound event's own text where
        // ConditionNode can see it, with no session pre-existing yet.
        (new FlowEngine)->handleInbound($this->inboundEvent('917000000012', 'This is urgent, please call me back'));

        Http::assertSent(fn (Request $request) => $request->data()['text']['body'] === 'Escalating now.');
        $this->assertSame('completed', WhatsappFlowSession::sole()->status);
    }

    // -- Wall-clock cap is per-run, not per-session-lifetime (review finding 1) --

    public function test_resuming_a_session_long_after_a_real_delay_still_advances_instead_of_failing(): void
    {
        $this->configuredSender();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.6']]])]);

        $flow = $this->flow([
            'start_node_id' => 'after_wait',
            'limits' => ['max_execution_seconds' => 5],
            'nodes' => [
                'after_wait' => ['type' => 'send_message', 'body' => 'Thanks for waiting!', 'next' => null],
            ],
        ]);
        // Represents exactly what a real DelayNode leaves behind once its
        // wait has elapsed: a session parked on the post-delay node, whose
        // started_at (its lifetime clock, not this run's clock) is long in
        // the past -- here, two hours, comfortably past the 5-second
        // max_execution_seconds this flow configures for a single run.
        $session = $this->sessionAt($flow, '917000000013', 'after_wait');
        $session->update(['started_at' => now()->subHours(2)]);

        (new FlowEngine)->resume($session->fresh());

        Http::assertSent(fn (Request $request) => $request->data()['text']['body'] === 'Thanks for waiting!');
        $this->assertSame('completed', $session->fresh()->status);
        $this->assertNull($session->fresh()->last_error);
    }

    // -- A node handler's exception fails the session cleanly (review finding 4) --

    public function test_a_node_handler_exception_fails_the_session_instead_of_leaving_it_stuck_active(): void
    {
        // Deliberately not configuring WhatsApp -- WhatsappSender throws a
        // RuntimeException when it isn't, which is exactly the kind of
        // handler failure that must not strand the session.
        Http::fake();

        $flow = $this->flow([
            'start_node_id' => 'greet',
            'nodes' => [
                'greet' => ['type' => 'send_message', 'body' => 'Hello!', 'next' => null],
            ],
        ]);
        $session = $this->sessionAt($flow, '917000000014', 'greet');

        (new FlowEngine)->handleInbound($this->inboundEvent('917000000014'));

        $session->refresh();
        $this->assertSame('failed', $session->status);
        $this->assertStringContainsString('WhatsApp sending is not configured', (string) $session->last_error);
        Http::assertNothingSent();
    }

    // -- MakeRequestNode refuses unsafe URLs (review finding 6) --------------

    /** @return iterable<string, array{string}> */
    public static function unsafeMakeRequestUrls(): iterable
    {
        yield 'cloud metadata address' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'loopback' => ['http://127.0.0.1/admin'];
        yield 'localhost by name' => ['http://localhost/admin'];
        yield 'private range' => ['http://10.0.0.5/internal'];
        yield 'non-http scheme' => ['file:///etc/passwd'];
    }

    #[DataProvider('unsafeMakeRequestUrls')]
    public function test_make_request_node_refuses_an_unsafe_url_and_fails_the_session(string $url): void
    {
        Http::fake();

        $flow = $this->flow([
            'start_node_id' => 'notify',
            'nodes' => [
                'notify' => ['type' => 'make_request', 'url' => $url, 'next' => null],
            ],
        ]);
        $session = $this->sessionAt($flow, '917000000015', 'notify');

        (new FlowEngine)->handleInbound($this->inboundEvent('917000000015'));

        Http::assertNothingSent();
        $this->assertSame('failed', $session->fresh()->status);
        $this->assertStringContainsString('unsafe or invalid URL', (string) $session->fresh()->last_error);
    }

    public function test_make_request_node_still_allows_an_ordinary_external_host(): void
    {
        Http::fake(['example.test/*' => Http::response(['ok' => true])]);

        $flow = $this->flow([
            'start_node_id' => 'notify',
            'nodes' => [
                'notify' => ['type' => 'make_request', 'url' => 'https://example.test/hooks/flow', 'next' => null],
            ],
        ]);
        $session = $this->sessionAt($flow, '917000000016', 'notify');

        (new FlowEngine)->handleInbound($this->inboundEvent('917000000016'));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://example.test/hooks/flow');
        $this->assertSame('completed', $session->fresh()->status);
    }
}
