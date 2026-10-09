{{--
    The Studio Map. The 3D town is drawn by resources/js/studio-map.js from
    the JSON below; everything else on the page -- the resource strip, the
    side panel, the list under the map -- is plain HTML that the same script
    fills in, so a phone, a screen reader or a browser without WebGL still
    gets every figure, just without the town.
--}}
@php
    $money = fn ($amount) => '₹'.number_format((float) $amount, 0);
    $t = $snapshot['totals'];

    $stats = [
        ['key' => 'owed', 'label' => 'Owed to us', 'value' => $money($t['owed'])],
        ['key' => 'overdue', 'label' => 'Overdue', 'value' => $money($t['overdue']), 'tone' => $t['overdue'] > 0 ? 'text-red-300' : ''],
        ['key' => 'shoots_today', 'label' => 'Shoots today', 'value' => $t['shoots_today']],
        ['key' => 'rolling', 'label' => 'Rolling now', 'value' => $t['rolling'], 'tone' => $t['rolling'] > 0 ? 'text-emerald-300' : ''],
        ['key' => 'waiting_on_clients', 'label' => 'Waiting on clients', 'value' => $t['waiting_on_clients']],
        ['key' => 'hours_month', 'label' => 'Hours this month', 'value' => number_format($t['hours_month'], 1)],
    ];

    // The lenses: one town, five questions. The legend for each is drawn by
    // studio-map.js, since it changes with the lens.
    $lenses = ['status' => 'Status', 'money' => 'Money', 'hours' => 'Hours', 'content' => 'Content', 'crew' => 'Crew'];
@endphp

<x-app-layout title="Studio Map">
    @push('scripts')
        @vite('resources/js/studio-map.js')
    @endpush

    <x-slot name="header">
        <x-page-header
            title="Studio Map"
            subtitle="Your clients as a town. Click a building to act on it, drag your team onto shoots, switch lens for a different question." />
    </x-slot>

    @php
        $actionUrls = [
            'assignCrew' => route('studio-map.actions.assign-crew'),
            'removeCrew' => route('studio-map.actions.remove-crew'),
            'createShoot' => route('studio-map.actions.create-shoot'),
            'createTodo' => route('studio-map.actions.create-todo'),
            'preview' => route('studio-map.actions.preview'),
            'send' => route('studio-map.actions.send'),
        ];
        $shortcuts = [
            ['/', 'Find a client or person'],
            ['1 – 5', 'Switch lens'],
            ['Space', 'Play or pause today’s replay'],
            ['Esc', 'Close whatever is open'],
            ['?', 'Show these keys'],
        ];
    @endphp

    <div class="space-y-4" data-studio-map data-endpoint="{{ route('studio-map.data') }}" data-actions='@json($actionUrls)'>
        <script type="application/json" data-studio-map-snapshot>@json($snapshot)</script>

        {{-- The resource bar, strategy-game style. --}}
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2">
            @foreach ($stats as $stat)
                <div class="rounded-xl bg-white/5 ring-1 ring-white/10 px-3 py-2.5">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-100/50">{{ $stat['label'] }}</p>
                    <p class="mt-0.5 text-lg font-bold tabular-nums text-white {{ $stat['tone'] ?? '' }}" data-stat="{{ $stat['key'] }}">{{ $stat['value'] }}</p>
                </div>
            @endforeach
        </div>

        {{-- Command bar: find anything, and the team bench to drag from. --}}
        <div class="flex flex-col gap-2 lg:flex-row lg:items-stretch">
            <div class="relative lg:w-80 shrink-0" data-search>
                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-brand-100/50" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z" /></svg>
                <input type="search" data-search-input autocomplete="off" spellcheck="false"
                       placeholder="Find a client or person"
                       role="combobox" aria-expanded="false" aria-controls="studio-map-search-results" aria-autocomplete="list"
                       class="h-full min-h-[46px] w-full rounded-xl border-0 bg-white/5 pl-9 pr-10 text-sm text-white ring-1 ring-white/10 placeholder:text-brand-100/40 focus:ring-2 focus:ring-brand-400">
                <kbd class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 rounded border border-white/15 px-1.5 text-[11px] text-brand-100/50">/</kbd>
                <ul id="studio-map-search-results" role="listbox" data-search-results
                    class="absolute inset-x-0 top-full z-30 mt-1 hidden max-h-80 overflow-y-auto rounded-xl bg-brand-900/95 p-1 shadow-2xl ring-1 ring-white/15 backdrop-blur"></ul>
            </div>

            <div class="flex min-w-0 flex-1 items-center gap-3 rounded-xl bg-white/5 px-3 py-1.5 ring-1 ring-white/10">
                <div class="hidden shrink-0 sm:block">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-100/50">Team</p>
                    <p class="text-[11px] text-brand-100/40">drag onto a building</p>
                </div>
                <ul class="flex min-w-0 flex-1 gap-1.5 overflow-x-auto py-1 [scrollbar-width:thin]" data-roster aria-label="Team"></ul>
            </div>

            <div class="relative shrink-0">
                <button type="button" data-help-toggle aria-expanded="false" aria-controls="studio-map-help"
                        class="h-full min-h-[46px] w-full rounded-xl bg-white/5 px-4 text-sm font-semibold text-brand-100/70 ring-1 ring-white/10 hover:text-white lg:w-auto">
                    Keys <kbd class="ml-1 rounded border border-white/15 px-1.5 text-[11px]">?</kbd>
                </button>
                <div id="studio-map-help" data-help class="absolute right-0 top-full z-30 mt-1 hidden w-72 rounded-xl bg-brand-900/95 p-3 shadow-2xl ring-1 ring-white/15 backdrop-blur">
                    <p class="mb-2 text-xs font-semibold text-white">Keyboard</p>
                    <dl class="space-y-1.5 text-xs">
                        @foreach ($shortcuts as [$key, $what])
                            <div class="flex items-center justify-between gap-3">
                                <dt class="text-brand-100/70">{{ $what }}</dt>
                                <dd><kbd class="rounded border border-white/15 bg-white/5 px-1.5 py-0.5 text-[11px] text-white">{{ $key }}</kbd></dd>
                            </div>
                        @endforeach
                    </dl>
                    <p class="mt-3 border-t border-white/10 pt-2 text-[11px] text-brand-100/50">Right-click or long-press a building for its actions. Drag a person onto the tower to take them off a shoot.</p>
                </div>
            </div>
        </div>

        <div class="relative overflow-hidden rounded-2xl ring-1 ring-white/10 bg-[#0b1a24] h-[70vh] min-h-[460px] sm:min-h-[560px]" data-map-box>
            <div class="absolute inset-0 touch-none" data-map-canvas></div>

            <p class="absolute inset-0 hidden items-center justify-center p-6 text-center text-sm text-brand-100/60" data-map-fallback>
                This browser can't draw the 3D map. Every client is listed below, and each one's actions open from there.
            </p>

            <div class="pointer-events-none absolute left-0 top-0 z-20 hidden rounded-md bg-black/75 px-2.5 py-1.5 text-xs text-white" data-map-tooltip></div>

            {{-- Lens switcher and its legend. --}}
            <div class="absolute left-3 top-3 flex flex-col items-start gap-2">
                <div class="flex flex-wrap gap-1 rounded-lg bg-black/55 backdrop-blur p-1" role="tablist" aria-label="Map lens">
                    @foreach ($lenses as $key => $label)
                        <button type="button" data-lens="{{ $key }}" role="tab"
                                class="rounded-md px-2.5 py-1 text-xs font-semibold text-brand-100/70 hover:text-white aria-selected:bg-brand-400 aria-selected:text-brand-900"
                                aria-selected="{{ $key === 'status' ? 'true' : 'false' }}"><span class="mr-1 hidden text-[10px] opacity-60 sm:inline">{{ $loop->iteration }}</span>{{ $label }}</button>
                    @endforeach
                </div>
                <div class="rounded-lg bg-black/55 backdrop-blur px-3 py-2 text-[11px] text-brand-100/80 space-y-1" data-legend></div>
            </div>

            <div class="absolute right-3 top-3 text-[11px] text-brand-100/50 text-right hidden sm:block">
                Click a building to act · drag to turn · scroll to zoom<br>
                Updated <span data-generated-at>{{ $snapshot['generated_at'] }}</span>
            </div>

            {{-- Live feed: today's events, newest first. --}}
            <div class="absolute inset-x-3 bottom-[60px] pointer-events-none">
                <ol class="flex gap-2 overflow-hidden" data-ticker aria-live="polite" aria-label="Today on shoots"></ol>
            </div>

            {{-- Day replay. --}}
            <div class="absolute inset-x-3 bottom-3 flex items-center gap-3 rounded-lg bg-black/60 backdrop-blur px-3 py-2">
                <button type="button" data-replay-play class="shrink-0 rounded-md bg-white/10 px-2.5 py-1 text-xs font-semibold text-white hover:bg-white/20" aria-label="Play today">▶ Replay</button>
                <input type="range" data-replay-slider class="flex-1 accent-[#67BCD4]" min="0" max="1" step="60000" value="1" aria-label="Time of day">
                <span class="shrink-0 w-16 text-right text-xs font-semibold tabular-nums text-white" data-replay-clock>Live</span>
                <button type="button" data-replay-live class="hidden shrink-0 rounded-md bg-red-500/80 px-2.5 py-1 text-xs font-semibold text-white hover:bg-red-500">● Live</button>
            </div>

            {{-- The command card: a building's actions, pinned above it as the
                 camera moves; docked to the bottom on a narrow screen. --}}
            <div class="absolute z-20 hidden" data-command-card role="dialog" aria-modal="false"></div>

            {{-- Side panel: a building's or a person's details. --}}
            <aside class="absolute inset-x-3 bottom-[112px] sm:inset-x-auto sm:right-3 sm:top-14 sm:bottom-[112px] sm:w-80 hidden flex-col overflow-y-auto rounded-xl bg-brand-900/95 ring-1 ring-white/15 shadow-2xl max-h-[55%] sm:max-h-none z-10"
                   data-map-panel aria-live="polite">
            </aside>
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            <x-card class="p-4 sm:p-5">
                <h2 class="text-sm font-semibold text-white">Needs attention</h2>
                <ul class="mt-3 space-y-2 text-sm" data-attention-list></ul>
            </x-card>

            <x-card class="p-4 sm:p-5">
                <h2 class="text-sm font-semibold text-white">Every building</h2>
                <ul class="mt-3 divide-y divide-white/10 text-sm max-h-[420px] overflow-y-auto" data-building-list></ul>
            </x-card>
        </div>

        {{-- Every action's form: one native dialog, filled per action. --}}
        <dialog data-action-dialog aria-label="Action"
                class="m-auto w-[min(94vw,460px)] overflow-hidden rounded-2xl bg-brand-900 p-0 text-white shadow-2xl ring-1 ring-white/15 backdrop:bg-black/60 backdrop:backdrop-blur-sm"></dialog>
    </div>

    <div class="pointer-events-none fixed bottom-4 right-4 z-[60] flex w-[min(92vw,380px)] flex-col items-stretch gap-2" data-toasts></div>
</x-app-layout>
