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
use Illuminate\Support\Facades\Cache;
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
    private function messagePayload(string $wamid = 'wamid.HELLO', string $body = 'Can we move the shoot to Friday?', string $waId = '919812345678', ?int $timestamp = null): array
    {
        // An hour ago by default: inside the 24-hour window, so a free-text
        // reply is allowed. Pass an older timestamp to close it.
        $timestamp ??= now()->subHour()->timestamp;

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
                            'timestamp' => (string) $timestamp,
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
            // The fixture's inbound message carries a WhatsApp profile name
            // ("Ravi") -- see test_an_inbound_message_auto_names_the_contact()
            // below for why that, not the raw number, is what shows here.
            ->assertSee('Ravi');
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

    // -- 24-hour window -------------------------------------------------------

    public function test_a_reply_outside_the_24_hour_window_is_refused_before_meta_is_called(): void
    {
        $this->configuredWebhook();
        $this->postWebhook($this->messagePayload(timestamp: now()->subDays(2)->timestamp))->assertOk();
        $conversation = WhatsappConversation::sole();
        $this->configuredSender();
        Http::fake();

        $this->actingAs($this->employee(['view', 'edit']))
            ->postJson(route('whatsapp-crm.inbox.reply', $conversation), ['body' => 'Still there?'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');

        // Meta would have accepted the call and failed the message later
        // (131047) -- the whole point is that it is never made.
        Http::assertNothingSent();
    }

    public function test_show_offers_a_template_once_the_window_has_closed(): void
    {
        $this->configuredWebhook();
        $this->postWebhook($this->messagePayload(timestamp: now()->subDays(2)->timestamp))->assertOk();

        $this->actingAs($this->employee(['view', 'edit']))
            ->get(route('whatsapp-crm.inbox.show', WhatsappConversation::sole()))
            ->assertOk()
            ->assertSee('Only a template can be sent now');
    }

    public function test_a_json_reply_returns_the_new_message_for_the_thread(): void
    {
        $conversation = $this->conversationWithOneMessage();
        $this->configuredSender();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]], 200)]);

        $this->actingAs($this->employee(['view', 'edit']))
            ->postJson(route('whatsapp-crm.inbox.reply', $conversation), ['body' => 'On our way.'])
            ->assertOk()
            ->assertJsonPath('message.summary', 'On our way.')
            ->assertJsonPath('message.status', 'sent');
    }

    // -- delivery status ------------------------------------------------------

    /** @return array<string, mixed> */
    private function statusPayload(string $wamid, string $status, array $errors = []): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '102290129340398',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'statuses' => [array_filter([
                            'id' => $wamid,
                            'status' => $status,
                            'timestamp' => (string) now()->timestamp,
                            'recipient_id' => '919812345678',
                            'errors' => $errors ?: null,
                        ])],
                    ],
                ]],
            ]],
        ];
    }

    public function test_polling_reports_read_and_failed_statuses_of_sent_messages(): void
    {
        $conversation = $this->conversationWithOneMessage();
        $this->configuredSender();
        Http::fakeSequence('graph.facebook.com/*')
            ->push(['messages' => [['id' => 'wamid.OUT1']]], 200)
            ->push(['messages' => [['id' => 'wamid.OUT2']]], 200);

        $user = $this->employee(['view', 'edit']);
        $this->actingAs($user)->post(route('whatsapp-crm.inbox.reply', $conversation), ['body' => 'One']);
        $this->actingAs($user)->post(route('whatsapp-crm.inbox.reply', $conversation), ['body' => 'Two']);

        $this->postWebhook($this->statusPayload('wamid.OUT1', 'delivered'))->assertOk();
        $this->postWebhook($this->statusPayload('wamid.OUT1', 'read'))->assertOk();
        $this->postWebhook($this->statusPayload('wamid.OUT2', 'failed', [['code' => 131047, 'title' => 'Re-engagement message']]))->assertOk();

        $response = $this->actingAs($user)->getJson(route('whatsapp-crm.inbox.messages', $conversation))->assertOk();

        $byBody = collect($response->json('messages'))->keyBy('summary');
        $this->assertSame('read', $byBody['One']['status']);
        $this->assertSame('failed', $byBody['Two']['status']);
        $this->assertStringContainsString('24 hours', $byBody['Two']['error']);
        $this->assertSame('failed', $response->json('statuses.'.$byBody['Two']['id'].'.status'));
        $this->assertTrue($response->json('window_open'));
    }

    // -- templates ------------------------------------------------------------

    private function fakeTemplates(): void
    {
        Cache::put('whatsapp.templates', [[
            'name' => 'invoice_ready_v2',
            'status' => 'APPROVED',
            'language' => 'en_US',
            'category' => 'UTILITY',
            'components' => [
                ['type' => 'BODY', 'text' => 'Hi {{1}}, your invoice {{2}} is ready.'],
                ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'text' => 'View', 'url' => 'https://example.test/i/{{1}}']]],
            ],
        ]], now()->addHour());
    }

    public function test_an_approved_template_can_be_sent_from_the_thread(): void
    {
        $this->configuredWebhook();
        $this->postWebhook($this->messagePayload(timestamp: now()->subDays(3)->timestamp))->assertOk();
        $conversation = WhatsappConversation::sole();
        $this->configuredSender();
        $this->fakeTemplates();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TPL']]], 200)]);

        $this->actingAs($this->employee(['view', 'edit']))
            ->postJson(route('whatsapp-crm.inbox.template', $conversation), [
                'template' => 'invoice_ready_v2',
                'params' => ['Ravi', 'INV-12'],
                'button_param' => 'abc123',
            ])
            ->assertOk()
            ->assertJsonPath('message.kind', 'template');

        Http::assertSent(fn (HttpRequest $request) => $request->data()['template']['name'] === 'invoice_ready_v2'
            && $request->data()['template']['components'][0]['parameters'][1]['text'] === 'INV-12'
            && $request->data()['template']['components'][1]['parameters'][0]['text'] === 'abc123');
    }

    public function test_a_template_with_a_missing_blank_or_unknown_name_is_refused(): void
    {
        $conversation = $this->conversationWithOneMessage();
        $this->configuredSender();
        $this->fakeTemplates();
        Http::fake();
        $user = $this->employee(['view', 'edit']);

        $this->actingAs($user)
            ->postJson(route('whatsapp-crm.inbox.template', $conversation), ['template' => 'invoice_ready_v2', 'params' => ['Ravi'], 'button_param' => 'x'])
            ->assertStatus(422);

        $this->actingAs($user)
            ->postJson(route('whatsapp-crm.inbox.template', $conversation), ['template' => 'not_a_template', 'params' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('template');

        $this->actingAs($this->employee(['view']))
            ->postJson(route('whatsapp-crm.inbox.template', $conversation), ['template' => 'invoice_ready_v2'])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    // -- list filters ---------------------------------------------------------

    public function test_the_unread_filter_and_the_partial_list(): void
    {
        $this->configuredWebhook();
        $this->postWebhook($this->messagePayload('wamid.A', 'Unread one', '919811111111'))->assertOk();
        $this->postWebhook($this->messagePayload('wamid.B', 'Read one', '919822222222'))->assertOk();
        WhatsappConversation::where('wa_id', '919822222222')->update(['unread_count' => 0]);
        $user = $this->employee();

        $this->actingAs($user)->get(route('whatsapp-crm.inbox.index', ['filter' => 'unread']))
            ->assertOk()->assertSee('Unread one')->assertDontSee('Read one');

        $this->actingAs($user)->get(route('whatsapp-crm.inbox.index', ['partial' => 1]))
            ->assertOk()->assertSee('Unread one')->assertDontSee('Search name, number or message');
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

    // -- contact ----------------------------------------------------------------

    public function test_an_inbound_message_auto_names_the_contact_from_its_whatsapp_profile(): void
    {
        // messagePayload()'s fixture carries 'contacts' => [['profile' =>
        // ['name' => 'Ravi'], ...]], exactly what a real webhook post does.
        $conversation = $this->conversationWithOneMessage();

        $contact = $conversation->fresh()->contact;

        $this->assertNotNull($contact);
        $this->assertSame('Ravi', $contact->name);
        $this->assertSame('919812345678', $contact->phone);
    }

    public function test_a_second_message_never_overwrites_a_name_already_on_file(): void
    {
        $conversation = $this->conversationWithOneMessage();
        $conversation->fresh()->contact->update(['name' => 'Ravi Kumar (renamed by staff)']);

        $this->configuredWebhook();
        $this->postWebhook($this->messagePayload('wamid.SECOND', 'Any update?'))->assertOk();

        $this->assertSame('Ravi Kumar (renamed by staff)', $conversation->fresh()->contact->name);
    }

    public function test_updating_the_contact_name_requires_the_edit_ability(): void
    {
        $conversation = $this->conversationWithOneMessage();

        $this->actingAs($this->employee())
            ->post(route('whatsapp-crm.inbox.contact.update', $conversation), ['name' => 'Ravi Kumar'])
            ->assertForbidden();
    }

    public function test_naming_a_conversation_with_no_contact_yet_creates_one(): void
    {
        $this->configuredWebhook();
        // No 'contacts' key at all this time -- the number never carried a
        // WhatsApp profile name, the exact gap this form exists to close.
        $payload = $this->messagePayload();
        unset($payload['entry'][0]['changes'][0]['value']['contacts']);
        $this->postWebhook($payload)->assertOk();

        $conversation = WhatsappConversation::sole();
        $this->assertNull($conversation->contact_id);

        $this->actingAs($this->employee(['view', 'edit']))
            ->post(route('whatsapp-crm.inbox.contact.update', $conversation), ['name' => 'Ravi Kumar'])
            ->assertRedirect(route('whatsapp-crm.inbox.show', $conversation));

        $contact = $conversation->fresh()->contact;
        $this->assertNotNull($contact);
        $this->assertSame('Ravi Kumar', $contact->name);
        $this->assertSame('919812345678', $contact->phone);
    }

    public function test_renaming_an_already_linked_contact_updates_it_in_place(): void
    {
        $conversation = $this->conversationWithOneMessage(); // auto-named "Ravi"
        $originalContactId = $conversation->fresh()->contact->id;

        $this->actingAs($this->employee(['view', 'edit']))
            ->post(route('whatsapp-crm.inbox.contact.update', $conversation), ['name' => 'Ravi Shastri'])
            ->assertRedirect(route('whatsapp-crm.inbox.show', $conversation));

        $contact = $conversation->fresh()->contact;
        $this->assertSame($originalContactId, $contact->id);
        $this->assertSame('Ravi Shastri', $contact->name);
    }

    public function test_a_blank_name_is_rejected(): void
    {
        $conversation = $this->conversationWithOneMessage();

        $this->actingAs($this->employee(['view', 'edit']))
            ->post(route('whatsapp-crm.inbox.contact.update', $conversation), ['name' => ''])
            ->assertSessionHasErrors('name');
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
