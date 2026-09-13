<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\EquipmentItem;
use App\Models\User;
use App\Models\WhatsappConversation;
use App\Models\WhatsappFlow;
use App\Models\WhatsappFlowSession;
use App\Models\WhatsappLabel;
use App\Models\WhatsappSetting;
use App\Models\WhatsappWebhookEvent;
use App\Services\WhatsappFlow\FlowEngine;
use App\Services\WhatsappFlow\ScheduledFlowRunner;
use App\Support\AdminPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The three trigger/portal additions that let flows serve the studio itself:
 * a label applied in the inbox, a clock, and the owner's own menu.
 */
class WhatsappAdminFlowsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Noon, so the clock-trigger tests below are about the clock trigger
         * and not about what time it is here. They name their times relative
         * to now ("an hour ago", "in two hours"), which between midnight and
         * 1am silently means yesterday and this morning -- ScheduledFlowRunner
         * compares against today()->setTime(), correctly refuses, and six
         * tests fail for an hour a day with nothing wrong with them.
         */
        $this->travelTo(today()->setTime(12, 0));

        WhatsappSetting::current()->update([
            'access_token' => 'EAAG-test-token',
            'phone_number_id' => '556677889900',
        ]);

        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.test']]], 200)]);
    }

    private function admin(string $phone = '9000000001'): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN, 'phone' => $phone]);
    }

    private function employee(string $phone = '9000000002'): User
    {
        return User::factory()->create(['role' => User::ROLE_EMPLOYEE, 'phone' => $phone]);
    }

    /** A one-node flow that just says something, so a trigger is observable. */
    private function flow(string $triggerType, array $config, string $name = 'Test flow'): WhatsappFlow
    {
        return WhatsappFlow::create([
            'name' => $name,
            'trigger_type' => $triggerType,
            'trigger_config' => $config,
            'is_active' => true,
            'graph' => [
                'start_node_id' => '1',
                'nodes' => ['1' => ['type' => 'send_message', 'body' => 'Hello from the flow.', 'next' => null]],
            ],
        ]);
    }

    private function conversation(string $waId = '919000000003'): WhatsappConversation
    {
        return WhatsappConversation::create(['wa_id' => $waId]);
    }

    // ——— Label applied ———

    public function test_labelling_a_conversation_in_the_inbox_starts_the_watching_flow(): void
    {
        $flow = $this->flow('label_applied', ['label' => 'Quote requested']);
        $conversation = $this->conversation();
        $label = WhatsappLabel::create(['name' => 'Quote requested']);

        app(FlowEngine::class)->handleLabelApplied($conversation, $label);

        $this->assertSame(1, WhatsappFlowSession::where('flow_id', $flow->id)->count());
    }

    public function test_the_label_name_is_available_to_the_flow(): void
    {
        $this->flow('label_applied', ['label' => 'Quote requested']);
        $conversation = $this->conversation();
        $label = WhatsappLabel::create(['name' => 'Quote requested']);

        app(FlowEngine::class)->handleLabelApplied($conversation, $label);

        $session = WhatsappFlowSession::latest('id')->first();
        $this->assertSame('Quote requested', data_get($session->variables, 'label.name'));
    }

    public function test_a_different_label_starts_nothing(): void
    {
        $this->flow('label_applied', ['label' => 'Quote requested']);
        $conversation = $this->conversation();
        $other = WhatsappLabel::create(['name' => 'Spam']);

        app(FlowEngine::class)->handleLabelApplied($conversation, $other);

        $this->assertSame(0, WhatsappFlowSession::count());
    }

    public function test_an_inactive_flow_is_not_started_by_its_label(): void
    {
        $this->flow('label_applied', ['label' => 'Quote requested'])->update(['is_active' => false]);
        $conversation = $this->conversation();
        $label = WhatsappLabel::create(['name' => 'Quote requested']);

        app(FlowEngine::class)->handleLabelApplied($conversation, $label);

        $this->assertSame(0, WhatsappFlowSession::count());
    }

    /**
     * Someone mid-conversation must not have a second flow cut in over the
     * top of the one they are already talking to.
     */
    public function test_a_number_already_in_a_session_is_left_alone(): void
    {
        $flow = $this->flow('label_applied', ['label' => 'Quote requested']);
        $conversation = $this->conversation();
        $label = WhatsappLabel::create(['name' => 'Quote requested']);

        WhatsappFlowSession::create([
            'flow_id' => $flow->id,
            'wa_id' => $conversation->wa_id,
            'current_node_id' => '1',
            'variables' => [],
            'status' => 'active',
            'iteration_count' => 0,
            'started_at' => now(),
        ]);

        app(FlowEngine::class)->handleLabelApplied($conversation, $label);

        $this->assertSame(1, WhatsappFlowSession::count());
    }

    /**
     * The wiring itself: labelling through the real inbox route reaches the
     * engine, not just handleLabelApplied() called directly.
     *
     * Deliberately one request. The re-tap guard (attachLabel only dispatches
     * for a label the conversation did not already carry) cannot be asserted
     * across two requests here: within one test the application instance is
     * reused, and the first request's afterResponse closure is replayed on the
     * second, which fabricates a second session no matter what the guard
     * decided. That is a harness artifact -- in production every request is
     * its own process -- but it makes a two-request assertion meaningless
     * rather than merely awkward.
     */
    public function test_labelling_through_the_inbox_route_reaches_the_engine(): void
    {
        $flow = $this->flow('label_applied', ['label' => 'Quote requested']);
        $conversation = $this->conversation();
        $label = WhatsappLabel::create(['name' => 'Quote requested']);

        $this->actingAs($this->admin())
            ->post(route('whatsapp-crm.inbox.labels.attach', [$conversation, $label]))
            ->assertRedirect();

        $this->assertSame(1, WhatsappFlowSession::where('flow_id', $flow->id)->count());
        $this->assertTrue($conversation->labels()->whereKey($label->id)->exists());
    }

    // ——— Admin portal ———

    public function test_an_admin_number_resolves_to_that_admin(): void
    {
        $admin = $this->admin('9786579573');
        $session = $this->sessionFor('919786579573');

        $this->assertSame($admin->id, AdminPortal::userForSession($session)?->id);
    }

    public function test_an_employee_is_refused_the_admin_portal(): void
    {
        $this->employee('9786579573');
        $session = $this->sessionFor('919786579573');

        $this->assertNull(AdminPortal::userForSession($session));
    }

    public function test_a_client_number_is_refused_the_admin_portal(): void
    {
        $client = Client::factory()->create();
        User::factory()->create(['role' => User::ROLE_CLIENT, 'client_id' => $client->id, 'phone' => '9786579573']);
        $session = $this->sessionFor('919786579573');

        $this->assertNull(AdminPortal::userForSession($session));
    }

    private function sessionFor(string $waId): WhatsappFlowSession
    {
        $flow = $this->flow('keyword', ['keyword' => 'studio']);

        return WhatsappFlowSession::create([
            'flow_id' => $flow->id,
            'wa_id' => $waId,
            'current_node_id' => '1',
            'variables' => [],
            'status' => 'active',
            'iteration_count' => 0,
            'started_at' => now(),
        ]);
    }

    // ——— Scheduled ———

    public function test_a_scheduled_flow_runs_once_its_time_has_passed(): void
    {
        $this->admin();
        $this->flow('scheduled', ['time' => now()->subHour()->format('H:i'), 'audience' => 'admins']);

        $this->assertSame(1, app(ScheduledFlowRunner::class)->run());
    }

    public function test_a_scheduled_flow_does_not_run_before_its_time(): void
    {
        $this->admin();
        $this->flow('scheduled', ['time' => now()->addHours(2)->format('H:i'), 'audience' => 'admins']);

        $this->assertSame(0, app(ScheduledFlowRunner::class)->run());
    }

    public function test_a_scheduled_flow_runs_only_once_a_day(): void
    {
        $this->admin();
        $this->flow('scheduled', ['time' => now()->subHour()->format('H:i'), 'audience' => 'admins']);

        $runner = app(ScheduledFlowRunner::class);

        $this->assertSame(1, $runner->run());
        $this->assertSame(0, $runner->run());
        $this->assertSame(0, $runner->run());
    }

    public function test_the_admins_audience_excludes_employees(): void
    {
        $this->admin('9000000001');
        $this->employee('9000000002');
        $this->flow('scheduled', ['time' => now()->subHour()->format('H:i'), 'audience' => 'admins']);

        $this->assertSame(1, app(ScheduledFlowRunner::class)->run());
    }

    public function test_the_staff_audience_includes_both(): void
    {
        $this->admin('9000000001');
        $this->employee('9000000002');
        $this->flow('scheduled', ['time' => now()->subHour()->format('H:i'), 'audience' => 'staff']);

        $this->assertSame(2, app(ScheduledFlowRunner::class)->run());
    }

    public function test_somebody_without_a_phone_number_is_simply_skipped(): void
    {
        $this->admin('9000000001');
        User::factory()->create(['role' => User::ROLE_ADMIN, 'phone' => null]);
        $this->flow('scheduled', ['time' => now()->subHour()->format('H:i'), 'audience' => 'admins']);

        $this->assertSame(1, app(ScheduledFlowRunner::class)->run());
    }

    public function test_an_unparseable_time_never_comes_due(): void
    {
        $this->admin();
        $this->flow('scheduled', ['time' => 'half past eight', 'audience' => 'admins']);

        $this->assertSame(0, app(ScheduledFlowRunner::class)->run());
    }

    public function test_an_inactive_scheduled_flow_never_runs(): void
    {
        $this->admin();
        $this->flow('scheduled', ['time' => now()->subHour()->format('H:i'), 'audience' => 'admins'])
            ->update(['is_active' => false]);

        $this->assertSame(0, app(ScheduledFlowRunner::class)->run());
    }

    /** Missed days are skipped, not replayed -- yesterday's briefing is noise. */
    public function test_a_missed_day_is_not_backfilled(): void
    {
        $this->admin();
        $flow = $this->flow('scheduled', ['time' => now()->subHour()->format('H:i'), 'audience' => 'admins']);
        $flow->forceFill(['last_run_on' => today()->subDays(4)->toDateString()])->save();

        $this->assertSame(1, app(ScheduledFlowRunner::class)->run());
    }

    // ——— The seeded crew graph actually routes ———

    /**
     * End to end over the real graph shipped with this change: a crew member
     * texting "the tripod is broken" must reach flag_damaged with the item
     * name intact -- the reason kit reporting is keyword-matched rather than
     * offered as a menu row that could only ever send its own id.
     */
    public function test_the_crew_graph_routes_a_typed_kit_report_to_the_right_action(): void
    {
        $crew = $this->employee('9786579573');
        $item = EquipmentItem::create(['name' => 'Tripod', 'quantity' => 2]);

        $flow = WhatsappFlow::create([
            'name' => 'Crew self-service (WhatsApp)',
            'trigger_type' => 'keyword',
            'trigger_config' => ['keyword' => 'hi'],
            'is_active' => true,
            'graph' => require database_path('flows/crew_menu.php'),
        ]);

        $session = WhatsappFlowSession::create([
            'flow_id' => $flow->id,
            'wa_id' => '919786579573',
            'current_node_id' => $flow->graph['start_node_id'],
            'variables' => array_merge(
                FlowEngine::identityVariables('919786579573'),
                ['message' => ['text' => 'the tripod is broken', 'normalized' => 'the tripod is broken', 'choice' => 'the tripod is broken']],
            ),
            'status' => 'active',
            'iteration_count' => 0,
            'started_at' => now(),
        ]);

        app(FlowEngine::class)->resume($session);

        $this->assertSame(EquipmentItem::STATUS_DAMAGED, $item->refresh()->status);
    }

    /** A number nobody recognises never reaches an action node. */
    public function test_the_crew_graph_tells_a_stranger_nothing(): void
    {
        $item = EquipmentItem::create(['name' => 'Tripod', 'quantity' => 2]);

        $flow = WhatsappFlow::create([
            'name' => 'Crew self-service (WhatsApp)',
            'trigger_type' => 'keyword',
            'trigger_config' => ['keyword' => 'hi'],
            'is_active' => true,
            'graph' => require database_path('flows/crew_menu.php'),
        ]);

        $session = WhatsappFlowSession::create([
            'flow_id' => $flow->id,
            'wa_id' => '919999000011',
            'current_node_id' => $flow->graph['start_node_id'],
            'variables' => ['message' => ['text' => 'the tripod is broken', 'normalized' => 'the tripod is broken', 'choice' => 'the tripod is broken']],
            'status' => 'active',
            'iteration_count' => 0,
            'started_at' => now(),
        ]);

        app(FlowEngine::class)->resume($session);

        $this->assertSame(EquipmentItem::STATUS_AVAILABLE, $item->refresh()->status);
    }
    // ——— Answering a menu ———

    /** The owner menu as it is really seeded, so the ladder under it is real. */
    private function ownerMenu(): WhatsappFlow
    {
        return WhatsappFlow::create([
            'name' => 'Studio owner menu (WhatsApp)',
            'trigger_type' => 'keyword',
            'trigger_config' => ['keyword' => 'studio'],
            'is_active' => true,
            'graph' => require database_path('flows/admin_menu.php'),
        ]);
    }

    /**
     * An inbound message the way Meta delivers one, through ingest() so the
     * observer drives the engine exactly as the live webhook does. A
     * $replyId makes it a tapped list row rather than typed text -- which is
     * the whole point: the tap's text is the row's title, never the keyword.
     */
    private function inbound(string $waId, string $text, ?string $replyId = null): void
    {
        $message = ['from' => $waId, 'id' => 'wamid.'.uniqid(), 'timestamp' => (string) now()->timestamp];

        $message += $replyId === null
            ? ['type' => 'text', 'text' => ['body' => $text]]
            : ['type' => 'interactive', 'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => $replyId, 'title' => $text]]];

        WhatsappWebhookEvent::ingest([
            'object' => 'whatsapp_business_account',
            'entry' => [['id' => '102290129340398', 'changes' => [['field' => 'messages', 'value' => [
                'messaging_product' => 'whatsapp',
                'metadata' => ['display_phone_number' => '919876543210', 'phone_number_id' => '556677889900'],
                'messages' => [$message],
            ]]]]],
        ]);
    }

    /** What the studio's number was actually sent, newest last. */
    private function sentBodies(): array
    {
        return collect(Http::recorded())
            ->map(fn (array $pair) => (string) (data_get($pair[0]->data(), 'text.body')
                ?? data_get($pair[0]->data(), 'interactive.body.text')))
            ->all();
    }

    public function test_an_admin_tapping_a_menu_row_is_answered(): void
    {
        $this->admin('7094126823');
        $this->ownerMenu();

        $this->inbound('917094126823', 'studio');
        $this->inbound('917094126823', 'Money', replyId: '1');

        // The menu, then the figures -- not the menu twice, and not silence.
        $bodies = $this->sentBodies();
        $this->assertCount(3, $bodies);
        $this->assertStringContainsString('what do you want to see?', $bodies[0]);
        $this->assertStringContainsString('Collected:', $bodies[1]);
        $this->assertStringContainsString('Anything else?', $bodies[2]);
    }

    public function test_an_admin_typing_the_row_number_is_answered_too(): void
    {
        $this->admin('7094126823');
        $this->ownerMenu();

        $this->inbound('917094126823', 'studio');
        $this->inbound('917094126823', '2');

        $this->assertStringContainsString('overdue', strtolower(implode("\n", $this->sentBodies())));
    }

    public function test_a_menu_older_than_the_continuation_window_is_not_answered(): void
    {
        $this->admin('7094126823');
        $this->ownerMenu();

        $this->inbound('917094126823', 'studio');

        $this->travel(3)->hours();
        $this->inbound('917094126823', 'Money', replyId: '1');

        // Yesterday's menu is not a live question: the tap matches no flow at
        // all rather than quietly reading out the money.
        $this->assertCount(1, $this->sentBodies());
        $this->assertSame(1, WhatsappFlowSession::count());
    }

    public function test_a_thread_a_human_has_taken_over_is_left_to_them(): void
    {
        $admin = $this->admin('7094126823');
        $this->ownerMenu();

        $this->inbound('917094126823', 'studio');
        WhatsappConversation::where('wa_id', '917094126823')->update(['assigned_to_id' => $admin->id]);

        $this->inbound('917094126823', 'Money', replyId: '1');

        $this->assertCount(1, $this->sentBodies());
    }

    public function test_a_stranger_gets_no_continuation(): void
    {
        $this->ownerMenu();

        // No staff row for this number, so nothing to continue -- the flow it
        // would continue into is the one that reads every client's money.
        $this->inbound('919000009999', 'Money', replyId: '1');

        $this->assertSame(0, WhatsappFlowSession::count());
        $this->assertCount(0, $this->sentBodies());
    }
}
