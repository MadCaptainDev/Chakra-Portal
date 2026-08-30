<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsappFlow;
use App\Models\WhatsappFlowSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The HTTP surface for Automations (WhatsappFlowController) and the
 * read-only Sessions debug screen (WhatsappFlowSessionController).
 *
 * The store()/update() tests post a real Drawflow-shaped `graph` payload --
 * built from this task's own installation of drawflow and reading of
 * node_modules/drawflow/README.md's "Export example", not guessed -- and
 * confirm DrawflowGraphTranslator turns it into exactly the FlowEngine
 * shape a flow's `graph` column is meant to hold (see
 * App\Services\WhatsappFlow\DrawflowGraphTranslator and
 * App\Services\WhatsappFlow\FlowEngine::run()). DrawflowGraphTranslatorTest
 * (tests/Unit) exercises that translation's edge cases directly; this file
 * only has to prove the HTTP round trip works end to end.
 */
class WhatsappFlowHttpTest extends TestCase
{
    use RefreshDatabase;

    private function employee(array $abilities = ['view']): User
    {
        $user = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);
        $user->syncPermissions(['whatsapp-crm' => $abilities]);

        return $user->refresh();
    }

    /**
     * A real Drawflow export(): one send_message node feeding a condition
     * node, which branches true into a set_label node and false into an
     * agent_transfer node. Shaped exactly the way
     * node_modules/drawflow/README.md's "Export example" documents --
     * `{drawflow: {Home: {data: {<id>: {id, name, data, class, html,
     * typenode, inputs, outputs, pos_x, pos_y}}}}}` -- with `data.is_start`
     * marking node "1" the way resources/js/whatsapp-flow-builder.js's own
     * "Set as start" handler would.
     *
     * @return array<string, mixed>
     */
    private function drawflowExportFixture(): array
    {
        return [
            'drawflow' => [
                'Home' => [
                    'data' => [
                        '1' => [
                            'id' => 1,
                            'name' => 'send_message',
                            'data' => ['type' => 'send_message', 'is_start' => true, 'body' => 'Hello from the studio!'],
                            'class' => 'send_message',
                            'html' => '<div class="flow-node">Send Message</div>',
                            'typenode' => false,
                            'inputs' => ['input_1' => ['connections' => []]],
                            'outputs' => ['output_1' => ['connections' => [['node' => '2', 'output' => 'input_1']]]],
                            'pos_x' => 80,
                            'pos_y' => 80,
                        ],
                        '2' => [
                            'id' => 2,
                            'name' => 'condition',
                            'data' => ['type' => 'condition', 'is_start' => false, 'variable' => 'message.text', 'operator' => 'equals', 'value' => 'yes'],
                            'class' => 'condition',
                            'html' => '<div class="flow-node">Condition</div>',
                            'typenode' => false,
                            'inputs' => ['input_1' => ['connections' => [['node' => '1', 'output' => 'output_1']]]],
                            'outputs' => [
                                'output_1' => ['connections' => [['node' => '3', 'output' => 'input_1']]],
                                'output_2' => ['connections' => [['node' => '4', 'output' => 'input_1']]],
                            ],
                            'pos_x' => 340,
                            'pos_y' => 80,
                        ],
                        '3' => [
                            'id' => 3,
                            'name' => 'set_label',
                            'data' => ['type' => 'set_label', 'is_start' => false, 'label' => 'Interested'],
                            'class' => 'set_label',
                            'html' => '<div class="flow-node">Set Label</div>',
                            'typenode' => false,
                            'inputs' => ['input_1' => ['connections' => [['node' => '2', 'output' => 'output_1']]]],
                            'outputs' => ['output_1' => ['connections' => []]],
                            'pos_x' => 600,
                            'pos_y' => 40,
                        ],
                        '4' => [
                            'id' => 4,
                            'name' => 'agent_transfer',
                            'data' => ['type' => 'agent_transfer', 'is_start' => false, 'user_id' => ''],
                            'class' => 'agent_transfer',
                            'html' => '<div class="flow-node">Agent Transfer</div>',
                            'typenode' => false,
                            'inputs' => ['input_1' => ['connections' => [['node' => '2', 'output' => 'output_2']]]],
                            'outputs' => [],
                            'pos_x' => 600,
                            'pos_y' => 160,
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function flowPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Greeting flow',
            'trigger_type' => 'inbound_message',
            'graph' => json_encode($this->drawflowExportFixture()),
        ], $overrides);
    }

    // -- index --------------------------------------------------------------

    public function test_an_ungranted_employee_is_refused_the_index(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($employee)->get(route('whatsapp-crm.flows.index'))->assertForbidden();
    }

    public function test_a_user_with_view_can_list_flows(): void
    {
        WhatsappFlow::create([
            'name' => 'Greeting flow',
            'trigger_type' => 'inbound_message',
            'graph' => ['start_node_id' => null, 'nodes' => []],
        ]);

        $this->actingAs($this->employee())
            ->get(route('whatsapp-crm.flows.index'))
            ->assertOk()
            ->assertSee('Greeting flow');
    }

    public function test_the_create_and_edit_forms_render(): void
    {
        $flow = WhatsappFlow::create([
            'name' => 'Greeting flow',
            'trigger_type' => 'inbound_message',
            'graph' => ['start_node_id' => null, 'nodes' => []],
        ]);
        $user = $this->employee();

        $this->actingAs($user)->get(route('whatsapp-crm.flows.create'))->assertOk();
        $this->actingAs($user)->get(route('whatsapp-crm.flows.edit', $flow))->assertOk()->assertSee('Greeting flow');
    }

    // -- store ----------------------------------------------------------------

    public function test_creating_a_flow_requires_the_create_ability(): void
    {
        $this->actingAs($this->employee())
            ->post(route('whatsapp-crm.flows.store'), $this->flowPayload())
            ->assertForbidden();

        $this->assertSame(0, WhatsappFlow::count());
    }

    /**
     * The round trip the brief asks for: POST a real Drawflow export, then
     * confirm what actually landed in the `graph` column is FlowEngine's
     * own shape -- not Drawflow's -- with every node's config translated
     * correctly (including the two-output condition node and the
     * zero-output, no-`next` agent_transfer node).
     */
    public function test_storing_a_flow_translates_a_real_drawflow_export_into_flow_engines_graph_shape(): void
    {
        $user = $this->employee(['view', 'create']);

        $response = $this->actingAs($user)->post(route('whatsapp-crm.flows.store'), $this->flowPayload([
            'trigger_type' => 'keyword',
            'trigger_config' => ['keyword' => 'hello'],
        ]));

        $flow = WhatsappFlow::sole();
        $response->assertRedirect(route('whatsapp-crm.flows.edit', $flow));

        $this->assertSame('Greeting flow', $flow->name);
        $this->assertSame('keyword', $flow->trigger_type);
        $this->assertSame('hello', $flow->trigger_config['keyword']);
        $this->assertSame($user->id, $flow->created_by_id);
        $this->assertFalse($flow->is_active);

        $graph = $flow->graph;
        $this->assertSame('1', $graph['start_node_id']);
        $this->assertSame([
            'type' => 'send_message',
            'body' => 'Hello from the studio!',
            'next' => '2',
        ], collect($graph['nodes']['1'])->except('_pos')->all());

        $this->assertSame([
            'type' => 'condition',
            'variable' => 'message.text',
            'operator' => 'equals',
            'value' => 'yes',
            'next_true' => '3',
            'next_false' => '4',
        ], collect($graph['nodes']['2'])->except('_pos')->all());

        $this->assertSame([
            'type' => 'set_label',
            'label' => 'Interested',
            'next' => null,
        ], collect($graph['nodes']['3'])->except('_pos')->all());

        // agent_transfer has 0 outputs -- no `next` key at all, matching
        // AgentTransferNode's own config (`user_id` only) and NodeResult::ended().
        $this->assertSame([
            'type' => 'agent_transfer',
            'user_id' => null,
        ], collect($graph['nodes']['4'])->except('_pos')->all());
    }

    public function test_storing_a_flow_with_no_start_node_marked_is_rejected(): void
    {
        $export = $this->drawflowExportFixture();
        $export['drawflow']['Home']['data']['1']['data']['is_start'] = false;

        $this->actingAs($this->employee(['view', 'create']))
            ->post(route('whatsapp-crm.flows.store'), $this->flowPayload(['graph' => json_encode($export)]))
            ->assertSessionHasErrors('graph');

        $this->assertSame(0, WhatsappFlow::count());
    }

    public function test_storing_a_flow_with_invalid_json_in_a_make_request_payload_is_rejected(): void
    {
        $export = [
            'drawflow' => ['Home' => ['data' => [
                '1' => [
                    'id' => 1,
                    'name' => 'make_request',
                    'data' => ['type' => 'make_request', 'is_start' => true, 'url' => 'https://example.test/hook', 'payload' => '{not valid json'],
                    'class' => 'make_request',
                    'html' => '<div></div>',
                    'typenode' => false,
                    'inputs' => ['input_1' => ['connections' => []]],
                    'outputs' => ['output_1' => ['connections' => []]],
                    'pos_x' => 0,
                    'pos_y' => 0,
                ],
            ]]],
        ];

        $this->actingAs($this->employee(['view', 'create']))
            ->post(route('whatsapp-crm.flows.store'), $this->flowPayload(['graph' => json_encode($export)]))
            ->assertSessionHasErrors('graph');

        $this->assertSame(0, WhatsappFlow::count());
    }

    public function test_a_flow_can_be_saved_with_zero_nodes_as_a_draft(): void
    {
        $empty = ['drawflow' => ['Home' => ['data' => []]]];

        $response = $this->actingAs($this->employee(['view', 'create']))
            ->post(route('whatsapp-crm.flows.store'), $this->flowPayload(['graph' => json_encode($empty)]));

        $flow = WhatsappFlow::sole();
        $response->assertRedirect(route('whatsapp-crm.flows.edit', $flow));
        $this->assertSame([], $flow->graph['nodes']);
        $this->assertNull($flow->graph['start_node_id']);
    }

    // -- update -----------------------------------------------------------------

    public function test_updating_a_flow_requires_the_edit_ability(): void
    {
        $flow = WhatsappFlow::create([
            'name' => 'Greeting flow',
            'trigger_type' => 'inbound_message',
            'graph' => ['start_node_id' => null, 'nodes' => []],
        ]);

        $this->actingAs($this->employee(['view', 'create']))
            ->put(route('whatsapp-crm.flows.update', $flow), $this->flowPayload())
            ->assertForbidden();
    }

    public function test_a_user_with_edit_can_update_a_flow_and_its_version_increments(): void
    {
        $flow = WhatsappFlow::create([
            'name' => 'Greeting flow',
            'trigger_type' => 'inbound_message',
            'graph' => ['start_node_id' => null, 'nodes' => []],
        ]);

        $response = $this->actingAs($this->employee(['view', 'edit']))
            ->put(route('whatsapp-crm.flows.update', $flow), $this->flowPayload(['name' => 'Renamed flow']));

        $response->assertRedirect(route('whatsapp-crm.flows.edit', $flow));
        $flow->refresh();
        $this->assertSame('Renamed flow', $flow->name);
        $this->assertSame(2, $flow->version);
        $this->assertSame('1', $flow->graph['start_node_id']);
        $this->assertCount(4, $flow->graph['nodes']);
    }

    // -- destroy ------------------------------------------------------------

    public function test_deleting_a_flow_requires_the_delete_ability(): void
    {
        $flow = WhatsappFlow::create([
            'name' => 'Greeting flow',
            'trigger_type' => 'inbound_message',
            'graph' => ['start_node_id' => null, 'nodes' => []],
        ]);

        $this->actingAs($this->employee(['view', 'edit']))
            ->delete(route('whatsapp-crm.flows.destroy', $flow))
            ->assertForbidden();

        $this->assertNotNull($flow->fresh());
    }

    public function test_a_user_with_delete_can_delete_a_flow(): void
    {
        $flow = WhatsappFlow::create([
            'name' => 'Greeting flow',
            'trigger_type' => 'inbound_message',
            'graph' => ['start_node_id' => null, 'nodes' => []],
        ]);

        $this->actingAs($this->employee(['view', 'delete']))
            ->delete(route('whatsapp-crm.flows.destroy', $flow))
            ->assertRedirect(route('whatsapp-crm.flows.index'));

        $this->assertDatabaseMissing('whatsapp_flows', ['id' => $flow->id]);
    }

    // -- activate / deactivate -------------------------------------------------

    public function test_activate_and_deactivate_require_the_edit_ability(): void
    {
        $flow = $this->runnableFlow();

        $this->actingAs($this->employee(['view']))
            ->post(route('whatsapp-crm.flows.activate', $flow))
            ->assertForbidden();

        $this->assertFalse($flow->fresh()->is_active);
    }

    public function test_activating_a_flow_with_no_nodes_is_refused(): void
    {
        $flow = WhatsappFlow::create([
            'name' => 'Empty flow',
            'trigger_type' => 'inbound_message',
            'graph' => ['start_node_id' => null, 'nodes' => []],
        ]);

        $response = $this->actingAs($this->employee(['view', 'edit']))
            ->post(route('whatsapp-crm.flows.activate', $flow));

        $response->assertRedirect(route('whatsapp-crm.flows.index'));
        $response->assertSessionHas('error');
        $this->assertFalse($flow->fresh()->is_active);
    }

    public function test_activating_a_runnable_flow_toggles_is_active(): void
    {
        $flow = $this->runnableFlow();

        $response = $this->actingAs($this->employee(['view', 'edit']))
            ->post(route('whatsapp-crm.flows.activate', $flow));

        $response->assertRedirect(route('whatsapp-crm.flows.index'));
        $this->assertTrue($flow->fresh()->is_active);
    }

    /**
     * Only one `inbound_message` catch-all may run at once -- see
     * WhatsappFlowController::activate()'s own docblock for why this is
     * enforced rather than left to admin judgment.
     */
    public function test_activating_an_inbound_message_flow_deactivates_the_previous_catch_all(): void
    {
        $first = $this->runnableFlow(['name' => 'First catch-all']);
        $second = $this->runnableFlow(['name' => 'Second catch-all']);

        $user = $this->employee(['view', 'edit']);
        $this->actingAs($user)->post(route('whatsapp-crm.flows.activate', $first))->assertRedirect();
        $this->assertTrue($first->fresh()->is_active);

        $this->actingAs($user)->post(route('whatsapp-crm.flows.activate', $second))->assertRedirect();

        $this->assertFalse($first->fresh()->is_active);
        $this->assertTrue($second->fresh()->is_active);
    }

    /**
     * Two active `keyword` flows are allowed to coexist -- only the
     * `inbound_message` catch-all is exclusive (see the previous test).
     */
    public function test_two_keyword_flows_can_be_active_at_once(): void
    {
        $first = $this->runnableFlow(['name' => 'Price keyword', 'trigger_type' => 'keyword', 'trigger_config' => ['keyword' => 'price']]);
        $second = $this->runnableFlow(['name' => 'Hours keyword', 'trigger_type' => 'keyword', 'trigger_config' => ['keyword' => 'hours']]);

        $user = $this->employee(['view', 'edit']);
        $this->actingAs($user)->post(route('whatsapp-crm.flows.activate', $first))->assertRedirect();
        $this->actingAs($user)->post(route('whatsapp-crm.flows.activate', $second))->assertRedirect();

        $this->assertTrue($first->fresh()->is_active);
        $this->assertTrue($second->fresh()->is_active);
    }

    public function test_deactivating_a_flow_turns_it_off(): void
    {
        $flow = $this->runnableFlow(['is_active' => true]);

        $response = $this->actingAs($this->employee(['view', 'edit']))
            ->post(route('whatsapp-crm.flows.deactivate', $flow));

        $response->assertRedirect(route('whatsapp-crm.flows.index'));
        $this->assertFalse($flow->fresh()->is_active);
    }

    /** @return WhatsappFlow */
    private function runnableFlow(array $overrides = [])
    {
        return WhatsappFlow::create(array_merge([
            'name' => 'Runnable flow',
            'trigger_type' => 'inbound_message',
            'graph' => [
                'start_node_id' => 'greet',
                'nodes' => ['greet' => ['type' => 'send_message', 'body' => 'Hi!', 'next' => null]],
            ],
        ], $overrides));
    }

    // -- flow-sessions ----------------------------------------------------------

    public function test_flow_sessions_index_and_show_render(): void
    {
        $flow = $this->runnableFlow();
        $session = WhatsappFlowSession::create([
            'flow_id' => $flow->id,
            'wa_id' => '919812345678',
            'current_node_id' => 'greet',
            'variables' => ['message' => ['text' => 'hi', 'type' => 'text']],
            'status' => 'active',
            'iteration_count' => 1,
            'started_at' => now(),
        ]);

        $user = $this->employee();

        $this->actingAs($user)->get(route('whatsapp-crm.flow-sessions.index'))
            ->assertOk()
            ->assertSee('Runnable flow')
            ->assertSee('919812345678');

        $this->actingAs($user)->get(route('whatsapp-crm.flow-sessions.index', ['flow' => $flow->id, 'status' => 'active']))
            ->assertOk()
            ->assertSee('919812345678');

        $this->actingAs($user)->get(route('whatsapp-crm.flow-sessions.show', $session))
            ->assertOk()
            ->assertSee('919812345678')
            ->assertSee('greet');
    }

    public function test_an_ungranted_employee_is_refused_flow_sessions(): void
    {
        $employee = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $this->actingAs($employee)->get(route('whatsapp-crm.flow-sessions.index'))->assertForbidden();
    }
}
