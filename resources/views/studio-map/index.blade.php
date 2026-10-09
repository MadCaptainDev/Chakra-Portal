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
            subtitle="Every client as a building around the studio. Switch lens to ask a different question; replay today with the slider." />
    </x-slot>

    <div class="space-y-5" data-studio-map data-endpoint="{{ route('studio-map.data') }}">
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

        <div class="relative overflow-hidden rounded-2xl ring-1 ring-white/10 bg-[#0b1a24] h-[70vh] min-h-[460px] sm:min-h-[560px]">
            <div class="absolute inset-0" data-map-canvas></div>

            <p class="absolute inset-0 hidden items-center justify-center p-6 text-center text-sm text-brand-100/60" data-map-fallback>
                This browser can't draw the 3D map. Every client is listed below.
            </p>

            <div class="pointer-events-none absolute left-3 top-3 hidden rounded-md bg-black/70 px-2.5 py-1.5 text-xs text-white" data-map-tooltip></div>

            {{-- Lens switcher and its legend. --}}
            <div class="absolute left-3 top-3 flex flex-col items-start gap-2">
                <div class="flex flex-wrap gap-1 rounded-lg bg-black/55 backdrop-blur p-1" role="tablist" aria-label="Map lens">
                    @foreach ($lenses as $key => $label)
                        <button type="button" data-lens="{{ $key }}" role="tab"
                                class="rounded-md px-2.5 py-1 text-xs font-semibold text-brand-100/70 hover:text-white aria-selected:bg-brand-400 aria-selected:text-brand-900"
                                aria-selected="{{ $key === 'status' ? 'true' : 'false' }}">{{ $label }}</button>
                    @endforeach
                </div>
                <div class="rounded-lg bg-black/55 backdrop-blur px-3 py-2 text-[11px] text-brand-100/80 space-y-1" data-legend></div>
            </div>

            <div class="absolute right-3 top-3 text-[11px] text-brand-100/50 text-right hidden sm:block">
                Drag to turn · scroll to zoom · click a building<br>
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

            {{-- Side panel: filled by studio-map.js when a building is picked. --}}
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
    </div>
</x-app-layout>
