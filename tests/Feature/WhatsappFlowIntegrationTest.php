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
