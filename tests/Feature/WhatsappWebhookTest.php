<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsappSetting;
use App\Models\WhatsappWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The webhook is the one route in this app that a stranger is *supposed* to
 * reach, so the tests are mostly about what it refuses.
 */
class WhatsappWebhookTest extends TestCase
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
    private function messagePayload(string $wamid = 'wamid.HELLO'): array
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
                            'text' => ['body' => 'Can we move the shoot to Friday?'],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    public function test_meta_can_complete_the_subscription_handshake(): void
    {
        $settings = WhatsappSetting::current();

        $response = $this->get('/webhooks/whatsapp?hub.mode=subscribe'
            .'&hub.verify_token='.$settings->verify_token
            .'&hub.challenge=1158201444');

        // The body must be the bare challenge -- not JSON, not wrapped. Meta
        // compares it byte for byte.
        $response->assertOk();
        $this->assertSame('1158201444', $response->getContent());

        $this->assertNotNull($settings->fresh()->verified_at);
    }

    public function test_the_handshake_is_refused_with_the_wrong_verify_token(): void
    {
        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=guessed&hub.challenge=123')
            ->assertForbidden();

        $this->assertNull(WhatsappSetting::current()->verified_at);
    }

    public function test_an_incoming_message_is_stored(): void
    {
        $this->configured();

        $this->postWebhook($this->messagePayload())->assertOk();

        $event = WhatsappWebhookEvent::sole();

        $this->assertSame(WhatsappWebhookEvent::TYPE_MESSAGE, $event->type);
        $this->assertSame('wamid.HELLO', $event->external_id);
        $this->assertSame('919812345678', $event->wa_id);
        $this->assertSame('Ravi', $event->contact_name);
        $this->assertSame('Can we move the shoot to Friday?', $event->summary);
        $this->assertNotNull(WhatsappSetting::current()->last_event_at);
    }

    /**
     * A tap on a Send List row: describeMessage() (App\Models\
     * WhatsappWebhookEvent) already returns the row's *title* as the
     * summary -- the id is never lifted into its own column, it only
     * survives inside the raw `payload` JSON. FlowEngine::recordInboundMessage()
     * depends on exactly this pairing to seed message.reply_id/message.choice,
     * so this guards the contract between the two rather than re-testing
     * either in isolation.
     *
     * @return array<string, mixed>
     */
    private function listReplyPayload(string $rowId, string $title): array
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
                            'id' => 'wamid.LISTREPLY',
                            'timestamp' => '1755259200',
                            'type' => 'interactive',
                            'interactive' => [
                                'type' => 'list_reply',
                                'list_reply' => ['id' => $rowId, 'title' => $title],
                            ],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    public function test_an_interactive_list_reply_is_ingested_with_its_title_as_the_summary_and_its_id_kept_in_the_payload(): void
    {
        $this->configured();

        $this->postWebhook($this->listReplyPayload('1', 'Invoices'))->assertOk();

        $event = WhatsappWebhookEvent::sole();

        $this->assertSame('interactive', $event->message_type);
        $this->assertSame('Invoices', $event->summary);
        $this->assertSame('1', $event->payload['interactive']['list_reply']['id']);
    }

    public function test_a_delivery_status_is_stored_separately_from_the_message(): void
    {
        $this->configured();

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '102290129340398',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'statuses' => [
                            ['id' => 'wamid.SENT', 'status' => 'sent', 'timestamp' => '1755259200', 'recipient_id' => '919812345678'],
                            ['id' => 'wamid.SENT', 'status' => 'delivered', 'timestamp' => '1755259260', 'recipient_id' => '919812345678'],
                        ],
                    ],
                ]],
            ]],
        ];

        $this->postWebhook($payload)->assertOk();

        // Same message id, two genuinely different events -- the dedupe key has
        // to keep them apart or the delivery timeline collapses to one row.
        $this->assertSame(2, WhatsappWebhookEvent::where('type', WhatsappWebhookEvent::TYPE_STATUS)->count());
        $this->assertSame(['sent', 'delivered'], WhatsappWebhookEvent::orderBy('id')->pluck('status')->all());
    }

    public function test_a_redelivered_event_does_not_become_a_second_row(): void
    {
        $this->configured();

        // Meta resends anything it does not get a prompt 200 for.
        $this->postWebhook($this->messagePayload())->assertOk();
        $this->postWebhook($this->messagePayload())->assertOk();

        $this->assertSame(1, WhatsappWebhookEvent::count());
    }

    public function test_an_unsigned_post_is_refused(): void
    {
        $this->configured();

        $this->postWebhook($this->messagePayload(), secret: null)->assertForbidden();

        $this->assertSame(0, WhatsappWebhookEvent::count());
    }

    public function test_a_post_signed_with_the_wrong_secret_is_refused(): void
    {
        $this->configured();

        $this->postWebhook($this->messagePayload(), secret: 'not-the-app-secret')->assertForbidden();

        $this->assertSame(0, WhatsappWebhookEvent::count());
    }

    public function test_nothing_is_accepted_until_an_app_secret_is_configured(): void
    {
        // Fails closed: with no secret there is no way to tell Meta from
        // anybody else who has learned the URL.
        $this->postWebhook($this->messagePayload())->assertForbidden();

        $this->assertSame(0, WhatsappWebhookEvent::count());
    }

    public function test_an_unrecognised_change_is_still_recorded(): void
    {
        $this->configured();

        $this->postWebhook([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '1',
                'changes' => [[
                    'field' => 'account_update',
                    'value' => ['event' => 'PARTNER_ADDED'],
                ]],
            ]],
        ])->assertOk();

        $this->assertSame(WhatsappWebhookEvent::TYPE_OTHER, WhatsappWebhookEvent::sole()->type);
    }

    public function test_the_admin_screen_shows_the_callback_url_and_verify_token(): void
    {
        $settings = WhatsappSetting::current();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->get(route('whatsapp.edit'))
            ->assertOk()
            ->assertSee($settings->callbackUrl())
            ->assertSee($settings->verify_token);
    }

    public function test_an_employee_cannot_reach_the_admin_screen(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_EMPLOYEE]))
            ->get(route('whatsapp.edit'))
            ->assertForbidden();
    }

    public function test_rotating_the_token_invalidates_the_old_one(): void
    {
        $settings = $this->configured();
        $old = $settings->verify_token;

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->post(route('whatsapp.rotate'))
            ->assertRedirect(route('whatsapp.edit'));

        $this->assertNotSame($old, WhatsappSetting::current()->verify_token);

        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token='.$old.'&hub.challenge=1')
            ->assertForbidden();
    }

    public function test_saving_the_form_blank_keeps_the_existing_app_secret(): void
    {
        $this->configured();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->put(route('whatsapp.update'), ['app_secret' => '', 'phone_number_id' => '999'])
            ->assertRedirect(route('whatsapp.edit'));

        $settings = WhatsappSetting::current();

        $this->assertSame('999', $settings->phone_number_id);
        $this->assertSame(self::SECRET, $settings->app_secret);
    }
}
