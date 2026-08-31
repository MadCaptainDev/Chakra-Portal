<?php

namespace Tests\Unit;

use App\Services\WhatsappFlow\DrawflowGraphTranslator;
use RuntimeException;
use Tests\TestCase;

/**
 * DrawflowGraphTranslator's own edge cases, isolated from the HTTP layer --
 * WhatsappFlowHttpTest (tests/Feature) proves the controller wires this in
 * correctly with one realistic end-to-end fixture; this file is where the
 * translation's individual rules (casting, the two-output condition node,
 * unknown node types, the reverse direction) get exercised directly.
 */
class DrawflowGraphTranslatorTest extends TestCase
{
    private function node(string $id, string $type, array $data, array $outputs, array $incoming = [], array $pos = ['x' => 10, 'y' => 20]): array
    {
        return [
            'id' => (int) $id,
            'name' => $type,
            'data' => array_merge(['type' => $type, 'is_start' => false], $data),
            'class' => $type,
            'html' => '<div></div>',
            'typenode' => false,
            'inputs' => ['input_1' => ['connections' => $incoming]],
            'outputs' => $outputs,
            'pos_x' => $pos['x'],
            'pos_y' => $pos['y'],
        ];
    }

    private function export(array $nodes): array
    {
        return ['drawflow' => ['Home' => ['data' => $nodes]]];
    }

    public function test_body_parameters_are_split_on_commas_and_trimmed(): void
    {
        $export = $this->export([
            '1' => $this->node('1', 'send_template', ['is_start' => true, 'template' => 'hello', 'language' => 'en_US', 'body_parameters' => ' Ravi ,  Friday,, '], ['output_1' => ['connections' => []]]),
        ]);

        $graph = DrawflowGraphTranslator::toEngineGraph($export);

        $this->assertSame(['Ravi', 'Friday'], $graph['nodes']['1']['body_parameters']);
    }

    public function test_delay_seconds_and_agent_transfer_user_id_are_cast_to_int(): void
    {
        $export = $this->export([
            '1' => $this->node('1', 'delay', ['is_start' => true, 'seconds' => '90'], ['output_1' => ['connections' => []]]),
        ]);

        $graph = DrawflowGraphTranslator::toEngineGraph($export);
        $this->assertSame(90, $graph['nodes']['1']['seconds']);

        $exportTransfer = $this->export([
            '1' => $this->node('1', 'agent_transfer', ['is_start' => true, 'user_id' => '7'], []),
        ]);
        $graphTransfer = DrawflowGraphTranslator::toEngineGraph($exportTransfer);
        $this->assertSame(7, $graphTransfer['nodes']['1']['user_id']);

        $exportUnassigned = $this->export([
            '1' => $this->node('1', 'agent_transfer', ['is_start' => true, 'user_id' => ''], []),
        ]);
        $graphUnassigned = DrawflowGraphTranslator::toEngineGraph($exportUnassigned);
        $this->assertNull($graphUnassigned['nodes']['1']['user_id']);
    }

    public function test_make_request_payload_decodes_valid_json_to_an_array(): void
    {
        $export = $this->export([
            '1' => $this->node('1', 'make_request', ['is_start' => true, 'url' => 'https://example.test', 'payload' => '{"a": 1, "b": "two"}'], ['output_1' => ['connections' => []]]),
        ]);

        $graph = DrawflowGraphTranslator::toEngineGraph($export);

        $this->assertSame(['a' => 1, 'b' => 'two'], $graph['nodes']['1']['payload']);
    }

    public function test_make_request_payload_defaults_to_an_empty_array_when_blank(): void
    {
        $export = $this->export([
            '1' => $this->node('1', 'make_request', ['is_start' => true, 'url' => 'https://example.test', 'payload' => '  '], ['output_1' => ['connections' => []]]),
        ]);

        $graph = DrawflowGraphTranslator::toEngineGraph($export);

        $this->assertSame([], $graph['nodes']['1']['payload']);
    }

    public function test_make_request_payload_throws_on_invalid_json(): void
    {
        $export = $this->export([
            '1' => $this->node('1', 'make_request', ['is_start' => true, 'url' => 'https://example.test', 'payload' => '{oops'], ['output_1' => ['connections' => []]]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Payload must be valid JSON.');

        DrawflowGraphTranslator::toEngineGraph($export);
    }

    public function test_condition_nodes_read_next_true_and_next_false_off_their_two_outputs(): void
    {
        $export = $this->export([
            '1' => $this->node('1', 'condition', ['is_start' => true, 'variable' => 'x', 'operator' => 'exists', 'value' => ''], [
                'output_1' => ['connections' => [['node' => '2', 'output' => 'input_1']]],
                'output_2' => ['connections' => [['node' => '3', 'output' => 'input_1']]],
            ]),
            '2' => $this->node('2', 'send_message', ['body' => 'yes'], ['output_1' => ['connections' => []]], [['node' => '1', 'output' => 'output_1']]),
            '3' => $this->node('3', 'send_message', ['body' => 'no'], ['output_1' => ['connections' => []]], [['node' => '1', 'output' => 'output_2']]),
        ]);

        $graph = DrawflowGraphTranslator::toEngineGraph($export);

        $this->assertSame('2', $graph['nodes']['1']['next_true']);
        $this->assertSame('3', $graph['nodes']['1']['next_false']);
        $this->assertArrayNotHasKey('next', $graph['nodes']['1']);
    }

    public function test_a_node_with_an_unknown_type_is_skipped_rather_than_rejected(): void
    {
        $export = $this->export([
            '1' => $this->node('1', 'send_message', ['is_start' => true, 'body' => 'hi'], ['output_1' => ['connections' => []]]),
            '2' => $this->node('2', 'some_future_node_type', [], []),
        ]);

        $graph = DrawflowGraphTranslator::toEngineGraph($export);

        $this->assertCount(1, $graph['nodes']);
        $this->assertArrayNotHasKey('2', $graph['nodes']);
    }

    /**
     * The companion case to the previous test: node "2" is still skipped
     * for being an unknown type, but this time node "1" actually points at
     * it via `next` -- a dangling pointer that must be rejected at save
     * time rather than silently saved and only discovered when FlowEngine
     * hits "Flow graph has no node '2'" mid-run.
     */
    public function test_a_next_pointer_at_a_dropped_unknown_type_node_is_rejected(): void
    {
        $export = $this->export([
            '1' => $this->node('1', 'send_message', ['is_start' => true, 'body' => 'hi'], ['output_1' => ['connections' => [['node' => '2', 'output' => 'input_1']]]]),
            '2' => $this->node('2', 'some_future_node_type', [], []),
        ]);

        $this->expectException(RuntimeException::class);

        DrawflowGraphTranslator::toEngineGraph($export);
    }

    public function test_a_condition_next_true_pointer_at_a_dropped_node_is_rejected(): void
    {
        $export = $this->export([
            '1' => $this->node('1', 'condition', ['is_start' => true, 'variable' => 'x', 'operator' => 'exists', 'value' => ''], [
                'output_1' => ['connections' => [['node' => '2', 'output' => 'input_1']]],
                'output_2' => ['connections' => []],
            ]),
            '2' => $this->node('2', 'some_future_node_type', [], []),
        ]);

        $this->expectException(RuntimeException::class);

        DrawflowGraphTranslator::toEngineGraph($export);
    }

    public function test_start_node_id_is_null_when_no_node_is_marked_the_start(): void
    {
        $export = $this->export([
            '1' => $this->node('1', 'send_message', ['body' => 'hi'], ['output_1' => ['connections' => []]]),
        ]);

        $graph = DrawflowGraphTranslator::toEngineGraph($export);

        $this->assertNull($graph['start_node_id']);
    }

    /**
     * The reverse direction, round-tripped: an engine-shaped graph ->
     * Drawflow import shape -> back to engine shape again should land on
     * the same meaningful data (modulo `_pos`, which toDrawflowExport()
     * always supplies even when the original had none).
     */
    public function test_to_drawflow_export_round_trips_back_through_to_engine_graph(): void
    {
        $original = [
            'start_node_id' => '1',
            'nodes' => [
                '1' => ['type' => 'send_message', 'body' => 'Hello!', 'next' => '2', '_pos' => ['x' => 5, 'y' => 6]],
                '2' => ['type' => 'condition', 'variable' => 'message.text', 'operator' => 'contains', 'value' => 'yes', 'next_true' => '3', 'next_false' => null, '_pos' => ['x' => 100, 'y' => 6]],
                '3' => ['type' => 'set_label', 'label' => 'Interested', 'next' => null, '_pos' => ['x' => 200, 'y' => 6]],
            ],
        ];

        $drawflow = DrawflowGraphTranslator::toDrawflowExport($original, [['id' => 9, 'name' => 'Priya']]);

        // Incoming connections were reconstructed correctly for node "2" --
        // Drawflow's own addConnection() (node_modules/drawflow/dist/
        // drawflow.min.js) pushes a DIFFERENT key name on each side of a
        // connection: the *output* side's connections carry an `output`
        // key, the *input* side's carry an `input` key -- confirmed against
        // both addConnection() itself and the README's own "Export
        // example". This is the `inputs` side, so the key must be `input`.
        $this->assertSame(
            [['node' => '1', 'input' => 'output_1']],
            $drawflow['drawflow']['Home']['data']['2']['inputs']['input_1']['connections']
        );
        // And the matching `outputs` side (node "1"'s own outgoing edge)
        // carries the opposite key name, `output`, with the *target's*
        // input slot as its value -- the two sides are not symmetric.
        $this->assertSame(
            [['node' => '2', 'output' => 'input_1']],
            $drawflow['drawflow']['Home']['data']['1']['outputs']['output_1']['connections']
        );
        $this->assertTrue($drawflow['drawflow']['Home']['data']['1']['data']['is_start']);
        $this->assertFalse($drawflow['drawflow']['Home']['data']['2']['data']['is_start']);

        $roundTripped = DrawflowGraphTranslator::toEngineGraph($drawflow);

        $this->assertSame('1', $roundTripped['start_node_id']);
        $this->assertSame('Hello!', $roundTripped['nodes']['1']['body']);
        $this->assertSame('2', $roundTripped['nodes']['1']['next']);
        $this->assertSame('3', $roundTripped['nodes']['2']['next_true']);
        $this->assertNull($roundTripped['nodes']['2']['next_false']);
        $this->assertSame('Interested', $roundTripped['nodes']['3']['label']);
    }

    public function test_to_drawflow_export_renders_the_assignable_users_into_the_agent_transfer_select(): void
    {
        $graph = [
            'start_node_id' => '1',
            'nodes' => ['1' => ['type' => 'agent_transfer', 'user_id' => null]],
        ];

        $drawflow = DrawflowGraphTranslator::toDrawflowExport($graph, [['id' => 42, 'name' => 'Priya <Lead>']]);

        $html = $drawflow['drawflow']['Home']['data']['1']['html'];
        $this->assertStringContainsString('value="42"', $html);
        $this->assertStringContainsString('Priya &lt;Lead&gt;', $html);
    }

    public function test_send_list_rows_parse_one_option_per_line(): void
    {
        $export = $this->export([
            '1' => $this->node('1', 'send_list', [
                'is_start' => true,
                'body' => 'Pick one',
                'rows' => "1|Invoices|Your bills\n2|Report",
                'button' => 'Go',
            ], ['output_1' => ['connections' => []]]),
        ]);

        $graph = DrawflowGraphTranslator::toEngineGraph($export);

        $this->assertSame([
            ['id' => '1', 'title' => 'Invoices', 'description' => 'Your bills'],
            ['id' => '2', 'title' => 'Report', 'description' => ''],
        ], $graph['nodes']['1']['rows']);
    }

    /**
     * explode(..., 3) on '|', not unlimited -- a description may itself
     * contain a literal "|" without truncating; only the id and title
     * must be pipe-free.
     */
    public function test_a_send_list_option_description_may_contain_a_pipe(): void
    {
        $export = $this->export([
            '1' => $this->node('1', 'send_list', [
                'is_start' => true, 'body' => 'x', 'rows' => '1|Invoices|Due 1 Aug | 2 Aug', 'button' => 'Go',
            ], ['output_1' => ['connections' => []]]),
        ]);

        $graph = DrawflowGraphTranslator::toEngineGraph($export);

        $this->assertSame('Due 1 Aug | 2 Aug', $graph['nodes']['1']['rows'][0]['description']);
    }

    public function test_send_list_blank_lines_are_dropped(): void
    {
        $export = $this->export([
            '1' => $this->node('1', 'send_list', [
                'is_start' => true, 'body' => 'x', 'rows' => "1|Invoices\n\n\n2|Report\n", 'button' => 'Go',
            ], ['output_1' => ['connections' => []]]),
        ]);

        $graph = DrawflowGraphTranslator::toEngineGraph($export);

        $this->assertCount(2, $graph['nodes']['1']['rows']);
    }

    public function test_send_list_rejects_no_options(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('add at least one option');

        DrawflowGraphTranslator::toEngineGraph($this->export([
            '1' => $this->node('1', 'send_list', ['is_start' => true, 'body' => 'x', 'rows' => '', 'button' => 'Go'], ['output_1' => ['connections' => []]]),
        ]));
    }

    public function test_send_list_rejects_more_than_ten_options(): void
    {
        $lines = collect(range(1, 11))->map(fn (int $i) => "{$i}|Option {$i}")->implode("\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('at most 10 options (found 11)');

        DrawflowGraphTranslator::toEngineGraph($this->export([
            '1' => $this->node('1', 'send_list', ['is_start' => true, 'body' => 'x', 'rows' => $lines, 'button' => 'Go'], ['output_1' => ['connections' => []]]),
        ]));
    }

    public function test_send_list_rejects_a_row_missing_a_title(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('needs both an id and a title');

        DrawflowGraphTranslator::toEngineGraph($this->export([
            '1' => $this->node('1', 'send_list', ['is_start' => true, 'body' => 'x', 'rows' => '1', 'button' => 'Go'], ['output_1' => ['connections' => []]]),
        ]));
    }

    public function test_send_list_rejects_an_option_title_over_24_characters(): void
    {
        $longTitle = str_repeat('a', 25);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('24 characters or fewer');

        DrawflowGraphTranslator::toEngineGraph($this->export([
            '1' => $this->node('1', 'send_list', ['is_start' => true, 'body' => 'x', 'rows' => "1|{$longTitle}", 'button' => 'Go'], ['output_1' => ['connections' => []]]),
        ]));
    }

    public function test_send_list_rejects_an_option_description_over_72_characters(): void
    {
        $longDescription = str_repeat('a', 73);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('72 characters or fewer');

        DrawflowGraphTranslator::toEngineGraph($this->export([
            '1' => $this->node('1', 'send_list', ['is_start' => true, 'body' => 'x', 'rows' => "1|Title|{$longDescription}", 'button' => 'Go'], ['output_1' => ['connections' => []]]),
        ]));
    }

    /**
     * Duplicate ids silently route two options down one branch -- caught
     * here rather than left to surface as confusing routing behaviour
     * later.
     */
    public function test_send_list_rejects_duplicate_option_ids(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('option ids must be unique');

        DrawflowGraphTranslator::toEngineGraph($this->export([
            '1' => $this->node('1', 'send_list', ['is_start' => true, 'body' => 'x', 'rows' => "1|First\n1|Second", 'button' => 'Go'], ['output_1' => ['connections' => []]]),
        ]));
    }

    public function test_send_list_rejects_a_button_label_over_20_characters(): void
    {
        $longButton = str_repeat('a', 21);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('20 characters or fewer');

        DrawflowGraphTranslator::toEngineGraph($this->export([
            '1' => $this->node('1', 'send_list', ['is_start' => true, 'body' => 'x', 'rows' => '1|Title', 'button' => $longButton], ['output_1' => ['connections' => []]]),
        ]));
    }

    public function test_a_blank_send_list_button_label_defaults_to_select_option(): void
    {
        $graph = DrawflowGraphTranslator::toEngineGraph($this->export([
            '1' => $this->node('1', 'send_list', ['is_start' => true, 'body' => 'x', 'rows' => '1|Title', 'button' => ''], ['output_1' => ['connections' => []]]),
        ]));

        $this->assertSame('Select Option', $graph['nodes']['1']['button']);
    }

    /**
     * The reverse direction: a saved send_list node reopens with its
     * textarea repopulated exactly as it would have been typed, id|Title|
     * Description per line -- proves uncast('list_rows') is the true
     * inverse of castListRows(), not just visually similar.
     */
    public function test_send_list_round_trips_through_to_drawflow_export(): void
    {
        $original = [
            'start_node_id' => '1',
            'nodes' => [
                '1' => [
                    'type' => 'send_list',
                    'body' => 'Pick one',
                    'rows' => [
                        ['id' => '1', 'title' => 'Invoices', 'description' => 'Your bills'],
                        ['id' => '2', 'title' => 'Report', 'description' => ''],
                    ],
                    'button' => 'Select Option',
                    'header' => '',
                    'footer' => '',
                    'next' => null,
                    '_pos' => ['x' => 5, 'y' => 6],
                ],
            ],
        ];

        $drawflow = DrawflowGraphTranslator::toDrawflowExport($original);
        $roundTripped = DrawflowGraphTranslator::toEngineGraph($drawflow);

        $this->assertSame($original['nodes']['1']['rows'], $roundTripped['nodes']['1']['rows']);
        $this->assertSame('Select Option', $roundTripped['nodes']['1']['button']);
    }
}
