<?php

namespace Tests\Feature;

use Anthropic\Messages\Tool as SdkTool;
use App\Jobs\AnswerAdminOnWhatsapp;
use App\Models\AdminAgentMessage;
use App\Models\AiSetting;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use App\Models\WhatsappFlow;
use App\Models\WhatsappFlowSession;
use App\Models\WhatsappSetting;
use App\Models\WhatsappWebhookEvent;
use App\Services\AdminAgent\AdminAgent;
use App\Services\AdminAgent\ToolRegistry;
use App\Services\Ai\ChatModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\FakeChatModel;
use Tests\TestCase;

/**
 * The assistant that answers an admin's WhatsApp in words rather than from a
 * menu.
 *
 * Two halves, tested separately because they fail separately: who the
 * assistant is allowed to answer (claimant, which is a permission question),
 * and what it does once it has been asked (answer, which is a conversation
 * question).
 */
class AdminAgentTest extends TestCase
{
    use RefreshDatabase;

    private FakeChatModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        WhatsappSetting::current()->update([
            'access_token' => 'EAAG-test-token',
            'phone_number_id' => '556677889900',
        ]);

        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.test']]], 200)]);

        $this->model = new FakeChatModel;
        $this->app->instance(ChatModel::class, $this->model);
    }

    private function keyed(array $overrides = []): AiSetting
    {
        $settings = AiSetting::current();
        $settings->update($overrides + ['api_key' => 'sk-ant-test', 'is_active' => true]);

        return $settings->fresh();
    }

    private function admin(string $phone = '7094126823'): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN, 'phone' => $phone]);
    }

    /**
     * An inbound event, unsaved.
     *
     * Unsaved on purpose: saving one fires WhatsappWebhookEventObserver,
     * which runs the whole engine. The claimant tests below are asking one
     * question -- would this message be claimed -- and answering it must not
     * also send messages. Use arrive() for the tests that do want the wiring.
     */
    private function event(string $text, string $waId = '917094126823', string $type = 'text', ?string $replyId = null): WhatsappWebhookEvent
    {
        return new WhatsappWebhookEvent([
            'object' => 'whatsapp_business_account',
            'field' => 'messages',
            'type' => WhatsappWebhookEvent::TYPE_MESSAGE,
            'dedupe_key' => hash('sha256', uniqid('', true)),
            'wa_id' => $waId,
            'message_type' => $type,
            'summary' => $text,
            // The stable id behind a tap, where FlowEngine looks for it.
            'payload' => $replyId === null
                ? []
                : ['interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => $replyId, 'title' => $text]]],
            'occurred_at' => now(),
            'received_at' => now(),
        ]);
    }

    /**
     * The same message, delivered for real: stored, observed, and pushed
     * through FlowEngine exactly as the live webhook does.
     */
    private function arrive(string $text, string $waId = '917094126823', string $type = 'text', ?string $replyId = null): void
    {
        $this->event($text, $waId, $type, $replyId)->save();
    }

    /** What the studio's number actually sent. */
    private function sent(): array
    {
        return collect(Http::recorded())
            ->map(fn (array $pair) => (string) data_get($pair[0]->data(), 'text.body'))
            ->filter()
            ->values()
            ->all();
    }

    /** The body of any interactive list the studio's number sent. */
    private function sentInteractive(): array
    {
        return collect(Http::recorded())
            ->map(fn (array $pair) => (string) data_get($pair[0]->data(), 'interactive.body.text'))
            ->filter()
            ->values()
            ->all();
    }

    // ——— Who it answers ———

    public function test_an_admin_asking_a_question_is_claimed(): void
    {
        $this->keyed();
        $admin = $this->admin();

        $this->assertSame($admin->id, AdminAgent::claimant($this->event('how much is outstanding?'))?->id);
    }

    public function test_an_employee_is_not_claimed(): void
    {
        $this->keyed();
        User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'phone' => '7094126823']);

        $this->assertNull(AdminAgent::claimant($this->event('how much is outstanding?')));
    }

    public function test_a_stranger_is_not_claimed(): void
    {
        $this->keyed();

        $this->assertNull(AdminAgent::claimant($this->event('how much is outstanding?', '919000009999')));
    }

    public function test_nothing_is_claimed_while_the_assistant_is_switched_off(): void
    {
        $this->keyed(['is_active' => false]);
        $this->admin();

        $this->assertNull(AdminAgent::claimant($this->event('how much is outstanding?')));
    }

    public function test_nothing_is_claimed_without_a_key(): void
    {
        AiSetting::current()->update(['is_active' => true]);
        $this->admin();

        $this->assertNull(AdminAgent::claimant($this->event('how much is outstanding?')));
    }

    public function test_the_word_menu_still_belongs_to_the_flow(): void
    {
        $this->keyed();
        $this->admin();

        // The escape hatch: whatever the assistant is doing, this word has to
        // reach the four figures the owner already trusts.
        $this->assertNull(AdminAgent::claimant($this->event('Menu')));
    }

    public function test_a_tap_on_a_list_row_is_left_to_the_flow(): void
    {
        $this->keyed();
        $this->admin();

        // A tap answers a menu this assistant did not send. Claiming it would
        // swallow the menu's own replies the day somebody switched this on.
        $this->assertNull(AdminAgent::claimant($this->event('Money', type: 'interactive')));
    }

    public function test_the_daily_ceiling_hands_the_thread_back_to_the_menu(): void
    {
        $admin = $this->admin();
        $this->keyed(['daily_answer_limit' => 2]);

        foreach (range(1, 2) as $i) {
            AdminAgentMessage::create([
                'wa_id' => '917094126823',
                'user_id' => $admin->id,
                'role' => AdminAgentMessage::ROLE_USER,
                'body' => 'question '.$i,
            ]);
        }

        $this->assertNull(AdminAgent::claimant($this->event('one more?')));
    }

    public function test_a_ceiling_of_zero_means_no_ceiling(): void
    {
        $admin = $this->admin();
        $this->keyed(['daily_answer_limit' => 0]);

        AdminAgentMessage::create([
            'wa_id' => '917094126823',
            'user_id' => $admin->id,
            'role' => AdminAgentMessage::ROLE_USER,
            'body' => 'question',
        ]);

        $this->assertSame($admin->id, AdminAgent::claimant($this->event('one more?'))?->id);
    }

    // ——— The hand-off from the flow engine ———

    public function test_an_admins_message_is_queued_and_any_open_menu_is_closed(): void
    {
        Queue::fake();
        $this->keyed();
        $admin = $this->admin();

        $flow = WhatsappFlow::create([
            'name' => 'Studio owner menu (WhatsApp)',
            'trigger_type' => 'keyword',
            'trigger_config' => ['keyword' => 'studio'],
            'is_active' => true,
            'graph' => ['start_node_id' => '1', 'nodes' => ['1' => ['type' => 'send_message', 'body' => 'hi', 'next' => null]]],
        ]);

        $session = WhatsappFlowSession::create([
            'flow_id' => $flow->id,
            'wa_id' => '917094126823',
            'current_node_id' => '1',
            'variables' => [],
            'status' => 'active',
            'iteration_count' => 0,
            'started_at' => now(),
        ]);

        $this->arrive('what did we collect this month?');

        Queue::assertPushed(AnswerAdminOnWhatsapp::class);
        $this->assertSame('completed', $session->fresh()->status);
    }

    public function test_the_flow_is_untouched_when_the_assistant_is_off(): void
    {
        Queue::fake();
        $this->keyed(['is_active' => false]);
        $this->admin();

        WhatsappFlow::create([
            'name' => 'Studio owner menu (WhatsApp)',
            'trigger_type' => 'keyword',
            'trigger_config' => ['keyword' => 'studio'],
            'is_active' => true,
            'graph' => ['start_node_id' => '1', 'nodes' => ['1' => ['type' => 'send_message', 'body' => 'the old menu', 'next' => null]]],
        ]);

        $this->arrive('studio');

        Queue::assertNothingPushed();
        $this->assertSame(['the old menu'], $this->sent());
    }

    // ——— What it does once asked ———

    public function test_a_question_is_answered_and_recorded(): void
    {
        $this->keyed();
        $admin = $this->admin();
        $this->model->willSay('Nothing is overdue.');

        app(AdminAgent::class)->answer($admin, '917094126823', 'anything overdue?');

        $this->assertSame(['Nothing is overdue.'], $this->sent());
        $this->assertSame(
            [['user', 'anything overdue?'], ['assistant', 'Nothing is overdue.']],
            AdminAgentMessage::orderBy('id')->get()->map(fn ($m) => [$m->role, $m->body])->all(),
        );
        $this->assertNotNull(AiSetting::current()->last_answered_at);
    }

    public function test_the_tool_it_asks_for_is_run_and_handed_back(): void
    {
        $this->keyed();
        $admin = $this->admin();
        $client = Client::factory()->create(['name' => 'Pothys Silks']);
        Invoice::factory()->create([
            'client_id' => $client->id,
            'total' => 50000,
            'status' => Invoice::STATUS_UNPAID,
            'due_date' => today()->subDays(10),
        ]);

        $this->model
            ->willCall([['name' => 'overdue_invoices']])
            ->willSay('Pothys Silks is ₹50,000 overdue, 10 days late.');

        app(AdminAgent::class)->answer($admin, '917094126823', 'who is late?');

        $results = $this->model->toolResults();
        $this->assertCount(1, $results);
        $this->assertFalse($results[0]['failed']);
        $this->assertStringContainsString('Pothys Silks', $results[0]['output']);
        $this->assertSame(['Pothys Silks is ₹50,000 overdue, 10 days late.'], $this->sent());
        $this->assertSame(
            ['overdue_invoices'],
            collect(AdminAgentMessage::where('role', 'assistant')->sole()->tool_calls)->pluck('name')->all(),
        );
    }

    public function test_several_tools_in_one_turn_come_back_in_one_message(): void
    {
        $this->keyed();
        $admin = $this->admin();

        $this->model
            ->willCall([['name' => 'money_summary'], ['name' => 'timesheet_gaps']])
            ->willSay('Both answered.');

        app(AdminAgent::class)->answer($admin, '917094126823', 'money and timesheets?');

        // One user message carrying both results, never one message each --
        // splitting them is what teaches the model to stop asking in parallel.
        $ran = collect($this->model->calls[1]['messages'])->filter(fn ($m) => isset($m['ran']));
        $this->assertCount(1, $ran);
        $this->assertCount(2, $ran->first()['ran']);
    }

    public function test_a_tool_that_fails_still_produces_an_answer(): void
    {
        $this->keyed();
        $admin = $this->admin();

        $this->model
            ->willCall([['name' => 'a_tool_that_does_not_exist']])
            ->willSay("I couldn't get that one.");

        app(AdminAgent::class)->answer($admin, '917094126823', 'something odd');

        $results = $this->model->toolResults();
        $this->assertTrue($results[0]['failed']);
        $this->assertSame(["I couldn't get that one."], $this->sent());
    }

    public function test_the_words_are_replayed_but_the_figures_are_not(): void
    {
        $this->keyed();
        $admin = $this->admin();

        $this->model
            ->willCall([['name' => 'money_summary']])
            ->willSay('Collected ₹1,20,000 so far.')
            ->willSay('Yes, that was this month.');

        app(AdminAgent::class)->answer($admin, '917094126823', 'how are we doing?');
        app(AdminAgent::class)->answer($admin, '917094126823', 'this month?');

        // The second question's conversation carries both turns of the first
        // exchange, and none of what the tool returned -- a figure replayed
        // tomorrow is a figure quoted wrongly.
        $conversation = $this->model->calls[2]['messages'];
        $this->assertCount(3, $conversation);
        $this->assertSame('how are we doing?', $conversation[0]['said']);
        $this->assertSame('Collected ₹1,20,000 so far.', $conversation[1]['turn']->text);
        $this->assertSame('this month?', $conversation[2]['said']);
        $this->assertSame([], collect($conversation)->filter(fn ($m) => isset($m['ran']))->all());
    }

    public function test_a_refusal_is_passed_on_rather_than_retried(): void
    {
        $this->keyed();
        $admin = $this->admin();
        $this->model->willRefuse('I can\'t help with that.');

        app(AdminAgent::class)->answer($admin, '917094126823', 'something off-limits');

        $this->assertSame(['I can\'t help with that.'], $this->sent());
        $this->assertCount(1, $this->model->calls);
    }

    public function test_a_model_that_says_nothing_still_gets_a_reply_out(): void
    {
        $this->keyed();
        $admin = $this->admin();
        $this->model->willSay('');

        app(AdminAgent::class)->answer($admin, '917094126823', 'hello?');

        // Silence on a phone is indistinguishable from the portal being
        // broken -- which was exactly the owner menu's bug.
        $this->assertCount(1, $this->sent());
        $this->assertStringContainsString('menu', $this->sent()[0]);
    }

    public function test_a_model_that_never_stops_asking_for_tools_is_cut_off(): void
    {
        $this->keyed();
        $admin = $this->admin();

        foreach (range(1, AdminAgent::MAX_STEPS) as $ignored) {
            $this->model->willCall([['name' => 'money_summary']]);
        }

        app(AdminAgent::class)->answer($admin, '917094126823', 'go round forever');

        $this->assertCount(AdminAgent::MAX_STEPS, $this->model->calls);
        $this->assertCount(1, $this->sent());
        $this->assertStringContainsString("couldn't finish", $this->sent()[0]);
    }

    public function test_the_assistant_is_told_the_date_and_who_it_is_speaking_to(): void
    {
        $this->keyed();
        $admin = $this->admin();
        $this->model->willSay('Noted.');

        app(AdminAgent::class)->answer($admin, '917094126823', 'hello');

        $system = $this->model->calls[0]['system'];
        $this->assertStringContainsString(now()->format('l j F Y'), $system);
        $this->assertStringContainsString($admin->name, $system);
        // The read-only boundary is stated, so it says so rather than
        // implying it has created something.
        $this->assertStringContainsString('change nothing', $system);
    }

    // ——— The settings screen ———

    public function test_an_admin_can_open_and_save_the_settings_screen(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('ai.edit'))->assertOk()->assertSee('WhatsApp Assistant');

        $this->actingAs($admin)->put(route('ai.update'), [
            'api_key' => 'gsk_pasted',
            'provider' => 'groq',
            'model' => '',
            'is_active' => '1',
            'daily_answer_limit' => 50,
        ])->assertRedirect(route('ai.edit'));

        $settings = AiSetting::current()->fresh();
        $this->assertSame('gsk_pasted', $settings->api_key);
        $this->assertTrue($settings->is_active);
        $this->assertSame(50, $settings->daily_answer_limit);
        // Blank model means whatever this provider's default is, rather than
        // an empty string sent to the API as a model id.
        $this->assertSame(AiSetting::DEFAULT_MODELS['groq'], $settings->modelName());
    }

    public function test_a_blank_key_keeps_the_one_already_on_file(): void
    {
        $admin = $this->admin();
        $this->keyed();

        // The field cannot show the key back, so an empty submit while
        // changing the model must not silently unkey the assistant.
        $this->actingAs($admin)->put(route('ai.update'), [
            'api_key' => '',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5',
            'is_active' => '1',
            'daily_answer_limit' => 100,
        ])->assertRedirect(route('ai.edit'));

        $settings = AiSetting::current()->fresh();
        $this->assertSame('sk-ant-test', $settings->api_key);
        $this->assertSame('claude-sonnet-5', $settings->model);
        $this->assertSame('anthropic', $settings->providerName());
    }

    public function test_an_unknown_provider_is_refused(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('ai.update'), [
            'provider' => 'some-other-shop',
            'model' => '',
            'daily_answer_limit' => 100,
        ])->assertSessionHasErrors('provider');
    }

    public function test_removing_the_key_also_switches_it_off(): void
    {
        $admin = $this->admin();
        $this->keyed();

        $this->actingAs($admin)->delete(route('ai.forget'))->assertRedirect(route('ai.edit'));

        $settings = AiSetting::current()->fresh();
        $this->assertNull($settings->api_key);
        $this->assertFalse($settings->is_active);
    }

    public function test_an_employee_cannot_reach_the_settings_screen(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($employee)->get(route('ai.edit'))->assertForbidden();
    }

    /**
     * The one thing about a tool definition that cannot be caught by reading
     * it: whether the SDK and then the API will take it.
     *
     * Specifically `properties`. A no-argument tool that sets it to an empty
     * PHP array sends `"properties": []`, and a JSON Schema whose properties
     * are a list rather than an object is rejected -- by the API, on the
     * first live question, having passed every other test here.
     */
    public function test_every_tool_definition_is_a_shape_the_api_accepts(): void
    {
        foreach (app(ToolRegistry::class)->definitions() as $definition) {
            $wire = json_decode(json_encode(SdkTool::with(
                inputSchema: $definition['inputSchema'],
                name: $definition['name'],
                description: $definition['description'],
            )), true);

            $this->assertSame('object', $wire['input_schema']['type'], $definition['name']);

            if (array_key_exists('properties', $wire['input_schema'])) {
                $properties = $wire['input_schema']['properties'];
                $this->assertNotSame([], $properties, $definition['name'].' sends an empty properties list');
                $this->assertNotSame(
                    array_keys($properties),
                    range(0, count($properties) - 1),
                    $definition['name'].' sends properties as a list, not an object',
                );
            }
        }
    }
    // ——— When the model mangles its own answer ———

    public function test_a_mangled_reply_is_replaced_by_the_figures_it_was_summarising(): void
    {
        $this->keyed();
        $admin = $this->admin();
        $client = Client::factory()->create(['name' => 'Janet Hospitals']);
        Invoice::factory()->create([
            'client_id' => $client->id,
            'total' => 40000,
            'status' => Invoice::STATUS_UNPAID,
            'due_date' => today()->subDays(40),
        ]);

        // Exactly what the free tier really sent once: the answer gives up
        // mid-word, with a zero-width space where it stopped.
        $this->model
            ->willCall([['name' => 'overdue_invoices']])
            ->willSay("2 overdue — total ₹40,000\n- Janet Hospitals — ₹7,500, 40 days late\n- Digital Harvest (Jan\u{200B} …\u{200B}...");

        app(AdminAgent::class)->answer($admin, '917094126823', 'anything overdue?');

        // The tools were written for the owner menu, so the fallback is a
        // plainer answer rather than an apology.
        $sent = $this->sent()[0];
        $this->assertStringContainsString('Janet Hospitals', $sent);
        $this->assertStringNotContainsString('…', $sent);
        $this->assertSame(0, preg_match('/[\x{200B}-\x{200D}\x{FEFF}]/u', $sent));
    }

    public function test_a_mangled_reply_with_nothing_looked_up_says_so(): void
    {
        $this->keyed();
        $admin = $this->admin();
        $this->model->willSay("Sure, I can\u{200B}…");

        app(AdminAgent::class)->answer($admin, '917094126823', 'hello');

        // Nothing to fall back to, so the invisible characters still go and
        // the owner is not handed half a sentence as if it were an answer.
        $this->assertSame(0, preg_match('/[\x{200B}-\x{200D}\x{FEFF}]/u', $this->sent()[0]));
    }

    public function test_invisible_characters_are_stripped_from_a_sound_reply_too(): void
    {
        $this->keyed();
        $admin = $this->admin();
        $this->model->willSay("Collected \u{200B}₹1,05,000 this month.   ");

        app(AdminAgent::class)->answer($admin, '917094126823', 'how are we doing?');

        // Invisible on the phone, and it breaks search and copy-paste for
        // whoever reads the thread later.
        $this->assertSame('Collected ₹1,05,000 this month.', $this->sent()[0]);
    }
    // ——— Reading anything ———

    public function test_it_can_query_any_part_of_the_portal(): void
    {
        $this->keyed();
        $admin = $this->admin();
        $sanjai = User::factory()->create(['name' => 'Sanjai', 'role' => User::ROLE_EMPLOYEE]);

        DB::table('timesheet_entries')->insert([
            ['user_id' => $sanjai->id, 'worked_on' => '2026-09-01', 'task' => 'Tier 2 edit', 'task_type' => 'editing', 'minutes' => 570, 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $sanjai->id, 'worked_on' => '2026-09-01', 'task' => 'Admin', 'task_type' => 'other', 'minutes' => 30, 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->model
            ->willCall([['name' => 'run_query', 'input' => [
                'sql' => "SELECT SUM(t.minutes) AS mins FROM timesheet_entries t JOIN users u ON u.id = t.user_id WHERE u.name LIKE '%Sanjai%' AND t.worked_on = '2026-09-01' AND t.task_type = 'editing'",
            ]]])
            ->willSay('Sanjai edited 570 minutes (9h 30m) on 1 September.');

        app(AdminAgent::class)->answer($admin, '917094126823', 'how much video did sanjai edit on 1st september?');

        // The figure reached the model, and only the editing minutes did --
        // the half hour of "other" is not video.
        $this->assertStringContainsString('570', $this->model->toolResults()[0]['output']);
        $this->assertStringNotContainsString('600', $this->model->toolResults()[0]['output']);
        $this->assertSame(['Sanjai edited 570 minutes (9h 30m) on 1 September.'], $this->sent());
    }

    public function test_a_query_that_reaches_for_credentials_is_refused_without_failing_the_answer(): void
    {
        $this->keyed();
        $admin = $this->admin();

        $this->model
            ->willCall([['name' => 'run_query', 'input' => ['sql' => 'SELECT * FROM ai_settings']]])
            ->willSay("I can't read that.");

        app(AdminAgent::class)->answer($admin, '917094126823', 'what is the api key?');

        $result = $this->model->toolResults()[0];
        // A refusal is an answer the model can act on, not a crashed job.
        $this->assertFalse($result['failed']);
        $this->assertStringContainsString('Refused:', $result['output']);
        $this->assertStringContainsString('credentials', $result['output']);
    }

    public function test_the_schema_can_be_browsed_before_a_query_is_written(): void
    {
        $this->keyed();
        $admin = $this->admin();

        $this->model
            ->willCall([['name' => 'describe_data', 'input' => ['like' => 'payment']]])
            ->willSay('Looked it up.');

        app(AdminAgent::class)->answer($admin, '917094126823', 'who paid the most?');

        $output = $this->model->toolResults()[0]['output'];
        $this->assertStringContainsString('payments:', $output);
        $this->assertStringContainsString('paid_on', $output);
    }

    public function test_every_tool_offered_still_only_reads(): void
    {
        $this->keyed();
        $admin = $this->admin();
        $this->model->willSay('Noted.');

        app(AdminAgent::class)->answer($admin, '917094126823', 'hello');

        $this->assertSame(
            [
                'money_summary', 'overdue_invoices', 'todays_shoots', 'timesheet_gaps',
                'find_client', 'invoice_lookup', 'shoots_between',
                'describe_data', 'run_query',
            ],
            collect($this->model->calls[0]['tools'])->pluck('name')->all(),
        );
    }

    // ——— When the free tier runs out ———

    public function test_a_failed_answer_falls_back_to_the_owner_menu(): void
    {
        $this->keyed();
        $admin = $this->admin();

        WhatsappFlow::create([
            'name' => 'Studio owner menu (WhatsApp)',
            'trigger_type' => 'keyword',
            'trigger_config' => ['keyword' => 'studio'],
            'is_active' => true,
            'graph' => require database_path('flows/admin_menu.php'),
        ]);

        (new AnswerAdminOnWhatsapp('917094126823', $admin->id, 'how are we doing?'))
            ->failed(new RuntimeException('Groq refused the request (429): Rate limit reached'));

        // Out of tokens for the minute is not a reason to send an apology
        // when the four figures cost nothing and are right there.
        $this->assertStringContainsString('what do you want to see?', implode("\n", $this->sentInteractive()));
    }

    public function test_the_menu_it_sends_leaves_a_session_so_the_tap_lands(): void
    {
        $this->keyed();
        $admin = $this->admin();

        WhatsappFlow::create([
            'name' => 'Studio owner menu (WhatsApp)',
            'trigger_type' => 'keyword',
            'trigger_config' => ['keyword' => 'studio'],
            'is_active' => true,
            'graph' => require database_path('flows/admin_menu.php'),
        ]);

        (new AnswerAdminOnWhatsapp('917094126823', $admin->id, 'how are we doing?'))->failed(new RuntimeException('nope'));

        // A menu with no session behind it is a menu whose taps go nowhere --
        // which was the original bug. staffMenuContinuation() needs this row.
        $session = WhatsappFlowSession::where('wa_id', '917094126823')->latest('id')->first();
        $this->assertNotNull($session);
        $this->assertSame('completed', $session->status);

        $this->arrive('Money', type: 'interactive', replyId: '1');
        $this->assertStringContainsString('Collected:', implode("\n", $this->sent()));
    }

    public function test_the_crew_menu_is_never_sent_to_the_owner_by_mistake(): void
    {
        $this->keyed();
        $admin = $this->admin();

        // Sorts first, and is a keyword flow, and is not the owner's.
        WhatsappFlow::create([
            'name' => 'Crew self-service',
            'trigger_type' => 'keyword',
            'trigger_config' => ['keyword' => 'hi'],
            'is_active' => true,
            'graph' => require database_path('flows/crew_menu.php'),
        ]);

        (new AnswerAdminOnWhatsapp('917094126823', $admin->id, 'how are we doing?'))->failed(new RuntimeException('nope'));

        // No owner menu exists, so it says so in words rather than handing
        // the owner a crew member's menu.
        $this->assertStringContainsString("couldn't get to that", implode("\n", $this->sent()));
    }

    public function test_words_are_sent_when_there_is_no_menu_at_all(): void
    {
        $this->keyed();
        $admin = $this->admin();

        (new AnswerAdminOnWhatsapp('917094126823', $admin->id, 'how are we doing?'))->failed(new RuntimeException('nope'));

        $this->assertStringContainsString("couldn't get to that", implode("\n", $this->sent()));
    }
}
