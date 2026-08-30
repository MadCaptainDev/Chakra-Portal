<?php

namespace App\Services\WhatsappFlow;

use RuntimeException;

/**
 * Translates between Drawflow's own JSON shape and the shape FlowEngine
 * actually reads out of a WhatsappFlow's `graph` column.
 *
 * The two are genuinely different, confirmed by installing drawflow and
 * reading node_modules/drawflow/README.md's "Export example" rather than
 * guessed:
 *
 * - Drawflow's export()/import() shape: `{drawflow: {Home: {data: {<id>:
 *   {id, name, data, class, html, typenode, inputs: {input_1: {connections:
 *   [{node, output}]}}, outputs: {output_1: {connections: [{node, input}]}},
 *   pos_x, pos_y}}}}}`.
 * - FlowEngine's shape (App\Services\WhatsappFlow\FlowEngine::run(), and
 *   WhatsappFlow's own docblock): `{start_node_id, nodes: {<id>: {type,
 *   ...}}}`, where each node's own keys are exactly what that node type's
 *   handler reads (see NODE_TYPES below, one entry per
 *   app/Services/WhatsappFlow/Nodes/*.php class).
 *
 * This class is the one place that reconciles them, on the server, so the
 * client only ever has to call Drawflow's own export()/import() with no
 * shape translation of its own: resources/js/whatsapp-flow-builder.js posts
 * `editor.export()` verbatim as the `graph` field, and
 * WhatsappFlowController::store()/update() run it through toEngineGraph()
 * before it ever reaches the `graph` column. Going the other way,
 * flows/edit.blade.php calls toDrawflowExport() on a stored `graph` to
 * rebuild something `editor.import()` can load, restoring the canvas
 * exactly as it was saved (including layout -- see `_pos` below).
 *
 * Node ids round-trip unchanged in both directions: Drawflow assigns them
 * (auto-incrementing integers, starting at 1, unless `useuuid` is set --
 * this editor never sets it) and this class never invents, renumbers, or
 * relies on any particular id shape being present. FlowEngine only ever
 * uses a node id as an opaque map key and a `next`/`next_true`/`next_false`
 * pointer value.
 *
 * One consequence worth flagging rather than guarding against: a `graph`
 * whose node ids are NOT the plain small integers this UI's own save path
 * always produces (a hand-authored graph, a fixture, a flow imported from
 * elsewhere) can, after toDrawflowExport() round-trips it back into the
 * editor, collide with Drawflow's own next-id counter -- `load()`
 * (node_modules/drawflow/dist/drawflow.min.js) derives it as
 * `max(parseInt(existing id)) + 1` across whatever ids are present, so a
 * non-numeric id (e.g. "greet") contributes nothing to that max and a
 * freshly-added node could then be assigned an id that collides with one
 * already on the canvas. This is a pre-existing Drawflow behaviour, not
 * something introduced here, and is unreachable through this UI's own
 * save path (ids are always Drawflow-assigned integers); it only matters
 * for a `graph` that arrived some other way.
 */
class DrawflowGraphTranslator
{
    /**
     * One entry per node type this editor can produce, doing double duty:
     * `cast` drives toEngineGraph()'s type coercion (every df-bound field
     * arrives from the browser as a plain string) and the rest drives
     * nodeHtml()'s markup, so there is exactly one description of "what a
     * Send Message node looks like and needs" rather than one for casting
     * and a second, separately-maintained one for rendering.
     *
     * `outputs` is how many next-pointers a node of this type can carry: 1
     * for every "advance to one place" handler, 2 for ConditionNode's
     * next_true/next_false, 0 for AgentTransferNode (NodeResult::ended() --
     * there is nowhere after it in the graph).
     *
     * @var array<string, array{label: string, outputs: int, fields: array<int, array{key: string, label: string, type: string, cast: string, placeholder?: string, default?: string, options?: array<string, string>}>}>
     */
    public const NODE_TYPES = [
        'send_message' => [
            'label' => 'Send Message',
            'outputs' => 1,
            'fields' => [
                ['key' => 'body', 'label' => 'Message', 'type' => 'textarea', 'cast' => 'string', 'placeholder' => 'Text to send'],
            ],
        ],
        'send_template' => [
            'label' => 'Send Template',
            'outputs' => 1,
            'fields' => [
                ['key' => 'template', 'label' => 'Template name', 'type' => 'text', 'cast' => 'string'],
                ['key' => 'language', 'label' => 'Language', 'type' => 'text', 'cast' => 'string', 'placeholder' => 'en_US', 'default' => 'en_US'],
                ['key' => 'body_parameters', 'label' => 'Parameters (comma-separated)', 'type' => 'text', 'cast' => 'csv_array', 'placeholder' => '{{1}}, {{2}}, ...'],
            ],
        ],
        'condition' => [
            'label' => 'Condition',
            'outputs' => 2,
            'fields' => [
                ['key' => 'variable', 'label' => 'Variable (dot path)', 'type' => 'text', 'cast' => 'string', 'placeholder' => 'message.text'],
                ['key' => 'operator', 'label' => 'Operator', 'type' => 'select', 'cast' => 'string', 'default' => 'equals', 'options' => ['equals' => 'Equals', 'contains' => 'Contains', 'exists' => 'Exists']],
                ['key' => 'value', 'label' => 'Value', 'type' => 'text', 'cast' => 'string'],
            ],
        ],
        'delay' => [
            'label' => 'Delay',
            'outputs' => 1,
            'fields' => [
                ['key' => 'seconds', 'label' => 'Wait (seconds)', 'type' => 'number', 'cast' => 'int', 'default' => '60'],
            ],
        ],
        'set_label' => [
            'label' => 'Set Label',
            'outputs' => 1,
            'fields' => [
                ['key' => 'label', 'label' => 'Label name', 'type' => 'text', 'cast' => 'string'],
            ],
        ],
        'agent_transfer' => [
            'label' => 'Agent Transfer',
            'outputs' => 0,
            'fields' => [
                ['key' => 'user_id', 'label' => 'Assign to', 'type' => 'select-users', 'cast' => 'nullable_int'],
            ],
        ],
        'make_request' => [
            'label' => 'Make Request',
            'outputs' => 1,
            'fields' => [
                ['key' => 'url', 'label' => 'URL', 'type' => 'text', 'cast' => 'string', 'placeholder' => 'https://example.com/webhook'],
                ['key' => 'payload', 'label' => 'Payload (JSON)', 'type' => 'textarea', 'cast' => 'json_object', 'placeholder' => '{}'],
            ],
        ],
    ];

    /**
     * Drawflow's export() shape -> FlowEngine's `graph` column shape.
     *
     * `start_node_id` is read off whichever node's own `data.is_start` is
     * true (see resources/js/whatsapp-flow-builder.js's "Set as start"
     * handler) -- not a separate field the form posts, so a plain
     * `editor.export()` is self-describing and the client never has to
     * carry that piece of state anywhere else.
     *
     * A node whose `type` is not one of NODE_TYPES is skipped rather than
     * rejected outright -- defensive against a `graph` hand-edited or
     * produced by a different build of the palette than this one.
     *
     * @param  array<string, mixed>  $drawflowExport
     * @return array{start_node_id: ?string, nodes: array<string, array<string, mixed>>}
     *
     * @throws RuntimeException if a node's own field value cannot be cast
     *                          (currently: make_request's `payload` is not
     *                          valid JSON).
     */
    public static function toEngineGraph(array $drawflowExport): array
    {
        $home = $drawflowExport['drawflow']['Home']['data'] ?? [];
        $home = is_array($home) ? $home : [];

        $nodes = [];
        $startNodeId = null;

        foreach ($home as $id => $node) {
            $id = (string) $id;
            $node = is_array($node) ? $node : [];
            $nodeData = is_array($node['data'] ?? null) ? $node['data'] : [];
            $type = $nodeData['type'] ?? null;
            $def = self::NODE_TYPES[$type] ?? null;

            if ($def === null) {
                continue;
            }

            $cfg = ['type' => $type];

            foreach ($def['fields'] as $field) {
                $cfg[$field['key']] = self::cast($field['cast'], $nodeData[$field['key']] ?? null, $def['label']);
            }

            if ($type === 'condition') {
                $cfg['next_true'] = self::firstConnectionTarget($node['outputs']['output_1'] ?? null);
                $cfg['next_false'] = self::firstConnectionTarget($node['outputs']['output_2'] ?? null);
            } elseif ($def['outputs'] > 0) {
                $cfg['next'] = self::firstConnectionTarget($node['outputs']['output_1'] ?? null);
            }

            // UI-only metadata FlowEngine never reads (see NodeHandler's own
            // docblock -- a handler reads its `type` plus "whatever that
            // type needs", nothing stops it ignoring an extra key). Kept
            // purely so reopening this flow later restores the canvas
            // layout instead of re-flowing every node to a default grid.
            $cfg['_pos'] = ['x' => $node['pos_x'] ?? 0, 'y' => $node['pos_y'] ?? 0];

            if (! empty($nodeData['is_start'])) {
                $startNodeId = $id;
            }

            $nodes[$id] = $cfg;
        }

        self::assertNoDanglingPointers($nodes);

        return ['start_node_id' => $startNodeId, 'nodes' => $nodes];
    }

    /**
     * A node whose type isn't in NODE_TYPES is dropped above (see this
     * method's caller) -- fine on its own, but a *different* node's
     * `next`/`next_true`/`next_false` may have pointed at exactly that now-
     * missing id. Left unchecked, the flow "saves successfully" and then
     * fails at runtime, deep inside FlowEngine, with "Flow graph has no
     * node 'x'" -- a much worse place for an admin to discover it than a
     * validation error on the save they just made. This runs as a second
     * pass over the *final* $nodes map (not interleaved with the loop that
     * builds it) precisely because a pointer can name a node the loop
     * hasn't reached yet -- Drawflow's own `data` map has no guaranteed
     * ordering to rely on.
     *
     * @param  array<string, array<string, mixed>>  $nodes
     *
     * @throws RuntimeException
     */
    private static function assertNoDanglingPointers(array $nodes): void
    {
        foreach ($nodes as $cfg) {
            foreach (['next', 'next_true', 'next_false'] as $pointerKey) {
                if (! array_key_exists($pointerKey, $cfg)) {
                    continue;
                }

                $target = $cfg[$pointerKey];

                if ($target !== null && ! array_key_exists($target, $nodes)) {
                    throw new RuntimeException(
                        "A \"{$cfg['type']}\" node connects to a node that can't be saved (it may be a node type this build of the editor doesn't recognise). Remove or reconnect that link before saving."
                    );
                }
            }
        }
    }

    /**
     * The reverse translation: rebuilds a Drawflow-importable
     * `{drawflow: {Home: {data: {...}}}}` structure from a stored `graph`,
     * used both for opening an existing flow for editing and for restoring
     * a failed submission's in-progress canvas (WhatsappFlowController
     * hands old('graph') -- the exact raw Drawflow JSON the browser just
     * posted -- straight back rather than calling this at all in that
     * case; see flows/edit.blade.php).
     *
     * Drawflow tracks connections in both directions (a node's `outputs`
     * AND the target's `inputs`); FlowEngine's shape only ever stores the
     * outgoing `next`/`next_true`/`next_false` pointer, so every node's
     * incoming edges are derived here by scanning every other node for one
     * pointing at it.
     *
     * A node whose type is not in NODE_TYPES is skipped (and cannot be the
     * target of a reconstructed connection either) for the same reason
     * toEngineGraph() skips one -- rendering an unopenable flow is worse
     * than silently dropping a node this build of the editor cannot
     * present.
     *
     * @param  array{start_node_id: mixed, nodes: array<string, array<string, mixed>>}  $graph
     * @param  iterable<int, array{id: int, name: string}>  $users
     * @return array<string, mixed>
     */
    public static function toDrawflowExport(array $graph, iterable $users = []): array
    {
        $allNodes = is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [];
        $startNodeId = isset($graph['start_node_id']) ? (string) $graph['start_node_id'] : null;
        $users = is_array($users) ? $users : iterator_to_array($users);

        $nodes = [];
        foreach ($allNodes as $id => $cfg) {
            $id = (string) $id;
            $cfg = is_array($cfg) ? $cfg : [];

            if (isset(self::NODE_TYPES[$cfg['type'] ?? null])) {
                $nodes[$id] = $cfg;
            }
        }

        $incoming = array_fill_keys(array_keys($nodes), []);

        foreach ($nodes as $id => $cfg) {
            // PHP silently coerces an array key that looks like a canonical
            // decimal integer ("1") to a real int the moment it is used as
            // a key -- $nodes["1"] really is stored under int key 1, so the
            // foreach above hands back $id as an int even though every node
            // id everywhere else in this class (and in FlowEngine's own
            // `graph` shape) is a string. Recast immediately: every use of
            // $id below is as a *value* (a `node`/`is_start` comparison
            // target), where that distinction matters -- unlike a plain
            // array-key lookup, which coerces the same way on both sides
            // and so never actually saw this bug.
            $id = (string) $id;

            // Drawflow's own addConnection() (node_modules/drawflow/dist/
            // drawflow.min.js) pushes a DIFFERENT key name onto each side of
            // a connection: the output side's connections carry `output`
            // (which output slot on that end), the input side's carry
            // `input` (which output slot on the OTHER end it came from) --
            // confirmed against both addConnection() itself and the
            // README's own "Export example", which shows
            // `"input": "output_1"` inside an `inputs` block. This is the
            // `inputs` side being built here, so the key must be `input`,
            // not `output` -- addNodeImport() reads this exact key to build
            // the connection's CSS class (`.node_out_node-X.<key value>`);
            // the wrong key name renders a class of the literal string
            // "undefined" and updateConnectionNodes() then throws trying to
            // resolve a dot that was never given a real class, aborting
            // editor.import() entirely for any flow with a saved connection.
            if (($cfg['type'] ?? null) === 'condition') {
                if (filled($cfg['next_true'] ?? null) && isset($incoming[(string) $cfg['next_true']])) {
                    $incoming[(string) $cfg['next_true']][] = ['node' => $id, 'input' => 'output_1'];
                }
                if (filled($cfg['next_false'] ?? null) && isset($incoming[(string) $cfg['next_false']])) {
                    $incoming[(string) $cfg['next_false']][] = ['node' => $id, 'input' => 'output_2'];
                }
            } elseif (filled($cfg['next'] ?? null) && isset($incoming[(string) $cfg['next']])) {
                $incoming[(string) $cfg['next']][] = ['node' => $id, 'input' => 'output_1'];
            }
        }

        $data = [];
        $index = 0;

        foreach ($nodes as $id => $cfg) {
            $id = (string) $id; // see the identical cast + comment in the loop above
            $type = $cfg['type'];
            $def = self::NODE_TYPES[$type];

            $nodeData = ['type' => $type, 'is_start' => $startNodeId !== null && $id === $startNodeId];

            foreach ($def['fields'] as $field) {
                $nodeData[$field['key']] = self::uncast($field['cast'], $cfg[$field['key']] ?? null);
            }

            $outputs = [];
            if ($type === 'condition') {
                $outputs['output_1'] = ['connections' => filled($cfg['next_true'] ?? null) ? [['node' => (string) $cfg['next_true'], 'output' => 'input_1']] : []];
                $outputs['output_2'] = ['connections' => filled($cfg['next_false'] ?? null) ? [['node' => (string) $cfg['next_false'], 'output' => 'input_1']] : []];
            } elseif ($def['outputs'] > 0) {
                $outputs['output_1'] = ['connections' => filled($cfg['next'] ?? null) ? [['node' => (string) $cfg['next'], 'output' => 'input_1']] : []];
            }

            // Every node type this editor produces takes exactly one input
            // -- none of NODE_TYPES' entries describe a second inbound
            // edge, so this is unconditional rather than driven off `$def`.
            $inputs = ['input_1' => ['connections' => $incoming[$id]]];

            $pos = is_array($cfg['_pos'] ?? null) ? $cfg['_pos'] : [];
            $numericId = ctype_digit($id) ? (int) $id : $id;

            $data[$id] = [
                'id' => $numericId,
                'name' => $type,
                'data' => $nodeData,
                'class' => $type,
                'html' => self::nodeHtml($type, $users),
                'typenode' => false,
                'inputs' => $inputs,
                'outputs' => $outputs,
                'pos_x' => is_numeric($pos['x'] ?? null) ? (float) $pos['x'] : 80 + ($index % 4) * 260,
                'pos_y' => is_numeric($pos['y'] ?? null) ? (float) $pos['y'] : 80 + intdiv($index, 4) * 180,
            ];

            $index++;
        }

        return ['drawflow' => ['Home' => ['data' => $data]]];
    }

    /**
     * The literal HTML Drawflow stores as a node's `html` (typenode=false,
     * so it is injected as-is via innerHTML on both addNode() and import())
     * -- kept in step by hand with resources/js/whatsapp-flow-builder.js's
     * own buildNodeHtml(), which renders a freshly-dragged-in node the same
     * way before this class ever sees it. Both read from the same NODE_TYPES
     * shape (this one lives in PHP, the JS file carries its own literal
     * copy of the same field list -- there is no way to share source
     * between the two languages here, so the docblocks on both sides point
     * at each other as the thing to keep in sync).
     *
     * @param  array<int, array{id: int, name: string}>  $users
     */
    private static function nodeHtml(string $type, array $users): string
    {
        $def = self::NODE_TYPES[$type];
        $fields = collect($def['fields'])->map(fn (array $field) => self::fieldHtml($field, $users))->implode('');

        $hint = match (true) {
            $type === 'condition' => '<p class="flow-node__hint">Top output = True, bottom output = False.</p>',
            $def['outputs'] === 0 => '<p class="flow-node__hint">Ends the flow (hands off to a human).</p>',
            default => '',
        };

        return '<div class="flow-node">'
            .'<div class="flow-node__header">'
            .'<span class="flow-node__title">'.e($def['label']).'</span>'
            .'<span class="flow-node__start-pill">Start</span>'
            .'</div>'
            .'<div class="flow-node__body">'.$fields.$hint
            .'<button type="button" class="flow-node__set-start" data-set-start>Set as start</button>'
            .'</div>'
            .'</div>';
    }

    /**
     * @param  array{key: string, label: string, type: string, placeholder?: string, options?: array<string, string>}  $field
     * @param  array<int, array{id: int, name: string}>  $users
     */
    private static function fieldHtml(array $field, array $users): string
    {
        $label = e($field['label']);
        $placeholder = e($field['placeholder'] ?? '');

        if ($field['type'] === 'textarea') {
            return "<div class=\"flow-node__field\"><label>{$label}</label>"
                ."<textarea df-{$field['key']} rows=\"2\" placeholder=\"{$placeholder}\"></textarea></div>";
        }

        if ($field['type'] === 'select') {
            $options = collect($field['options'] ?? [])
                ->map(fn (string $optLabel, string $value) => '<option value="'.e($value).'">'.e($optLabel).'</option>')
                ->implode('');

            return "<div class=\"flow-node__field\"><label>{$label}</label><select df-{$field['key']}>{$options}</select></div>";
        }

        if ($field['type'] === 'select-users') {
            $options = '<option value="">— Unassigned —</option>'
                .collect($users)->map(fn (array $user) => '<option value="'.e((string) $user['id']).'">'.e($user['name']).'</option>')->implode('');

            return "<div class=\"flow-node__field\"><label>{$label}</label><select df-{$field['key']}>{$options}</select></div>";
        }

        $inputType = $field['type'] === 'number' ? 'number' : 'text';

        return "<div class=\"flow-node__field\"><label>{$label}</label>"
            ."<input type=\"{$inputType}\" df-{$field['key']} placeholder=\"{$placeholder}\"></div>";
    }

    private static function firstConnectionTarget(?array $output): ?string
    {
        $connection = $output['connections'][0] ?? null;

        return isset($connection['node']) ? (string) $connection['node'] : null;
    }

    /**
     * @throws RuntimeException
     */
    private static function cast(string $type, mixed $value, string $nodeLabel): mixed
    {
        return match ($type) {
            'csv_array' => collect(explode(',', (string) $value))->map(fn ($v) => trim($v))->filter(fn ($v) => $v !== '')->values()->all(),
            'json_object' => self::castJsonObject($value, $nodeLabel),
            'int' => (int) $value,
            'nullable_int' => $value === '' || $value === null ? null : (int) $value,
            default => (string) ($value ?? ''),
        };
    }

    /**
     * @throws RuntimeException
     */
    private static function castJsonObject(mixed $value, string $nodeLabel): array
    {
        $text = trim((string) ($value ?? ''));

        if ($text === '') {
            return [];
        }

        $decoded = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            throw new RuntimeException("\"{$nodeLabel}\" node: Payload must be valid JSON.");
        }

        return $decoded;
    }

    private static function uncast(string $type, mixed $value): string
    {
        return match ($type) {
            'csv_array' => implode(', ', is_array($value) ? $value : []),
            'json_object' => (is_array($value) && $value !== []) ? json_encode($value) : '',
            default => (string) ($value ?? ''),
        };
    }
}
