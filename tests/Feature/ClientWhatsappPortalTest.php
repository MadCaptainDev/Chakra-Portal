<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Shoot;
use App\Models\WhatsappFlow;
use App\Models\WhatsappSetting;
use App\Models\WhatsappWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClientWhatsappPortalTest extends TestCase
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
    private function postWebhook(array $payload): void
    {
        $body = json_encode($payload);

        $this->call(
            'POST',
            '/webhooks/whatsapp',
            [], [], [],
            $this->transformHeadersToServerVars([
                'CONTENT_TYPE' => 'application/json',
                'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, self::SECRET),
            ]),
            $body,
        )->assertOk();
    }

    /** @return array<string, mixed> */
    private function messagePayload(string $body, string $waId = '919812345678'): array
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
                        'contacts' => [['profile' => ['name' => 'Priya'], 'wa_id' => $waId]],
                        'messages' => [[
                            'from' => $waId,
                            'id' => 'wamid.'.md5($body.microtime(true)),
                            'timestamp' => '1755259200',
                            'type' => 'text',
                            'text' => ['body' => $body],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    /**
     * The tap equivalent of messagePayload() -- an interactive list_reply,
     * same shape Meta sends when someone taps a row in a Send List node's
     * menu. $rowId is the row id (e.g. "1"), $title its visible label.
     *
     * @return array<string, mixed>
     */
    private function listReplyPayload(string $rowId, string $title, string $waId = '919812345678'): array
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
                        'contacts' => [['profile' => ['name' => 'Priya'], 'wa_id' => $waId]],
                        'messages' => [[
                            'from' => $waId,
                            'id' => 'wamid.'.md5($rowId.$title.microtime(true)),
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

    private function activatedClient(string $phone = '9812345678'): Client
    {
        return Client::create([
            'name' => 'SVA Silks',
            'phone' => $phone,
            'whatsapp_portal_enabled' => true,
        ]);
    }

    private function seedPortalFlow(): WhatsappFlow
    {
        $this->artisan('app:seed-client-portal-automation', ['--force' => true]);

        $flow = WhatsappFlow::where('trigger_type', 'client_portal')->firstOrFail();
        $flow->update(['is_active' => true]);

        return $flow->fresh();
    }

    public function test_an_activated_client_saying_hi_gets_the_tappable_menu_from_the_automation(): void
    {
        $this->configured();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);

        $this->activatedClient();
        $this->seedPortalFlow();

        $this->postWebhook($this->messagePayload('Hi'));

        Http::assertSent(function (Request $request) {
            $data = $request->data();
            $titles = array_column($data['interactive']['action']['sections'][0]['rows'] ?? [], 'title');

            return ($data['type'] ?? null) === 'interactive'
                && ($data['interactive']['type'] ?? null) === 'list'
                && ($data['interactive']['action']['button'] ?? null) === 'Select Option'
                && str_contains($data['interactive']['body']['text'] ?? '', 'SVA Silks')
                && $titles === ['Invoices', 'Monthly report', 'Upcoming shoots', 'Talk to the studio'];
        });
    }

    public function test_tapping_the_invoices_row_lists_invoices(): void
    {
        $this->configured();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1b']]])]);

        $client = $this->activatedClient();

        Invoice::factory()->create([
            'client_id' => $client->id,
            'invoice_number' => 'CP-0100',
            'total' => 20000,
            'status' => Invoice::STATUS_UNPAID,
        ]);

        $this->seedPortalFlow();

        $this->postWebhook($this->listReplyPayload('1', 'Invoices'));

        Http::assertSent(fn (Request $request) => str_contains($request->data()['text']['body'] ?? '', 'CP-0100'));
    }

    public function test_tapping_upcoming_shoots_lists_shoots(): void
    {
        $this->configured();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1c']]])]);

        $client = $this->activatedClient();

        Shoot::create([
            'client_id' => $client->id,
            'title' => 'Tapped shoot',
            'starts_at' => now()->addDays(2),
            'status' => Shoot::STATUS_CONFIRMED,
        ]);

        $this->seedPortalFlow();

        $this->postWebhook($this->listReplyPayload('3', 'Upcoming shoots'));

        Http::assertSent(fn (Request $request) => str_contains($request->data()['text']['body'] ?? '', 'Tapped shoot'));
    }

    /**
     * The single assertion that justifies giving list rows numeric ids:
     * a tap and the equivalent typed number reach the same condition node,
     * because FlowEngine seeds message.choice from whichever one happened
     * (see FlowEngine::recordInboundMessage()).
     */
    public function test_a_typed_number_and_the_equivalent_tap_reach_the_same_place(): void
    {
        $this->configured();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1d']]])]);

        $client = $this->activatedClient();

        Invoice::factory()->create([
            'client_id' => $client->id,
            'invoice_number' => 'CP-0101',
            'total' => 5000,
            'status' => Invoice::STATUS_UNPAID,
        ]);

        $this->seedPortalFlow();

        $this->postWebhook($this->messagePayload('1'));
        Http::assertSent(fn (Request $request) => str_contains($request->data()['text']['body'] ?? '', 'CP-0101'));

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1e']]])]);
        $this->postWebhook($this->listReplyPayload('1', 'Invoices'));
        Http::assertSent(fn (Request $request) => str_contains($request->data()['text']['body'] ?? '', 'CP-0101'));
    }

    public function test_an_unrecognised_message_re_sends_the_tappable_menu(): void
    {
        $this->configured();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1f']]])]);

        $this->activatedClient();
        $this->seedPortalFlow();

        $this->postWebhook($this->messagePayload('gibberish'));

        Http::assertSent(function (Request $request) {
            $data = $request->data();

            return ($data['interactive']['type'] ?? null) === 'list'
                && str_contains($data['interactive']['body']['text'] ?? '', "didn't catch that");
        });
    }

    public function test_replying_with_1_lists_invoices(): void
    {
        $this->configured();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT2']]])]);

        $client = $this->activatedClient();

        Invoice::factory()->create([
            'client_id' => $client->id,
            'invoice_number' => 'CP-0099',
            'total' => 15000,
            'status' => Invoice::STATUS_UNPAID,
        ]);

        $this->seedPortalFlow();

        $this->postWebhook($this->messagePayload('1'));

        Http::assertSent(fn (Request $request) => str_contains($request->data()['text']['body'] ?? '', 'CP-0099'));
    }

    public function test_replying_with_3_lists_upcoming_shoots(): void
    {
        $this->configured();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT3']]])]);

        $client = $this->activatedClient();

        Shoot::create([
            'client_id' => $client->id,
            'title' => 'Warehouse shoot',
            'starts_at' => now()->addDays(4),
            'status' => Shoot::STATUS_CONFIRMED,
        ]);

        $this->seedPortalFlow();

        $this->postWebhook($this->messagePayload('3'));

        Http::assertSent(fn (Request $request) => str_contains($request->data()['text']['body'] ?? '', 'Warehouse shoot'));
    }

    public function test_a_non_activated_number_still_runs_the_generic_flow(): void
    {
        $this->configured();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT4']]])]);

        Client::create(['name' => 'Quiet Client', 'phone' => '9812345678', 'whatsapp_portal_enabled' => false]);

        $this->seedPortalFlow();

        WhatsappFlow::create([
            'name' => 'Catch-all',
            'trigger_type' => 'inbound_message',
            'graph' => [
                'start_node_id' => 'reply',
                'nodes' => [
                    'reply' => ['type' => 'send_message', 'body' => 'Studio fallback', 'next' => null],
                ],
            ],
            'is_active' => true,
        ]);

        $this->postWebhook($this->messagePayload('hello'));

        Http::assertSent(fn (Request $request) => ($request->data()['text']['body'] ?? '') === 'Studio fallback');
    }

    public function test_outgoing_portal_replies_show_in_the_crm_log(): void
    {
        $this->configured();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT5']]])]);

        $this->activatedClient();
        $this->seedPortalFlow();

        $this->postWebhook($this->messagePayload('hello'));

        $this->assertTrue(
            WhatsappWebhookEvent::where('type', WhatsappWebhookEvent::TYPE_OUTGOING)
                ->where('wa_id', '919812345678')
                ->exists()
        );
    }
}
