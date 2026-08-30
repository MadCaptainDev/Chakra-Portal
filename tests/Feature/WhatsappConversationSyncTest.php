<?php

namespace Tests\Feature;

use App\Models\WhatsappConversation;
use App\Models\WhatsappSetting;
use App\Models\WhatsappWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WhatsappConversation is never written by hand -- it is kept in step by
 * WhatsappWebhookEventObserver every time a message or send lands in
 * WhatsappWebhookEvent. These tests drive it the same way production does:
 * through the real webhook route, not by calling the observer directly.
 */
class WhatsappConversationSyncTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'meta-app-secret-abc123';

    private function configured(): WhatsappSetting
    {
        $settings = WhatsappSetting::current();
        $settings->update(['app_secret' => self::SECRET]);

        return $settings->fresh();
    }

    /** @param array<string, mixed> $payload */
    private function postWebhook(array $payload, ?string $secret = self::SECRET): \Illuminate\Testing\TestResponse
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
    private function messagePayload(string $wamid = 'wamid.HELLO', string $body = 'Can we move the shoot to Friday?'): array
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
                        'contacts' => [['profile' => ['name' => 'Ravi'], 'wa_id' => '919812345678']],
                        'messages' => [[
                            'from' => '919812345678',
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

    public function test_an_incoming_message_creates_a_conversation_with_one_unread(): void
    {
        $this->configured();

        $this->postWebhook($this->messagePayload())->assertOk();

        $conversation = WhatsappConversation::sole();

        $this->assertSame('919812345678', $conversation->wa_id);
        $this->assertSame(1, $conversation->unread_count);
        $this->assertSame('Can we move the shoot to Friday?', $conversation->last_message_summary);
        $this->assertNotNull($conversation->last_message_at);
    }

    public function test_a_second_incoming_message_from_the_same_number_increments_unread(): void
    {
        $this->configured();

        $this->postWebhook($this->messagePayload('wamid.ONE', 'First message'))->assertOk();
        $this->postWebhook($this->messagePayload('wamid.TWO', 'Second message'))->assertOk();

        $conversation = WhatsappConversation::sole();

        $this->assertSame(2, $conversation->unread_count);
        $this->assertSame('Second message', $conversation->last_message_summary);
    }

    public function test_an_outgoing_event_does_not_increment_unread(): void
    {
        $this->configured();

        WhatsappWebhookEvent::recordOutgoing(
            to: '919812345678',
            wamid: 'wamid.OUT',
            messageType: 'text',
            summary: 'We are on for Friday.',
            payload: ['type' => 'text', 'text' => ['body' => 'We are on for Friday.']],
        );

        $conversation = WhatsappConversation::sole();

        $this->assertSame(0, $conversation->unread_count);
        $this->assertSame('We are on for Friday.', $conversation->last_message_summary);
    }

    public function test_a_status_event_does_not_create_a_conversation(): void
    {
        $this->configured();

        $this->postWebhook([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '102290129340398',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'statuses' => [
                            ['id' => 'wamid.SENT', 'status' => 'sent', 'timestamp' => '1755259200', 'recipient_id' => '919812345678'],
                        ],
                    ],
                ]],
            ]],
        ])->assertOk();

        $this->assertSame(0, WhatsappConversation::count());
    }

    public function test_messages_returns_inbound_and_outgoing_events_in_occurred_at_order(): void
    {
        $this->configured();

        $this->postWebhook($this->messagePayload('wamid.ONE', 'First message'))->assertOk();

        WhatsappWebhookEvent::recordOutgoing(
            to: '919812345678',
            wamid: 'wamid.OUT',
            messageType: 'text',
            summary: 'We are on for Friday.',
            payload: ['type' => 'text', 'text' => ['body' => 'We are on for Friday.']],
        );

        $conversation = WhatsappConversation::sole();
        $messages = $conversation->messages();

        $this->assertCount(2, $messages);
        $this->assertSame(
            [WhatsappWebhookEvent::TYPE_MESSAGE, WhatsappWebhookEvent::TYPE_OUTGOING],
            $messages->pluck('type')->all()
        );
    }

    public function test_unread_count_sums_across_conversations(): void
    {
        $this->configured();

        $this->postWebhook($this->messagePayload('wamid.ONE'))->assertOk();

        $payload = $this->messagePayload('wamid.TWO');
        $payload['entry'][0]['changes'][0]['value']['contacts'][0]['wa_id'] = '919999999999';
        $payload['entry'][0]['changes'][0]['value']['messages'][0]['from'] = '919999999999';

        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($this->messagePayload('wamid.THREE'))->assertOk();

        $this->assertSame(3, WhatsappConversation::unreadCount());
    }
}
