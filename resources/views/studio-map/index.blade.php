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

    $legend = [
        'overdue' => ['Overdue invoice', 'bg-red-400'],
        'rolling' => ['Shoot rolling now', 'bg-emerald-400'],
        'shoot' => ['Shoot today', 'bg-amber-400'],
        'waiting' => ['Waiting on client', 'bg-sky-400'],
        'owes' => ['Owes, not overdue', 'bg-violet-400'],
        'calm' => ['All quiet', 'bg-slate-400'],
    ];
@endphp

<x-app-layout title="Studio Map">
    @push('scripts')
        @vite('resources/js/studio-map.js')
    @endpush

    <x-slot name="header">
        <x-page-header
            title="Studio Map"
            subtitle="Every client as a building. Taller means more hours this month; the colour says what needs you." />
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

        <div class="relative overflow-hidden rounded-2xl ring-1 ring-white/10 bg-[#0b1a24] h-[62vh] min-h-[380px] sm:min-h-[480px]">
            <div class="absolute inset-0" data-map-canvas></div>

            <p class="absolute inset-0 hidden items-center justify-center p-6 text-center text-sm text-brand-100/60" data-map-fallback>
                This browser can't draw the 3D map. Every client is listed below.
            </p>

            <div class="pointer-events-none absolute left-3 top-3 hidden rounded-md bg-black/70 px-2.5 py-1.5 text-xs text-white" data-map-tooltip></div>

            <div class="absolute left-3 bottom-3 rounded-lg bg-black/55 backdrop-blur px-3 py-2 text-[11px] text-brand-100/80 space-y-1">
                @foreach ($legend as [$label, $dot])
                    <div class="flex items-center gap-2"><span class="w-2.5 h-2.5 rounded-sm {{ $dot }}"></span>{{ $label }}</div>
                @endforeach
            </div>

            <div class="absolute right-3 top-3 text-[11px] text-brand-100/50 text-right">
                Drag to turn · scroll to zoom · click a building<br>
                Updated <span data-generated-at>{{ $snapshot['generated_at'] }}</span>
            </div>

            {{-- Side panel: filled by studio-map.js when a building is picked. --}}
            <aside class="absolute inset-x-3 bottom-3 sm:inset-x-auto sm:right-3 sm:top-14 sm:bottom-3 sm:w-80 hidden flex-col overflow-y-auto rounded-xl bg-brand-900/95 ring-1 ring-white/15 shadow-2xl max-h-[60%] sm:max-h-none"
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
