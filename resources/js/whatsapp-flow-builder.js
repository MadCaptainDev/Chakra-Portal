/**
 * The visual flow builder: mounts Drawflow on flows/edit.blade.php.
 *
 * This file does no shape translation of its own -- it only ever calls
 * Drawflow's own export()/import(), exactly the way the README documents
 * them. Reconciling Drawflow's shape with what FlowEngine actually reads
 * out of a flow's `graph` column happens entirely on the server, in
 * App\Services\WhatsappFlow\DrawflowGraphTranslator (see that class's
 * docblock for the two shapes, confirmed against
 * node_modules/drawflow/README.md's "Export example" after installing it,
 * not guessed).
 *
 * NODE_TYPES below is this file's own copy of the same field list
 * DrawflowGraphTranslator::NODE_TYPES carries in PHP -- there is no way to
 * share source between the two languages here, so a change to one node
 * type's fields has to be made in both places; each file's docblock points
 * at the other as the thing to keep in sync. This copy only drives the
 * palette and the HTML of a freshly-added node; a *reopened* flow's nodes
 * arrive with their HTML already rendered server-side (see
 * DrawflowGraphTranslator::nodeHtml()), so this file never needs to render
 * a node from stored data, only from a user's own drag-and-drop.
 */
import Drawflow from 'drawflow';
import 'drawflow/dist/drawflow.min.css';
import '../css/whatsapp-flow-builder.css';

const NODE_TYPES = {
    send_message: {
        label: 'Send Message',
        inputs: 1,
        outputs: 1,
        fields: [
            { key: 'body', label: 'Message', type: 'textarea', placeholder: 'Text to send', default: '' },
        ],
    },
    send_template: {
        label: 'Send Template',
        inputs: 1,
        outputs: 1,
        fields: [
            { key: 'template', label: 'Template name', type: 'text', default: '' },
            { key: 'language', label: 'Language', type: 'text', placeholder: 'en_US', default: 'en_US' },
            { key: 'body_parameters', label: 'Parameters (comma-separated)', type: 'text', placeholder: '{{1}}, {{2}}, ...', default: '' },
        ],
    },
    send_list: {
        label: 'Send List',
        inputs: 1,
        outputs: 1,
        fields: [
            { key: 'body', label: 'Message', type: 'textarea', placeholder: 'What can we help with?', default: '' },
            { key: 'rows', label: 'Options (one per line: id|Title|Description)', type: 'textarea', placeholder: '1|Invoices|Your recent bills', default: '' },
            { key: 'button', label: 'Button label', type: 'text', placeholder: 'Select Option', default: 'Select Option' },
            { key: 'header', label: 'Header (optional)', type: 'text', default: '' },
            { key: 'footer', label: 'Footer (optional)', type: 'text', default: '' },
        ],
    },
    condition: {
        label: 'Condition',
        inputs: 1,
        outputs: 2,
        fields: [
            { key: 'variable', label: 'Variable (dot path)', type: 'text', placeholder: 'message.text', default: '' },
            {
                key: 'operator',
                label: 'Operator',
                type: 'select',
                options: [['equals', 'Equals'], ['contains', 'Contains'], ['exists', 'Exists']],
                default: 'equals',
            },
            { key: 'value', label: 'Value', type: 'text', default: '' },
        ],
    },
    delay: {
        label: 'Delay',
        inputs: 1,
        outputs: 1,
        fields: [
            { key: 'seconds', label: 'Wait (seconds)', type: 'number', default: '60' },
        ],
    },
    set_label: {
        label: 'Set Label',
        inputs: 1,
        outputs: 1,
        fields: [
            { key: 'label', label: 'Label name', type: 'text', default: '' },
        ],
    },
    agent_transfer: {
        label: 'Agent Transfer',
        inputs: 1,
        outputs: 0,
        fields: [
            { key: 'user_id', label: 'Assign to', type: 'select-users', default: '' },
        ],
    },
    make_request: {
        label: 'Make Request',
        inputs: 1,
        outputs: 1,
        fields: [
            { key: 'url', label: 'URL', type: 'text', placeholder: 'https://example.com/webhook', default: '' },
            { key: 'payload', label: 'Payload (JSON)', type: 'textarea', placeholder: '{}', default: '' },
        ],
    },
    client_action: {
        label: 'Client Action',
        inputs: 1,
        outputs: 1,
        fields: [
            {
                key: 'action',
                label: 'Send to client',
                type: 'select',
                options: [
                    ['invoices', 'Invoices'],
                    ['monthly_report', 'Monthly report'],
                    ['upcoming_shoots', 'Upcoming shoots'],
                ],
                default: 'invoices',
            },
        ],
    },
};

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
}

function fieldHtml(field, users) {
    if (field.type === 'textarea') {
        return `<div class="flow-node__field"><label>${escapeHtml(field.label)}</label>`
            + `<textarea df-${field.key} rows="2" placeholder="${escapeHtml(field.placeholder ?? '')}"></textarea></div>`;
    }

    if (field.type === 'select') {
        const options = field.options.map(([value, label]) => `<option value="${escapeHtml(value)}">${escapeHtml(label)}</option>`).join('');

        return `<div class="flow-node__field"><label>${escapeHtml(field.label)}</label><select df-${field.key}>${options}</select></div>`;
    }

    if (field.type === 'select-users') {
        const options = ['<option value="">— Unassigned —</option>']
            .concat(users.map((user) => `<option value="${user.id}">${escapeHtml(user.name)}</option>`))
            .join('');

        return `<div class="flow-node__field"><label>${escapeHtml(field.label)}</label><select df-${field.key}>${options}</select></div>`;
    }

    const inputType = field.type === 'number' ? 'number' : 'text';

    return `<div class="flow-node__field"><label>${escapeHtml(field.label)}</label>`
        + `<input type="${inputType}" df-${field.key} placeholder="${escapeHtml(field.placeholder ?? '')}"></div>`;
}

function buildNodeHtml(typeKey, users) {
    const def = NODE_TYPES[typeKey];
    const fields = def.fields.map((field) => fieldHtml(field, users)).join('');
    const hint = typeKey === 'condition'
        ? '<p class="flow-node__hint">Top output = True, bottom output = False.</p>'
        : typeKey === 'send_list'
            ? '<p class="flow-node__hint">Leave the output unconnected -- a tap starts a new message, routed by a Condition node on message.choice. Free-form send: only works within 24h of their last message.</p>'
            : (def.outputs === 0 ? '<p class="flow-node__hint">Ends the flow (hands off to a human).</p>' : '');

    return `<div class="flow-node">`
        + `<div class="flow-node__header">`
        + `<span class="flow-node__title">${escapeHtml(def.label)}</span>`
        + `<span class="flow-node__start-pill">Start</span>`
        + `</div>`
        + `<div class="flow-node__body">${fields}${hint}`
        + `<button type="button" class="flow-node__set-start" data-set-start>Set as start</button>`
        + `</div>`
        + `</div>`;
}

function defaultNodeData(typeKey) {
    const def = NODE_TYPES[typeKey];
    const data = { type: typeKey, is_start: false };
    def.fields.forEach((field) => {
        data[field.key] = field.default ?? '';
    });

    return data;
}

/**
 * Applies the `.is-start-node` CSS marker (the ring + "Start" pill) to
 * whichever single node in the DOM has `data.is_start === true` right now
 * -- called once right after an import() (which rebuilds the DOM with no
 * memory of this file's own CSS classes) and again every time "Set as
 * start" changes which node that is.
 */
function refreshStartMarker(editor, container) {
    container.querySelectorAll('.drawflow-node.is-start-node').forEach((el) => el.classList.remove('is-start-node'));

    const home = editor.export().drawflow.Home.data;
    Object.entries(home).forEach(([id, node]) => {
        if (node.data && node.data.is_start) {
            document.getElementById(`node-${id}`)?.classList.add('is-start-node');
        }
    });
}

function init() {
    const container = document.getElementById('flow-drawflow');

    if (! container) {
        return;
    }

    const config = window.__flowBuilderConfig || { graph: { drawflow: { Home: { data: {} } } }, users: [] };
    const users = Array.isArray(config.users) ? config.users : [];
    const form = document.getElementById('flow-form');
    const graphInput = document.getElementById('flow-graph-input');

    const editor = new Drawflow(container);
    editor.reroute = true;
    editor.draggable_inputs = false;
    editor.start();

    const hasExistingNodes = Object.keys(config.graph?.drawflow?.Home?.data ?? {}).length > 0;

    if (hasExistingNodes) {
        editor.import(config.graph);
        refreshStartMarker(editor, container);
    }

    // "Set as start" is delegated on the canvas rather than bound per-node
    // -- the same handler then works for nodes rebuilt by import() and for
    // nodes freshly dragged in from the palette alike. Only one node may be
    // the start: every other node's own `is_start` is explicitly cleared
    // here rather than merely left unset, so a stale `true` from an earlier
    // save can never linger once a different node is marked.
    container.addEventListener('click', (event) => {
        const button = event.target.closest('[data-set-start]');

        if (! button) {
            return;
        }

        const nodeEl = button.closest('.drawflow-node');

        if (! nodeEl) {
            return;
        }

        const clickedId = nodeEl.id.replace('node-', '');
        const home = editor.export().drawflow.Home.data;

        Object.keys(home).forEach((id) => {
            const current = editor.getNodeFromId(id).data;
            const shouldBeStart = id === clickedId;

            if (!! current.is_start !== shouldBeStart) {
                editor.updateNodeDataFromId(id, { ...current, is_start: shouldBeStart });
            }
        });

        refreshStartMarker(editor, container);
    });

    // Palette: one draggable chip per node type, built from NODE_TYPES so
    // there is exactly one list of the 7 types in this file.
    const palette = document.getElementById('flow-palette');

    function addNode(typeKey, x, y) {
        const def = NODE_TYPES[typeKey];

        if (! def) {
            return;
        }

        editor.addNode(typeKey, def.inputs, def.outputs, x, y, typeKey, defaultNodeData(typeKey), buildNodeHtml(typeKey, users), false);
    }

    if (palette) {
        Object.entries(NODE_TYPES).forEach(([typeKey, def]) => {
            const chip = document.createElement('div');
            chip.className = `flow-palette-item ${typeKey}`;
            chip.textContent = def.label;
            chip.draggable = true;
            chip.dataset.nodeType = typeKey;

            chip.addEventListener('dragstart', (event) => {
                event.dataTransfer.setData('application/drawflow-node-type', typeKey);
            });

            // Click-to-add: the only way to place a node on a touch device,
            // where HTML5 drag-and-drop does not fire.
            chip.addEventListener('click', () => addNode(typeKey, 120 + Math.random() * 160, 120 + Math.random() * 160));

            palette.appendChild(chip);
        });
    }

    container.addEventListener('dragover', (event) => event.preventDefault());
    container.addEventListener('drop', (event) => {
        event.preventDefault();
        const typeKey = event.dataTransfer.getData('application/drawflow-node-type');

        if (! NODE_TYPES[typeKey]) {
            return;
        }

        // Screen coordinates -> canvas coordinates, accounting for zoom and
        // the canvas's own scroll/translate offset -- Drawflow's documented
        // addNode() only ever takes canvas-space positions, so a drop at a
        // raw clientX/clientY would land the node in the wrong place the
        // moment the canvas is panned or zoomed away from its start state.
        const rect = editor.precanvas.getBoundingClientRect();
        const x = (event.clientX - rect.left) / editor.zoom;
        const y = (event.clientY - rect.top) / editor.zoom;

        addNode(typeKey, x, y);
    });

    document.getElementById('flow-zoom-in')?.addEventListener('click', () => editor.zoom_in());
    document.getElementById('flow-zoom-out')?.addEventListener('click', () => editor.zoom_out());
    document.getElementById('flow-delete-node')?.addEventListener('click', () => {
        // removeNodeId() (unlike Drawflow's own Delete-key handler) does not
        // clear node_selected itself, so this does it here -- otherwise a
        // second click would try to remove a node id that is already gone.
        if (editor.node_selected) {
            editor.removeNodeId(editor.node_selected.id);
            editor.node_selected = null;
        }
    });

    const triggerType = document.getElementById('trigger_type');
    const keywordField = document.getElementById('trigger-keyword-field');
    const labelAppliedWarning = document.getElementById('trigger-label-applied-warning');

    triggerType?.addEventListener('change', () => {
        keywordField?.classList.toggle('hidden', triggerType.value !== 'keyword');
        labelAppliedWarning?.classList.toggle('hidden', triggerType.value !== 'label_applied');
        document.getElementById('trigger-client-portal-hint')?.classList.toggle('hidden', triggerType.value !== 'client_portal');
    });

    form?.addEventListener('submit', (event) => {
        const home = editor.export().drawflow.Home.data;
        const hasStart = Object.values(home).some((node) => node.data && node.data.is_start);

        if (Object.keys(home).length > 0 && ! hasStart) {
            event.preventDefault();
            alert('Mark one node as the start of the flow before saving.');

            return;
        }

        graphInput.value = JSON.stringify(editor.export());
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
