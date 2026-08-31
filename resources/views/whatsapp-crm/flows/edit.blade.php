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

{{--
    Full-bleed editor (see EditorLayout's own doc block for why this is a
    sibling layout rather than an <x-app-layout> option): the canvas is the
    page, so the sidebar and the max-w-7xl document column that every other
    screen gets both had to go. Everything that used to live in the header
    slot and the card above the canvas now lives in one compact top bar.
--}}
<x-editor-layout :title="$flow->exists ? $flow->name : 'New Automation'">
    <form method="POST"
          action="{{ $flow->exists ? route('whatsapp-crm.flows.update', $flow) : route('whatsapp-crm.flows.store') }}"
          id="flow-form" class="flex flex-col h-full min-h-0"
          x-data="{ palette: window.innerWidth >= 1024 }">
        @csrf
        @if ($flow->exists)
            @method('PUT')
        @endif
        {{-- Filled with editor.export() -- Drawflow's own raw JSON, unmodified
             -- just before submit. WhatsappFlowController::validated() runs
             it through DrawflowGraphTranslator before anything is stored. --}}
        <input type="hidden" name="graph" id="flow-graph-input" value="">

        {{-- Top bar: everything that isn't the canvas, in one row so the
             canvas keeps the rest of the viewport. --}}
        <header class="shrink-0 flex flex-wrap items-center gap-3 h-auto min-h-14 px-3 py-2 border-b border-white/10 bg-brand-900">
            <a href="{{ route('whatsapp-crm.flows.index') }}"
               class="shrink-0 inline-flex items-center justify-center w-9 h-9 rounded-lg text-brand-100/70 hover:bg-white/10 hover:text-white transition"
               aria-label="Back to automations" title="Back to automations">
                <x-icon name="chevron-left" class="w-5 h-5" />
            </a>

            <button type="button" @click="palette = ! palette"
                    class="shrink-0 inline-flex items-center justify-center w-9 h-9 rounded-lg text-brand-100/70 hover:bg-white/10 hover:text-white transition"
                    :aria-pressed="palette" aria-label="Toggle node palette" title="Toggle node palette">
                <x-icon name="grip" class="w-5 h-5" />
            </button>

            <div class="w-full sm:w-56 shrink-0">
                <x-text-input id="name" name="name" type="text" class="!min-h-[38px] !bg-white/[0.03]" required autofocus
                    value="{{ old('name', $flow->name ?? '') }}" placeholder="Automation name" />
            </div>

            <div class="w-full sm:w-64 shrink-0">
                <x-select id="trigger_type" name="trigger_type" class="!min-h-[38px] !bg-white/[0.03]">
                    @foreach ([
                        'client_portal' => 'Activated client number (self-service menu)',
                        'inbound_message' => 'Any inbound message (catch-all)',
                        'keyword' => 'Keyword match',
                        'label_applied' => 'Label applied',
                    ] as $value => $label)
                        <option value="{{ $value }}" @selected($selectedTrigger === $value)>{{ $label }}</option>
                    @endforeach
                </x-select>
            </div>

            {{-- Rendered on every load (not just when selected) and toggled
                 live by whatsapp-flow-builder.js's own trigger_type change
                 listener -- ids preserved exactly for that listener to keep
                 working unchanged. --}}
            <div id="trigger-keyword-field" class="w-full sm:w-40 shrink-0 {{ $selectedTrigger === 'keyword' ? '' : 'hidden' }}">
                <x-text-input id="trigger_config_keyword" name="trigger_config[keyword]" type="text" class="!min-h-[38px] !bg-white/[0.03]"
                    value="{{ old('trigger_config.keyword', $flow->trigger_config['keyword'] ?? '') }}" placeholder="Keyword" />
            </div>

            <span class="flex-1"></span>

            <div class="shrink-0 flex items-center gap-1">
                <button type="button" id="flow-zoom-out" class="w-9 h-9 rounded-lg text-sm font-semibold text-brand-100/80 hover:bg-white/10 hover:text-white" title="Zoom out">−</button>
                <button type="button" id="flow-zoom-in" class="w-9 h-9 rounded-lg text-sm font-semibold text-brand-100/80 hover:bg-white/10 hover:text-white" title="Zoom in">+</button>
                <button type="button" id="flow-delete-node" class="px-3 h-9 rounded-lg text-sm text-red-300 hover:bg-red-400/10 hover:text-red-200" title="Delete selected node">Delete node</button>
            </div>

            <a href="{{ route('whatsapp-crm.flows.index') }}" class="shrink-0 text-sm text-brand-100/70 hover:text-white px-2">Cancel</a>
            <x-primary-button type="submit" class="shrink-0 !py-2">Save</x-primary-button>
        </header>

        @if ($errors->has('name') || $errors->has('trigger_type') || $errors->has('trigger_config.keyword') || $errors->has('graph'))
            <div class="shrink-0 px-4 py-2 bg-red-500/15 text-red-200 text-sm space-y-1">
                @foreach ($errors->get('name') as $m) <p>{{ $m }}</p> @endforeach
                @foreach ($errors->get('trigger_type') as $m) <p>{{ $m }}</p> @endforeach
                @foreach ($errors->get('trigger_config.keyword') as $m) <p>{{ $m }}</p> @endforeach
                @foreach ($errors->get('graph') as $m) <p>{{ $m }}</p> @endforeach
            </div>
        @endif

        {{-- Client-portal / label-applied hints, kept out of the top bar
             (there is no room there) but still id-addressed by the same JS
             listener that used to toggle them next to the trigger select. --}}
        <p id="trigger-client-portal-hint" class="shrink-0 px-4 py-1.5 text-xs text-brand-100/60 bg-brand-900/60 {{ $selectedTrigger === 'client_portal' ? '' : 'hidden' }}">
            Runs only for clients with <strong class="text-brand-200">WhatsApp self-service portal</strong> enabled on their phone number.
            Use <strong class="text-brand-200">Client Action</strong> nodes for invoices, reports and shoots. In Send Message/Send List, use <code class="text-brand-300">@{{client.name}}</code>.
        </p>
        <p id="trigger-label-applied-warning" class="shrink-0 px-4 py-1.5 text-xs text-amber-300 bg-brand-900/60 {{ $selectedTrigger === 'label_applied' ? '' : 'hidden' }}">
            Not wired up yet -- a flow with this trigger will never start on its own.
        </p>

        <div class="flex-1 min-h-0 flex">
            {{-- Palette panel: starts open on desktop, closed on a phone
                 (see x-data above) -- Drawflow's own drag-and-drop is
                 desktop-first, and the toggle button keeps it available
                 without permanently costing canvas width on a small
                 screen. --}}
            <aside x-show="palette" x-cloak
                   class="w-60 shrink-0 overflow-y-auto border-r border-white/10 bg-brand-900 p-3 space-y-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-brand-100/60 mb-2">Drag onto canvas</p>
                    {{-- Populated by whatsapp-flow-builder.js from its own
                         NODE_TYPES list -- one source of truth for the node
                         palette, not a second copy here. --}}
                    <div id="flow-palette"></div>
                </div>
                <p class="text-xs text-brand-100/50 leading-snug">
                    Connect a node's right-edge dot to another node's left-edge dot to chain them. Every node carries a
                    "Set as start" link -- exactly one must be marked before this flow can be activated.
                </p>
            </aside>

            {{-- min-h-0 here and on the flex row above is load-bearing: a
                 flex child otherwise refuses to shrink below its content,
                 the canvas overflows the viewport, and page scroll comes
                 back -- defeating the entire point of this layout. --}}
            <div class="flow-canvas-shell flex-1 min-h-0">
                <div id="flow-drawflow"></div>
            </div>
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
</x-editor-layout>
