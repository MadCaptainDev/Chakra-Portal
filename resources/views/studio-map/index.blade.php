{{--
    The Studio Map: the clients as an animated city (resources/js/studio-city.js),
    drawn by resources/js/studio-map.js from the JSON below.

    Map first: the map fills the window, and everything else -- search, the
    lens switcher, the figures, the team bench, the feed, the replay -- floats
    on it. The lists under it are plain HTML the script fills in, so a
    phone, a screen reader or a map that fails to load still gets every
    figure and every action.
--}}
@php
    $money = fn ($amount) => '₹'.number_format((float) $amount, 0);
    $t = $snapshot['totals'];

    $stats = [
        ['key' => 'owed', 'label' => 'Owed', 'value' => $money($t['owed'])],
        ['key' => 'overdue', 'label' => 'Overdue', 'value' => $money($t['overdue']), 'tone' => $t['overdue'] > 0 ? 'text-red-300' : ''],
        ['key' => 'shoots_today', 'label' => 'Shoots today', 'value' => $t['shoots_today']],
        ['key' => 'rolling', 'label' => 'Rolling', 'value' => $t['rolling'], 'tone' => $t['rolling'] > 0 ? 'text-emerald-300' : ''],
        ['key' => 'waiting_on_clients', 'label' => 'Waiting on clients', 'value' => $t['waiting_on_clients']],
        ['key' => 'hours_month', 'label' => 'Hours this month', 'value' => number_format($t['hours_month'], 1)],
    ];

    // One map, five questions. Each lens's legend is drawn by studio-map.js.
    $lenses = ['status' => 'Status', 'money' => 'Money', 'hours' => 'Hours', 'content' => 'Content', 'crew' => 'Crew'];

    $actionUrls = [
        'assignCrew' => route('studio-map.actions.assign-crew'),
        'removeCrew' => route('studio-map.actions.remove-crew'),
        'createShoot' => route('studio-map.actions.create-shoot'),
        'createTodo' => route('studio-map.actions.create-todo'),
        'place' => route('studio-map.actions.place'),
        'locate' => route('studio-map.actions.locate'),
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

    $glass = 'bg-brand-900/85 shadow-lg ring-1 ring-white/10 backdrop-blur';
@endphp

<x-app-layout title="Studio Map">
    @push('scripts')
        @vite('resources/js/studio-map.js')
    @endpush

    <h1 class="sr-only">Studio Map</h1>

    <div class="space-y-4" data-studio-map data-endpoint="{{ route('studio-map.data') }}" data-logo="{{ asset('images/chakra-logo.png') }}" data-actions='@json($actionUrls)'>
        <script type="application/json" data-studio-map-snapshot>@json($snapshot)</script>

        <div class="relative -mt-2 h-[calc(100dvh-6.5rem)] min-h-[540px] overflow-hidden rounded-2xl bg-[#0b1a24] ring-1 ring-white/10 lg:h-[calc(100dvh-5.5rem)] [&:fullscreen]:h-screen [&:fullscreen]:rounded-none" data-map-box>
            <div class="absolute inset-0 z-0" data-map-canvas></div>

            <p class="absolute inset-0 hidden items-center justify-center p-6 text-center text-sm text-brand-100/60" data-map-fallback>
                The city could not be drawn. Every client is listed below, and each one's actions open from there.
            </p>

            {{-- ——— Top left: find, lens, legend, unpinned ——— --}}
            <div class="absolute left-3 top-3 z-10 flex w-[min(19rem,calc(100%-5.5rem))] flex-col items-start gap-2">
                <div class="relative w-full" data-search>
                    <svg class="pointer-events-none absolute left-3 top-1/2 z-10 h-4 w-4 -translate-y-1/2 text-brand-100/60" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z" /></svg>
                    <input type="search" data-search-input autocomplete="off" spellcheck="false"
                           placeholder="Find a client or person"
                           role="combobox" aria-expanded="false" aria-controls="studio-map-search-results" aria-autocomplete="list"
                           class="h-10 w-full rounded-xl border-0 pl-9 pr-9 text-sm text-white placeholder:text-brand-100/45 focus:ring-2 focus:ring-brand-400 {{ $glass }}">
                    <kbd class="pointer-events-none absolute right-3 top-1/2 z-10 hidden -translate-y-1/2 rounded border border-white/15 px-1.5 text-[11px] text-brand-100/50 lg:block">/</kbd>
                    <ul id="studio-map-search-results" role="listbox" data-search-results
                        class="absolute inset-x-0 top-full z-30 mt-1 hidden max-h-80 overflow-y-auto rounded-xl bg-brand-900/95 p-1 shadow-2xl ring-1 ring-white/15 backdrop-blur"></ul>
                </div>

                <div class="flex max-w-full flex-wrap gap-1 rounded-xl p-1 {{ $glass }}" role="tablist" aria-label="Map lens">
                    @foreach ($lenses as $key => $label)
                        <button type="button" data-lens="{{ $key }}" role="tab"
                                class="rounded-lg px-2.5 py-1 text-xs font-semibold text-brand-100/70 hover:text-white aria-selected:bg-brand-400 aria-selected:text-brand-900"
                                aria-selected="{{ $key === 'status' ? 'true' : 'false' }}" title="{{ $label }} ({{ $loop->iteration }})">{{ $label }}</button>
                    @endforeach
                </div>

                <div class="hidden space-y-1 rounded-xl px-3 py-2 text-[11px] text-brand-100/80 sm:block {{ $glass }}" data-legend></div>
                <div class="relative" data-unpinned></div>
            </div>

            {{-- ——— Top centre: the figures (wide screens; below the map otherwise) ——— --}}
            <dl class="absolute left-1/2 top-3 z-10 hidden -translate-x-1/2 divide-x divide-white/10 rounded-xl 2xl:flex {{ $glass }}" aria-label="Today's figures">
                @foreach ($stats as $stat)
                    <div class="px-3 py-1.5 text-center">
                        <dt class="whitespace-nowrap text-[9px] font-semibold uppercase tracking-[0.14em] text-brand-100/50">{{ $stat['label'] }}</dt>
                        <dd class="text-sm font-bold tabular-nums text-white {{ $stat['tone'] ?? '' }}" data-stat="{{ $stat['key'] }}">{{ $stat['value'] }}</dd>
                    </div>
                @endforeach
            </dl>

            {{-- ——— Top right: map controls ——— --}}
            <div class="absolute right-3 top-3 z-10 flex flex-col items-end gap-2">
                <div class="flex overflow-hidden rounded-xl text-xs font-semibold {{ $glass }}" role="group" aria-label="Daylight">
                    <button type="button" data-sky="auto" aria-pressed="true" title="Follows the clock (and the replay)" class="px-2.5 py-2 text-brand-100/70 hover:text-white aria-pressed:bg-white/15 aria-pressed:text-white">Auto</button>
                    <button type="button" data-sky="day" aria-pressed="false" class="px-2.5 py-2 text-brand-100/70 hover:text-white aria-pressed:bg-white/15 aria-pressed:text-white">Day</button>
                    <button type="button" data-sky="night" aria-pressed="false" class="px-2.5 py-2 text-brand-100/70 hover:text-white aria-pressed:bg-white/15 aria-pressed:text-white">Night</button>
                </div>
                <div class="flex flex-col overflow-hidden rounded-xl text-white {{ $glass }}">
                    <button type="button" data-map-zoom="in" class="flex h-9 w-9 items-center justify-center text-lg hover:bg-white/10" aria-label="Zoom in">+</button>
                    <button type="button" data-map-zoom="out" class="flex h-9 w-9 items-center justify-center border-t border-white/10 text-lg hover:bg-white/10" aria-label="Zoom out">−</button>
                    <button type="button" data-map-fit class="flex h-9 w-9 items-center justify-center border-t border-white/10 hover:bg-white/10" aria-label="Show the whole city" title="Show the whole city">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9V5.25A1.5 1.5 0 0 1 5.25 3.75H9m6 0h3.75a1.5 1.5 0 0 1 1.5 1.5V9m0 6v3.75a1.5 1.5 0 0 1-1.5 1.5H15m-6 0H5.25a1.5 1.5 0 0 1-1.5-1.5V15" /></svg>
                    </button>
                    <button type="button" data-map-fullscreen class="flex h-9 w-9 items-center justify-center border-t border-white/10 hover:bg-white/10" aria-label="Full screen" title="Full screen">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15" /></svg>
                    </button>
                </div>
                <div class="relative hidden lg:block">
                    <button type="button" data-help-toggle aria-expanded="false" aria-controls="studio-map-help"
                            class="flex h-9 w-9 items-center justify-center rounded-xl text-sm font-bold text-brand-100/80 hover:text-white {{ $glass }}" aria-label="Keyboard shortcuts">?</button>
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
                        <p class="mt-3 border-t border-white/10 pt-2 text-[11px] text-brand-100/50">Click a building for its actions. Drag a person from the bench onto a building to put them on a shoot, or onto the Chakra tower to take them off one.</p>
                    </div>
                </div>
            </div>


            {{-- ——— Bottom: the feed, then the bench and the replay ——— --}}
            <div class="pointer-events-none absolute inset-x-3 bottom-[130px] z-10 sm:bottom-[78px]">
                <ol class="flex gap-2 overflow-hidden" data-ticker aria-live="polite" aria-label="Today on shoots"></ol>
            </div>

            <div class="absolute inset-x-3 bottom-6 z-10 flex flex-col gap-1.5 sm:flex-row sm:items-stretch">
                <div class="flex min-w-0 flex-1 items-center gap-2 rounded-xl px-2 py-1.5 {{ $glass }}">
                    <span class="hidden shrink-0 pl-1 text-[10px] font-semibold uppercase leading-tight tracking-[0.14em] text-brand-100/50 md:block">Team<br><span class="normal-case tracking-normal text-brand-100/40">drag onto a pin</span></span>
                    <ul class="flex min-w-0 flex-1 gap-1.5 overflow-x-auto [scrollbar-width:none]" data-roster aria-label="Team"></ul>
                </div>
                <div class="flex items-center gap-3 rounded-xl px-3 py-2 sm:w-[min(26rem,42%)] {{ $glass }}">
                    <button type="button" data-replay-play class="shrink-0 rounded-md bg-white/10 px-2.5 py-1 text-xs font-semibold text-white hover:bg-white/20" aria-label="Play today">▶ Replay</button>
                    <input type="range" data-replay-slider class="min-w-0 flex-1 accent-[#67BCD4]" min="0" max="1" step="60000" value="1" aria-label="Time of day">
                    <span class="w-14 shrink-0 text-right text-xs font-semibold tabular-nums text-white" data-replay-clock>Live</span>
                    <button type="button" data-replay-live class="hidden shrink-0 rounded-md bg-red-500/80 px-2 py-1 text-xs font-semibold text-white hover:bg-red-500">● Live</button>
                </div>
            </div>

            {{-- The command card: a client's actions, pinned above it as the
                 map moves; docked to the bottom on a narrow screen. --}}
            <div class="absolute z-20 hidden" data-command-card role="dialog" aria-modal="false"></div>

            {{-- Side panel: a client's or a person's details. --}}
            <aside class="absolute inset-x-3 bottom-[130px] z-20 hidden max-h-[60%] flex-col overflow-y-auto rounded-xl bg-brand-900/95 shadow-2xl ring-1 ring-white/15 sm:inset-x-auto sm:bottom-[78px] sm:right-14 sm:top-3 sm:max-h-none sm:w-80"
                   data-map-panel aria-live="polite">
            </aside>
        </div>

        {{-- The figures, under the map where they do not fit on it. --}}
        <div class="grid grid-cols-3 gap-1.5 sm:gap-2 lg:grid-cols-6 2xl:hidden">
            @foreach ($stats as $stat)
                <div class="rounded-xl bg-white/5 px-3 py-2 ring-1 ring-white/10">
                    <p class="truncate text-[9px] font-semibold uppercase tracking-[0.12em] text-brand-100/50 sm:text-[10px]">{{ $stat['label'] }}</p>
                    <p class="mt-0.5 text-base font-bold tabular-nums text-white {{ $stat['tone'] ?? '' }}" data-stat="{{ $stat['key'] }}">{{ $stat['value'] }}</p>
                </div>
            @endforeach
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            <x-card class="p-4 sm:p-5">
                <h2 class="text-sm font-semibold text-white">Needs attention</h2>
                <ul class="mt-3 space-y-2 text-sm" data-attention-list></ul>
            </x-card>

            <x-card class="p-4 sm:p-5">
                <h2 class="text-sm font-semibold text-white">Every client <span class="font-normal text-brand-100/50">· updated <span data-generated-at>{{ $snapshot['generated_at'] }}</span></span></h2>
                <ul class="mt-3 max-h-[420px] divide-y divide-white/10 overflow-y-auto text-sm" data-building-list></ul>
            </x-card>
        </div>

        {{-- Every action's form: one native dialog, filled per action. --}}
        <dialog data-action-dialog aria-label="Action"
                class="m-auto w-[min(94vw,460px)] overflow-hidden rounded-2xl bg-brand-900 p-0 text-white shadow-2xl ring-1 ring-white/15 backdrop:bg-black/60 backdrop:backdrop-blur-sm"></dialog>
    </div>

    <div class="pointer-events-none fixed bottom-4 right-4 z-[60] flex w-[min(92vw,380px)] flex-col items-stretch gap-2" data-toasts></div>
</x-app-layout>
