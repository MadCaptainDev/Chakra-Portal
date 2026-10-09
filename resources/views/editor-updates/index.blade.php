{{--
    Editor Updates -- one day, person by person: each to-do and how it moved.
    See EditorUpdatesController.
--}}
@php
    $tone = [
        'added' => 'bg-white/10 text-brand-100/90',
        'started' => 'bg-amber-400/15 text-amber-200',
        'fix' => 'bg-orange-400/15 text-orange-200',
        'fixed' => 'bg-lime-400/10 text-lime-200',
        'done' => 'bg-sky-400/15 text-sky-200',
        'posted' => 'bg-emerald-400/15 text-emerald-200',
        'other' => 'bg-white/5 text-brand-100/70',
    ];
    $statusChip = [
        'waiting' => ['To do', 'bg-white/10 text-brand-100/80'],
        'blocked' => ['Blocked', 'bg-red-400/15 text-red-200'],
        'started' => ['Working', 'bg-amber-400/15 text-amber-200'],
        'completed' => ['Done', 'bg-sky-400/15 text-sky-200'],
        'cancelled' => ['Cancelled', 'bg-white/5 text-brand-100/50'],
    ];
    $hm = fn (int $m) => $m >= 60 ? intdiv($m, 60).'h '.($m % 60).'m' : $m.'m';
    $isToday = $day->isToday();
@endphp

<x-app-layout title="Editor Updates">
    <x-slot name="header">
        <x-page-header title="Editor Updates" eyebrow="Team"
                       subtitle="Every to-do on each person's board, and how it moved that day — added, started, corrections, done, posted." />
    </x-slot>

    <div class="mx-auto max-w-4xl space-y-5">
        <x-day-nav route="editor-updates" :day="$day" :subtitle="$isToday ? 'Live — updates as people work' : null" />

        {{-- The day in four numbers --}}
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
            @foreach ([['Added', $totals['added'], 'text-white'], ['Corrections', $totals['fixes'], 'text-orange-200'], ['Done', $totals['done'], 'text-sky-200'], ['Posted', $totals['posted'], 'text-emerald-200']] as [$label, $n, $color])
                <div class="rounded-xl bg-white/5 px-4 py-3 ring-1 ring-white/10">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-100/50">{{ $label }}</p>
                    <p class="mt-0.5 text-2xl font-bold tabular-nums {{ $color }}">{{ $n }}</p>
                </div>
            @endforeach
        </div>

        @if ($people->isEmpty())
            <div class="rounded-xl border border-dashed border-white/15 px-6 py-12 text-center">
                <p class="text-sm text-brand-100/70">Nothing on anyone's board {{ $isToday ? 'today' : 'that day' }}.</p>
                <p class="mt-1 text-xs text-brand-100/50">Videos show up here as editors add them in My Editing, and every move they make is listed.</p>
            </div>
        @endif

        @foreach ($people as $p)
            <section class="rounded-2xl bg-white/[0.03] ring-1 ring-white/10" x-data="{ still: false }">
                {{-- Who, and their day in a line --}}
                <div class="flex flex-wrap items-center gap-3 border-b border-white/10 px-4 py-3.5 sm:px-5">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand-400/20 text-sm font-bold text-brand-200">{{ mb_strtoupper(mb_substr($p['user']->name, 0, 1)) }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-white">{{ $p['user']->name }}</p>
                        <p class="text-xs text-brand-100/55">
                            @if ($p['first'])
                                {{ $p['first']->format('g:i A') }} – {{ $p['last']->format('g:i A') }}
                            @else
                                No moves {{ $isToday ? 'yet today' : 'that day' }}
                            @endif
                            @if ($p['minutes'] > 0) · {{ $hm($p['minutes']) }} working @endif
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-1.5 text-[11px] font-semibold">
                        @foreach (['added' => 'added', 'started' => 'started', 'fixes' => 'corrections', 'done' => 'done', 'posted' => 'posted'] as $k => $label)
                            @if ($p['stats'][$k] > 0)
                                <span class="rounded-full px-2 py-0.5 {{ $tone[$k === 'fixes' ? 'fix' : $k] }}">{{ $p['stats'][$k] }} {{ $label }}</span>
                            @endif
                        @endforeach
                    </div>
                </div>

                {{-- What moved --}}
                <div class="divide-y divide-white/5">
                    @foreach ($p['moved'] as $v)
                        @php [$sLabel, $sCls] = $statusChip[$v['status']] ?? [ucfirst($v['status']), 'bg-white/10 text-brand-100/80']; @endphp
                        <div class="px-4 py-3 sm:px-5">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-semibold leading-snug text-white">{{ $v['title'] }}</p>
                                    <p class="mt-0.5 text-xs text-brand-100/50">
                                        {{ $v['client'] }}@if ($v['kind']) · {{ $v['kind'] }}@endif
                                        @if ($v['publishes']) · {{ $v['publishes'] }}@endif
                                        @if ($v['carried']) · on the board since {{ $v['carried']->format('j M') }}@endif
                                        @if ($v['minutes'] > 0) · <span class="text-amber-200/80">{{ $hm($v['minutes']) }} working</span>@endif
                                    </p>
                                </div>
                                <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $sCls }}" title="{{ $isToday ? 'Now' : 'At the end of that day' }}">
                                    {{ $sLabel }}@if ($v['open_fixes'] > 0 && $v['status'] !== 'completed') · {{ $v['open_fixes'] }} fix{{ $v['open_fixes'] > 1 ? 'es' : '' }} open @endif
                                </span>
                            </div>
                            <ol class="mt-2 flex flex-wrap items-center gap-x-1 gap-y-1.5">
                                @foreach ($v['events'] as $i => $e)
                                    @if ($i > 0)
                                        <li class="text-brand-100/25" aria-hidden="true">→</li>
                                    @endif
                                    <li class="inline-flex max-w-full items-center gap-1.5 rounded-lg px-2 py-1 text-xs {{ $tone[$e['type']] }}">
                                        <span class="tabular-nums opacity-70">{{ $e['at']->format('g:i') }}</span>
                                        <span class="font-semibold">{{ $e['label'] }}</span>
                                        @if ($e['detail'])
                                            <span class="truncate opacity-80">· {{ \Illuminate\Support\Str::limit($e['detail'], 60) }}</span>
                                        @endif
                                        @if ($e['by'])
                                            <span class="opacity-60">by {{ $e['by'] }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ol>
                        </div>
                    @endforeach

                    @if ($p['moved']->isEmpty())
                        <p class="px-4 py-3 text-sm text-brand-100/50 sm:px-5">Nothing moved {{ $isToday ? 'yet today' : 'that day' }}.</p>
                    @endif
                </div>

                {{-- On the board, untouched --}}
                @if ($p['still']->isNotEmpty())
                    <div class="border-t border-white/10 px-4 py-2.5 sm:px-5">
                        <button type="button" @click="still = !still" class="flex min-h-[36px] w-full items-center justify-between text-left text-xs font-semibold text-brand-100/60 hover:text-white">
                            <span>Still on the board, no movement {{ $isToday ? 'today' : 'that day' }} ({{ $p['still']->count() }})</span>
                            <span x-text="still ? '▴' : '▾'"></span>
                        </button>
                        <ul x-show="still" x-cloak class="mt-1 space-y-1 pb-1">
                            @foreach ($p['still'] as $v)
                                @php [$sLabel, $sCls] = $statusChip[$v['status']] ?? [ucfirst($v['status']), '']; @endphp
                                <li class="flex items-center justify-between gap-3 text-sm">
                                    <span class="min-w-0 truncate text-brand-100/80">{{ $v['title'] }} <span class="text-xs text-brand-100/40">· {{ $v['client'] }}@if ($v['carried']) · since {{ $v['carried']->format('j M') }}@endif</span></span>
                                    <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $sCls }}">{{ $sLabel }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </section>
        @endforeach
    </div>
</x-app-layout>
