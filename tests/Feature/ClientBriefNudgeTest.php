<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientBrief;
use App\Models\User;
use App\Models\WhatsappConversation;
use App\Models\WhatsappSetting;
use App\Models\WhatsappWebhookEvent;
use App\Services\ClientBriefNudge;
use App\Services\WhatsappSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClientBriefNudgeTest extends TestCase
{
    use RefreshDatabase;

    private function configured(): WhatsappSetting
    {
        $settings = WhatsappSetting::current();
        $settings->update([
            'access_token' => 'EAAG-test-token',
            'phone_number_id' => '556677889900',
        ]);

        return $settings->fresh();
    }

    private function staff(): User
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $user->syncPermissions(['clients' => ['view', 'edit']]);

        return $user->fresh();
    }

    public function test_nudge_sends_a_template_outside_the_service_window(): void
    {
        $this->configured();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.NUDGE1']]])]);

        $client = Client::create(['name' => 'SVA Silks', 'phone' => '7094126823']);
        $brief = ClientBrief::factory()->for($client)->create(['public_token' => 'abc123token']);

        $this->actingAs($this->staff())
            ->post(route('clients.brief.nudge', $client))
            ->assertRedirect()
            ->assertSessionHas('status');

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $body['type'] === 'template'
                && $body['template']['name'] === ClientBrief::WHATSAPP_TEMPLATE
                && $body['template']['components'][1]['parameters'][0]['text'] === 'abc123token';
        });

        $this->assertSame(WhatsappWebhookEvent::TYPE_OUTGOING, WhatsappWebhookEvent::sole()->type);
        $this->assertDatabaseHas('whatsapp_conversations', ['wa_id' => '917094126823']);
    }

    public function test_nudge_sends_free_text_inside_the_service_window(): void
    {
        $this->configured();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.NUDGE2']]])]);

        $client = Client::create(['name' => 'SVA Silks', 'phone' => '917094126823']);
        ClientBrief::factory()->for($client)->create(['public_token' => 'abc123token']);

        WhatsappWebhookEvent::create([
            'object' => 'whatsapp_business_account',
            'field' => 'messages',
            'type' => WhatsappWebhookEvent::TYPE_MESSAGE,
            'dedupe_key' => hash('sha256', 'inbound|1'),
            'external_id' => 'wamid.IN1',
            'wa_id' => '917094126823',
            'message_type' => 'text',
            'summary' => 'Hello studio',
            'payload' => [],
            'occurred_at' => now()->subHours(2),
            'received_at' => now()->subHours(2),
        ]);

        app(ClientBriefNudge::class)->send($client, User::factory()->create());

        Http::assertSent(fn (Request $request) => $request->data()['type'] === 'text'
            && str_contains($request->data()['text']['body'], 'brand brief'));
    }

    public function test_the_client_page_uses_the_connected_sender_not_wa_me(): void
    {
        $client = Client::create(['name' => 'SVA Silks', 'phone' => '7094126823']);

        $this->actingAs($this->staff())
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertSee(route('clients.brief.nudge', $client), false)
            ->assertDontSee('wa.me/', false);
    }
}
