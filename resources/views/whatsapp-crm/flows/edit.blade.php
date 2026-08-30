@php
    use App\Services\WhatsappFlow\DrawflowGraphTranslator;

    $selectedTrigger = old('trigger_type', $flow->trigger_type ?? 'inbound_message');

    $usersForNodes = $assignableUsers->map(fn ($user) => ['id' => $user->id, 'name' => $user->name])->values()->all();

    // On a fresh GET, rebuild Drawflow's own import shape from the stored
    // (FlowEngine-shaped) graph -- see DrawflowGraphTranslator. On the
    // redirect-back from a failed store()/update(), old('graph') already
    // holds the exact raw Drawflow JSON the browser just posted (nothing
    // about it has been translated yet, since validation failed before
    // that), so the canvas the user was just editing is restored exactly
    // rather than reverting to whatever was last saved.
    $initialDrawflow = null;

    if (old('graph')) {
        $decoded = json_decode(old('graph'), true);
        $initialDrawflow = is_array($decoded) ? $decoded : null;
    }

    if ($initialDrawflow === null) {
        $initialDrawflow = DrawflowGraphTranslator::toDrawflowExport(
            $flow->graph ?: ['start_node_id' => null, 'nodes' => []],
            $usersForNodes
        );
    }
@endphp

<x-app-layout :title="$flow->exists ? $flow->name : 'New Automation'">
    <x-slot name="header">
        <x-page-header :title="$flow->exists ? $flow->name : 'New Automation'" eyebrow="WhatsApp CRM"
                       subtitle="Drag nodes onto the canvas, connect them, then mark one node as the start.">
            <x-slot name="actions">
                <x-btn :href="route('whatsapp-crm.flows.index')" variant="secondary" size="sm">Back to automations</x-btn>
            </x-slot>
        </x-page-header>
    </x-slot>

    <form method="POST"
          action="{{ $flow->exists ? route('whatsapp-crm.flows.update', $flow) : route('whatsapp-crm.flows.store') }}"
          id="flow-form" class="space-y-4">
        @csrf
        @if ($flow->exists)
            @method('PUT')
        @endif
        {{-- Filled with editor.export() -- Drawflow's own raw JSON, unmodified
             -- just before submit. WhatsappFlowController::validated() runs
             it through DrawflowGraphTranslator before anything is stored. --}}
        <input type="hidden" name="graph" id="flow-graph-input" value="">

        <x-card class="p-4 sm:p-6">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <x-input-label for="name" value="Name" />
                    <x-text-input id="name" name="name" type="text" class="mt-1" required autofocus
                        value="{{ old('name', $flow->name ?? '') }}" placeholder="e.g. Welcome greeting" />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="trigger_type" value="Trigger" />
                    <x-select id="trigger_type" name="trigger_type" class="mt-1">
                        @foreach (['inbound_message' => 'Any inbound message (catch-all)', 'keyword' => 'Keyword match', 'label_applied' => 'Label applied'] as $value => $label)
                            <option value="{{ $value }}" @selected($selectedTrigger === $value)>{{ $label }}</option>
                        @endforeach
                    </x-select>
                    <x-input-error :messages="$errors->get('trigger_type')" class="mt-2" />
                    {{-- Rendered on every load (not just when selected) and
                         toggled live by whatsapp-flow-builder.js's own
                         trigger_type change listener, the same way
                         #trigger-keyword-field is -- picking "Label
                         applied" from the dropdown must show this
                         immediately, not only after a save round-trip. --}}
                    <p id="trigger-label-applied-warning" class="mt-1 text-xs text-amber-300 {{ $selectedTrigger === 'label_applied' ? '' : 'hidden' }}">
                        Not wired up yet -- a flow with this trigger will never start on its own.
                    </p>
                </div>
                <div id="trigger-keyword-field" class="{{ $selectedTrigger === 'keyword' ? '' : 'hidden' }}">
                    <x-input-label for="trigger_config_keyword" value="Keyword" />
                    <x-text-input id="trigger_config_keyword" name="trigger_config[keyword]" type="text" class="mt-1"
                        value="{{ old('trigger_config.keyword', $flow->trigger_config['keyword'] ?? '') }}" placeholder="e.g. price" />
                    <x-input-error :messages="$errors->get('trigger_config.keyword')" class="mt-2" />
                </div>
            </div>
        </x-card>

        <x-input-error :messages="$errors->get('graph')" />

        <div class="grid grid-cols-1 lg:grid-cols-[220px_1fr] gap-4">
            <div>
                <x-card class="p-3">
                    <p class="text-xs font-semibold uppercase tracking-wider text-brand-100/60 mb-2">Drag onto canvas</p>
                    {{-- Populated by whatsapp-flow-builder.js from its own
                         NODE_TYPES list -- one source of truth for the 7
                         node types, not a second copy here. --}}
                    <div id="flow-palette"></div>
                </x-card>
                <x-card class="p-3 mt-4 space-y-1">
                    <p class="text-xs font-semibold uppercase tracking-wider text-brand-100/60 mb-1">Canvas</p>
                    <button type="button" id="flow-zoom-in" class="w-full text-sm text-left text-brand-100/80 hover:text-white py-1">Zoom in</button>
                    <button type="button" id="flow-zoom-out" class="w-full text-sm text-left text-brand-100/80 hover:text-white py-1">Zoom out</button>
                    <button type="button" id="flow-delete-node" class="w-full text-sm text-left text-red-300 hover:text-red-200 py-1">Delete selected node</button>
                </x-card>
                <p class="text-xs text-brand-100/50 mt-3 leading-snug">
                    Connect a node's right-edge dot to another node's left-edge dot to chain them. Every node carries a
                    "Set as start" link -- exactly one must be marked before this flow can be activated.
                </p>
            </div>
            <div class="flow-canvas-shell">
                <div id="flow-drawflow"></div>
            </div>
        </div>

        <div class="flex items-center gap-4">
            <x-primary-button type="submit">Save Flow</x-primary-button>
            <a href="{{ route('whatsapp-crm.flows.index') }}" class="text-sm text-brand-100/70 hover:text-white">Cancel</a>
        </div>
    </form>

    <script>
        // `graph` here is already Drawflow's own import()-shaped JSON
        // (see the @php block above) -- resources/js/whatsapp-flow-builder.js
        // hands it straight to editor.import(), no translation on this side.
        window.__flowBuilderConfig = @json([
            'graph' => $initialDrawflow,
            'users' => $usersForNodes,
        ]);
    </script>

    @vite(['resources/js/whatsapp-flow-builder.js'])
</x-app-layout>
