<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsappConversation;
use App\Models\WhatsappConversationNote;
use App\Models\WhatsappLabel;
use App\Models\WhatsappSetting;
use App\Models\WhatsappWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The 2-way inbox: index/show/messages (read side), reply/markRead/assign/
 * labels/notes (the mutations). Anything that needs real message history
 * goes through the webhook route (postWebhook()/messagePayload(), copied
 * from WhatsappWebhookTest the same way WhatsappConversationSyncTest copied
 * them) rather than inserting WhatsappWebhookEvent rows by hand -- that is
 * the only door production ever uses to create one.
 */
class WhatsappInboxTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'meta-app-secret-abc123';

    private function employee(array $abilities = ['view']): User
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $user->syncPermissions(['whatsapp-crm' => $abilities]);

        return $user->refresh();
    }

    private function configuredWebhook(): WhatsappSetting
    {
        $settings = WhatsappSetting::current();
        $settings->update(['app_secret' => self::SECRET]);

        return $settings->fresh();
    }

    /** @param array<string, mixed> $payload */
    private function postWebhook(array $payload): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload);

        return $this->call(
            'POST',
            '/webhooks/whatsapp',
            [], [], [],
            $this->transformHeadersToServerVars([
                'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, self::SECRET),
                'CONTENT_TYPE' => 'application/json',
            ]),
            $body
        );
    }

    /** @return array<string, mixed> */
    private function messagePayload(string $wamid = 'wamid.HELLO', string $body = 'Can we move the shoot to Friday?', string $waId = '919812345678'): array
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

    /** A conversation with one unread inbound message, created the real way. */
    private function conversationWithOneMessage(): WhatsappConversation
    {
        $this->configuredWebhook();
        $this->postWebhook($this->messagePayload())->assertOk();

        return WhatsappConversation::sole();
    }

    private function configuredSender(): void
    {
        WhatsappSetting::current()->update([
            'access_token' => 'EAAG-test-token',
            'phone_number_id' => '556677889900',
        ]);
    }

    // -- index --------------------------------------------------------------

    public function test_an_ungranted_employee_is_refused_the_index(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($employee)->get(route('whatsapp-crm.inbox.index'))->assertForbidden();
    }

    public function test_a_user_with_view_can_list_conversations(): void
    {
        $this->conversationWithOneMessage();

        $this->actingAs($this->employee())
            ->get(route('whatsapp-crm.inbox.index'))
            ->assertOk()
            ->assertSee('919812345678');
    }

    // -- show -----------------------------------------------------------------

    public function test_an_ungranted_employee_is_refused_the_show_page(): void
    {
        $conversation = $this->conversationWithOneMessage();
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($employee)->get(route('whatsapp-crm.inbox.show', $conversation))->assertForbidden();
    }

    public function test_show_renders_the_thread_and_marks_it_read(): void
    {
        $conversation = $this->conversationWithOneMessage();
        $this->assertSame(1, $conversation->unread_count);

        $this->actingAs($this->employee())
            ->get(route('whatsapp-crm.inbox.show', $conversation))
            ->assertOk()
            ->assertSee('Can we move the shoot to Friday?');

        $this->assertSame(0, $conversation->fresh()->unread_count);
    }

    /**
     * Every optional branch the show view has (an assignee, an existing
     * label plus one still available to add, an existing note) rendered at
     * once -- the cheapest way to catch a Blade error that only surfaces
     * once a conversation actually has all of this attached.
     */
    public function test_show_renders_with_an_assignee_labels_and_notes_present(): void
    {
        $conversation = $this->conversationWithOneMessage();
        $assignee = $this->employee(['view']);
        $conversation->update(['assigned_to_id' => $assignee->id]);

        $attached = WhatsappLabel::create(['name' => 'VIP']);
        $available = WhatsappLabel::create(['name' => 'Needs reply']);
        $conversation->labels()->attach($attached->id);

        $conversation->notes()->create(['author_id' => $assignee->id, 'body' => 'Called back at noon.']);

        $response = $this->actingAs($this->employee(['view', 'edit', 'create', 'delete']))
            ->get(route('whatsapp-crm.inbox.show', $conversation));

        $response->assertOk()
            ->assertSee($assignee->name)
            ->assertSee('VIP')
            ->assertSee('Needs reply')
            ->assertSee('Called back at noon.');
    }

    public function test_show_with_peek_does_not_mark_it_read(): void
    {
        $conversation = $this->conversationWithOneMessage();

        $this->actingAs($this->employee())
            ->get(route('whatsapp-crm.inbox.show', $conversation).'?peek=1')
            ->assertOk();

        $this->assertSame(1, $conversation->fresh()->unread_count);
    }

    // -- messages (polling) -----------------------------------------------

    public function test_messages_only_returns_events_after_the_given_id(): void
    {
        $this->configuredWebhook();
        $this->postWebhook($this->messagePayload('wamid.ONE', 'First message'))->assertOk();
        $conversation = WhatsappConversation::sole();
        $firstEventId = WhatsappWebhookEvent::sole()->id;

        $this->postWebhook($this->messagePayload('wamid.TWO', 'Second message'))->assertOk();

        $response = $this->actingAs($this->employee())
            ->getJson(route('whatsapp-crm.inbox.messages', $conversation).'?after='.$firstEventId);

        $response->assertOk();
        $messages = $response->json('messages');
        $this->assertCount(1, $messages);
        $this->assertSame('Second message', $messages[0]['summary']);
    }

    public function test_messages_with_no_after_returns_the_whole_thread(): void
    {
        $conversation = $this->conversationWithOneMessage();

        $response = $this->actingAs($this->employee())
            ->getJson(route('whatsapp-crm.inbox.messages', $conversation));

        $response->assertOk();
        $this->assertCount(1, $response->json('messages'));
    }

    // -- reply ------------------------------------------------------------

    public function test_replying_requires_the_edit_ability(): void
    {
        $conversation = $this->conversationWithOneMessage();

        $this->actingAs($this->employee())
            ->post(route('whatsapp-crm.inbox.reply', $conversation), ['body' => 'Running late'])
            ->assertForbidden();
    }

    public function test_reply_sends_via_whatsapp_sender_and_records_an_outgoing_event(): void
    {
        $conversation = $this->conversationWithOneMessage();
        $this->configuredSender();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200)]);

        $this->actingAs($this->employee(['view', 'edit']))
            ->post(route('whatsapp-crm.inbox.reply', $conversation), ['body' => 'We are on for Friday.'])
            ->assertRedirect(route('whatsapp-crm.inbox.show', $conversation));

        Http::assertSent(fn (HttpRequest $request) => $request->data()['text']['body'] === 'We are on for Friday.'
            && $request->data()['to'] === $conversation->wa_id);

        $this->assertTrue(
            WhatsappWebhookEvent::where('type', WhatsappWebhookEvent::TYPE_OUTGOING)
                ->where('summary', 'We are on for Friday.')
                ->exists()
        );
    }

    public function test_a_reply_meta_refuses_surfaces_as_a_validation_error_suggesting_a_template(): void
    {
        $conversation = $this->conversationWithOneMessage();
        $this->configuredSender();
        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'Message failed to send because more than 24 hours have passed since the customer last replied to this number.'],
        ], 400)]);

        $this->actingAs($this->employee(['view', 'edit']))
            ->post(route('whatsapp-crm.inbox.reply', $conversation), ['body' => 'Still there?'])
            ->assertSessionHasErrors('body');

        $this->assertSame(
            0,
            WhatsappWebhookEvent::where('type', WhatsappWebhookEvent::TYPE_OUTGOING)->count()
        );
    }

    // -- markRead -----------------------------------------------------------

    public function test_mark_read_zeroes_the_badge(): void
    {
        $conversation = $this->conversationWithOneMessage();
        $this->assertSame(1, WhatsappConversation::unreadCount());

        $this->actingAs($this->employee())
            ->postJson(route('whatsapp-crm.inbox.read', $conversation))
            ->assertOk();

        $this->assertSame(0, WhatsappConversation::unreadCount());
    }

    // -- assign ---------------------------------------------------------------

    public function test_assign_requires_the_edit_ability(): void
    {
        $conversation = $this->conversationWithOneMessage();
        $assignee = $this->employee(['view']);

        $this->actingAs($this->employee())
            ->post(route('whatsapp-crm.inbox.assign', $conversation), ['assigned_to_id' => $assignee->id])
            ->assertForbidden();
    }

    public function test_assign_sets_and_clears_the_assignee(): void
    {
        $conversation = $this->conversationWithOneMessage();
        $assignee = $this->employee(['view']);

        $this->actingAs($this->employee(['view', 'edit']))
            ->post(route('whatsapp-crm.inbox.assign', $conversation), ['assigned_to_id' => $assignee->id])
            ->assertRedirect(route('whatsapp-crm.inbox.show', $conversation));

        $this->assertSame($assignee->id, $conversation->fresh()->assigned_to_id);

        $this->actingAs($this->employee(['view', 'edit']))
            ->post(route('whatsapp-crm.inbox.assign', $conversation), ['assigned_to_id' => ''])
            ->assertRedirect(route('whatsapp-crm.inbox.show', $conversation));

        $this->assertNull($conversation->fresh()->assigned_to_id);
    }

    // -- labels ---------------------------------------------------------------

    public function test_attaching_and_detaching_a_label_requires_the_edit_ability(): void
    {
        $conversation = $this->conversationWithOneMessage();
        $label = WhatsappLabel::create(['name' => 'VIP']);

        $this->actingAs($this->employee())
            ->post(route('whatsapp-crm.inbox.labels.attach', [$conversation, $label]))
            ->assertForbidden();
    }

    public function test_a_label_can_be_attached_and_detached(): void
    {
        $conversation = $this->conversationWithOneMessage();
        $label = WhatsappLabel::create(['name' => 'VIP']);
        $user = $this->employee(['view', 'edit']);

        $this->actingAs($user)
            ->post(route('whatsapp-crm.inbox.labels.attach', [$conversation, $label]))
            ->assertRedirect(route('whatsapp-crm.inbox.show', $conversation));

        $this->assertTrue($conversation->labels()->whereKey($label->id)->exists());

        $this->actingAs($user)
            ->delete(route('whatsapp-crm.inbox.labels.detach', [$conversation, $label]))
            ->assertRedirect(route('whatsapp-crm.inbox.show', $conversation));

        $this->assertFalse($conversation->labels()->whereKey($label->id)->exists());
    }

    // -- notes ------------------------------------------------------------

    public function test_creating_a_note_requires_the_create_ability(): void
    {
        $conversation = $this->conversationWithOneMessage();

        $this->actingAs($this->employee())
            ->post(route('whatsapp-crm.inbox.notes.store', $conversation), ['body' => 'Called, no answer.'])
            ->assertForbidden();
    }

    public function test_a_note_can_be_created_and_deleted(): void
    {
        $conversation = $this->conversationWithOneMessage();
        $user = $this->employee(['view', 'create', 'delete']);

        $this->actingAs($user)
            ->post(route('whatsapp-crm.inbox.notes.store', $conversation), ['body' => 'Called, no answer.'])
            ->assertRedirect(route('whatsapp-crm.inbox.show', $conversation));

        $note = WhatsappConversationNote::sole();
        $this->assertSame('Called, no answer.', $note->body);
        $this->assertSame($user->id, $note->author_id);

        $this->actingAs($user)
            ->delete(route('whatsapp-crm.inbox.notes.destroy', [$conversation, $note]))
            ->assertRedirect(route('whatsapp-crm.inbox.show', $conversation));

        $this->assertSame(0, WhatsappConversationNote::count());
    }

    public function test_deleting_a_note_requires_the_delete_ability(): void
    {
        $conversation = $this->conversationWithOneMessage();
        $note = $conversation->notes()->create(['author_id' => $this->employee()->id, 'body' => 'x']);

        $this->actingAs($this->employee(['view', 'create']))
            ->delete(route('whatsapp-crm.inbox.notes.destroy', [$conversation, $note]))
            ->assertForbidden();
    }

    public function test_a_note_id_belonging_to_another_conversation_is_refused(): void
    {
        $conversationA = $this->conversationWithOneMessage();
        $this->postWebhook($this->messagePayload('wamid.OTHER', 'Hi there', '919999999999'))->assertOk();
        $conversationB = WhatsappConversation::where('wa_id', '919999999999')->sole();

        $note = $conversationB->notes()->create(['author_id' => $this->employee()->id, 'body' => 'belongs to B']);

        $this->actingAs($this->employee(['view', 'create', 'delete']))
            ->delete(route('whatsapp-crm.inbox.notes.destroy', [$conversationA, $note]))
            ->assertNotFound();
    }
}
