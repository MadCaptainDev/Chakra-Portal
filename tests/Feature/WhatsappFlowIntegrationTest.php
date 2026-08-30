<?php

namespace Tests\Feature;

use App\Jobs\AdvanceWhatsappFlowSession;
use App\Models\WhatsappConversation;
use App\Models\WhatsappFlow;
use App\Models\WhatsappFlowSession;
use App\Models\WhatsappSetting;
use App\Models\WhatsappWebhookEvent;
use App\Services\WhatsappFlow\FlowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * WhatsappFlowIntegrationTest proves the *wiring*, not the engine.
 *
 * FlowEngineTest (tests/Unit) already exercises every node type and every
 * loop-protection edge directly against the engine. What is untested until
 * now is that a real inbound webhook POST -- signed, routed, ingested by
 * WhatsappWebhookEvent::ingest(), observed by WhatsappWebhookEventObserver
 * -- actually reaches FlowEngine::handleInbound() and advances a session.
 * So every test here goes in through the real /webhooks/whatsapp route,
 * exactly the way WhatsappConversationSyncTest (Task 6) does for the
 * conversation-sync half of the same observer.
 */
class WhatsappFlowIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'meta-app-secret-abc123';

    private function configured(): WhatsappSetting
    {
        $settings = WhatsappSetting::current();
        $settings->update([
            'app_secret' => self::SECRET,
            'access_token' => 'EAAG-test-token',
            'phone_number_id' => '556677889900',
        ]);

        return $settings->fresh();
    }

    /** @param array<string, mixed> $payload */
    private function postWebhook(array $payload, ?string $secret = self::SECRET): TestResponse
    {
        $body = json_encode($payload);

        $headers = $secret === null ? [] : [
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $secret),
        ];

        return $this->call(
            'POST',
            '/webhooks/whatsapp',
            [], [], [],
            $this->transformHeadersToServerVars($headers + ['CONTENT_TYPE' => 'application/json']),
            $body
        );
    }

    /** @return array<string, mixed> */
    private function messagePayload(string $wamid, string $body, string $waId = '919812345678'): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '102290129340398',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '919876543210', 'phone_number_id' => '1234'],
                        'contacts' => [['profile' => ['name' => 'Ravi'], 'wa_id' => $waId]],
                        'messages' => [[
                            'from' => $waId,
                            'id' => $wamid,
                            'timestamp' => '1755259200',
                            'type' => 'text',
                            'text' => ['body' => $body],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    public function test_a_webhook_post_matching_a_keyword_flow_starts_and_advances_a_session(): void
    {
        $this->configured();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.reply']]])]);

        $flow = WhatsappFlow::create([
            'name' => 'Greeting flow',
            'trigger_type' => 'keyword',
            'trigger_config' => ['keyword' => 'hello'],
            'graph' => [
                'start_node_id' => 'greet',
                'nodes' => [
                    'greet' => ['type' => 'send_message', 'body' => 'Hello from the studio!', 'next' => null],
                ],
            ],
            'is_active' => true,
        ]);

        $this->postWebhook($this->messagePayload('wamid.ONE', 'hello there, need some help'))->assertOk();

        Http::assertSent(fn (Request $request) => $request->data()['type'] === 'text'
            && $request->data()['text']['body'] === 'Hello from the studio!'
            && $request->data()['to'] === '919812345678');

        $session = WhatsappFlowSession::sole();
        $this->assertSame($flow->id, $session->flow_id);
        $this->assertSame('919812345678', $session->wa_id);
        $this->assertSame('completed', $session->status);
        $this->assertNull($session->current_node_id);
    }

    public function test_a_webhook_post_matching_a_delay_flow_leaves_the_session_active_and_queues_the_resume_job(): void
    {
        $this->configured();
        Queue::fake();

        WhatsappFlow::create([
            'name' => 'Delayed follow-up',
            'trigger_type' => 'inbound_message',
            'graph' => [
                'start_node_id' => 'wait',
                'nodes' => [
                    'wait' => ['type' => 'delay', 'seconds' => 3600, 'next' => 'after_wait'],
                    'after_wait' => ['type' => 'agent_transfer', 'user_id' => null, 'next' => null],
                ],
            ],
            'is_active' => true,
        ]);

        $this->postWebhook($this->messagePayload('wamid.TWO', 'is anyone there?'))->assertOk();

        $session = WhatsappFlowSession::sole();
        $this->assertSame('active', $session->status);
        $this->assertSame('after_wait', $session->current_node_id);

        Queue::assertPushed(
            AdvanceWhatsappFlowSession::class,
            fn (AdvanceWhatsappFlowSession $job) => $job->sessionId === $session->id
                && $job->delay !== null
        );
    }

    /**
     * Finding 2 (final whole-branch review): a session parked by DelayNode
     * still reads as `active`, which is exactly what lets
     * AdvanceWhatsappFlowSession resume it later -- but also what let ANY
     * inbound message from that number walk straight through the wait via
     * handleInbound()'s own "continue the active session" path, skipping
     * the delay entirely. DelayNode now stamps `expires_at`, and
     * handleInbound() must refuse to advance while it is still in the
     * future -- recording the early message into `variables` (so it is not
     * lost) but not calling run().
     */
    public function test_an_inbound_message_before_the_delay_elapses_does_not_advance_the_session_past_the_wait(): void
    {
        $this->configured();
        Queue::fake();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.reply']]])]);

        WhatsappFlow::create([
            'name' => 'Delayed follow-up',
            'trigger_type' => 'inbound_message',
            'graph' => [
                'start_node_id' => 'wait',
                'nodes' => [
                    'wait' => ['type' => 'delay', 'seconds' => 3600, 'next' => 'after_wait'],
                    'after_wait' => ['type' => 'send_message', 'body' => 'Delay done!', 'next' => null],
                ],
            ],
            'is_active' => true,
        ]);

        $this->postWebhook($this->messagePayload('wamid.EARLY1', 'start please'))->assertOk();

        $session = WhatsappFlowSession::sole();
        $this->assertSame('active', $session->status);
        $this->assertSame('after_wait', $session->current_node_id);
        $this->assertNotNull($session->expires_at);
        $this->assertTrue($session->expires_at->isFuture());

        // A second message from the same number, well before the delay's
        // hour is up -- and before AdvanceWhatsappFlowSession (faked, so it
        // never actually ran) would have fired.
        $this->postWebhook($this->messagePayload('wamid.EARLY2', 'are you there?'))->assertOk();

        $session->refresh();
        $this->assertSame('active', $session->status, 'The early message must not complete the flow.');
        $this->assertSame('after_wait', $session->current_node_id, 'The early message must not walk past the delay.');
        Http::assertNotSent(fn (Request $request) => ($request->data()['text']['body'] ?? null) === 'Delay done!');

        // Not lost, though -- still recorded, same as every other inbound
        // message handleInbound() sees.
        $this->assertSame('are you there?', $session->variables['message']['text'] ?? null);

        // Now the delay has genuinely elapsed. AdvanceWhatsappFlowSession
        // never re-enters handleInbound() -- it calls FlowEngine::resume()
        // directly -- so it is not subject to the expires_at guard at all;
        // it only ever fires once expires_at is naturally in the past.
        $session->update(['expires_at' => now()->subSecond()]);
        (new AdvanceWhatsappFlowSession($session->id))->handle(app(FlowEngine::class));

        $session->refresh();
        $this->assertSame('completed', $session->status);
        $this->assertNull($session->current_node_id);
        Http::assertSent(fn (Request $request) => ($request->data()['text']['body'] ?? null) === 'Delay done!');
    }

    public function test_a_webhook_post_still_returns_200_when_the_flow_engine_throws(): void
    {
        $this->configured();

        // A stand-in for "something FlowEngine::handleInbound() itself does
        // not anticipate" -- Task 8's engine already catches everything a
        // node handler can throw internally, so the only way to exercise
        // this observer's own defense-in-depth catch is to make resolving
        // the engine itself blow up.
        $this->app->bind(FlowEngine::class, function () {
            throw new RuntimeException('flow engine unavailable');
        });

        $response = $this->postWebhook($this->messagePayload('wamid.THREE', 'hello there'));

        // The mandatory 200-to-Meta contract holds even though a flow
        // dependency just blew up -- exactly what WhatsappWebhookTest
        // already proves for a parse failure in the controller's own
        // catch block.
        $response->assertOk();
        $this->assertSame('', $response->getContent());

        // And the conversation sync half of the same observer -- which
        // runs before the flow call -- is untouched by the failure.
        $conversation = WhatsappConversation::sole();
        $this->assertSame('919812345678', $conversation->wa_id);
        $this->assertSame(1, $conversation->unread_count);

        $this->assertSame(1, WhatsappWebhookEvent::count());
    }
}
