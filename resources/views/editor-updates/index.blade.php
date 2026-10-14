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
        'hold' => 'bg-violet-400/15 text-violet-200',
        'moved' => 'bg-sky-400/10 text-sky-200/90',
        'done' => 'bg-sky-400/15 text-sky-200',
        'ready' => 'bg-violet-400/15 text-violet-200',
        'posted' => 'bg-emerald-400/15 text-emerald-200',
        'other' => 'bg-white/5 text-brand-100/70',
    ];
    $statusChip = [
        'waiting' => ['To do', 'bg-white/10 text-brand-100/80'],
        'blocked' => ['On hold', 'bg-violet-400/15 text-violet-200'],
        'started' => ['Working', 'bg-amber-400/15 text-amber-200'],
        'completed' => ['Done', 'bg-sky-400/15 text-sky-200'],
        'review' => ['Under review', 'bg-sky-400/15 text-sky-200'],
        'ready' => ['Video ready', 'bg-violet-400/15 text-violet-200'],
        'posted' => ['Posted', 'bg-emerald-400/15 text-emerald-200'],
        'delivered' => ['Delivered', 'bg-teal-400/15 text-teal-200'],
        'cancelled' => ['Cancelled', 'bg-white/5 text-brand-100/50'],
    ];
    $hm = fn (int $m) => $m >= 60 ? intdiv($m, 60).'h '.($m % 60).'m' : $m.'m';
    $isToday = $day->isToday();
@endphp

<x-app-layout title="Editor Updates">
    <div class="mx-auto max-w-[1320px] space-y-7 pb-16">
        {{-- Header: the day, live, and a switch to other days --}}
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-300">Team · Editing</p>
                <h1 class="mt-1 text-[30px] font-extrabold tracking-tight text-white sm:text-[34px]">Editor Updates</h1>
                <p class="text-sm text-brand-100/70">{{ $day->format('l, j F') }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2.5">
                @if ($isToday)
                    <span class="inline-flex h-10 items-center gap-2 rounded-full bg-emerald-400/[0.12] px-3.5 text-[13px] font-semibold text-emerald-300">
                        <span class="h-2 w-2 rounded-full bg-emerald-400 shadow-[0_0_0_4px_rgba(52,211,153,0.25)]"></span>Live · every 15s
                    </span>
                @endif
                <nav class="inline-flex h-11 items-center gap-1 rounded-xl border border-white/[0.08] bg-[#15303f] px-1" aria-label="Day">
                    <a href="{{ route('editor-updates', ['date' => $day->copy()->subDay()->toDateString()]) }}" class="flex h-9 w-9 items-center justify-center rounded-lg text-lg text-brand-100/70 hover:bg-white/10 hover:text-white" aria-label="Previous day">‹</a>
                    <form method="GET" action="{{ route('editor-updates') }}">
                        <input type="date" name="date" value="{{ $day->toDateString() }}" max="{{ today()->toDateString() }}" onchange="this.form.submit()"
                               class="h-9 rounded-lg border-0 bg-transparent px-2 text-sm font-semibold text-white [color-scheme:dark] focus:ring-2 focus:ring-brand-400" aria-label="Pick a day">
                    </form>
                    @if ($isToday)
                        <span class="flex h-9 w-9 items-center justify-center text-lg text-brand-100/25" aria-hidden="true">›</span>
                    @else
                        <a href="{{ route('editor-updates', ['date' => $day->copy()->addDay()->toDateString()]) }}" class="flex h-9 w-9 items-center justify-center rounded-lg text-lg text-brand-100/70 hover:bg-white/10 hover:text-white" aria-label="Next day">›</a>
                    @endif
                </nav>
                @unless ($isToday)
                    <a href="{{ route('editor-updates') }}" class="inline-flex h-11 items-center rounded-xl bg-brand-400 px-4 text-sm font-bold text-brand-900 hover:bg-brand-300">Today</a>
                @endunless
            </div>
        </header>

        {{-- Read top-down: what needs you, each editor now, the day's flow (live,
             today), the queues to act on, the timeline; detail last. --}}
        <div class="space-y-7" x-data="editorTimeline(@js($timeline), @js(route('editor-updates.live', ['date' => $day->toDateString()])), @js(route('editor-updates.editor', ['user' => '__ID__', 'date' => $day->toDateString()])))">
            @include('editor-updates._now')

            @include('editor-updates._queue')

            @include('editor-updates._timeline')
        </div>

        {{-- A past day: its numbers (today's are in the live flow above) --}}
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4" @if ($isToday) hidden @endif>
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

        @if ($people->isNotEmpty())
            <h2 class="pt-2 text-xs font-bold uppercase tracking-[0.16em] text-brand-100/50">Video by video <span class="font-normal normal-case tracking-normal text-brand-100/40">— every move, tap a name to open</span></h2>
        @endif

        @foreach ($people as $p)
            <details class="group rounded-2xl bg-white/[0.03] ring-1 ring-white/10" x-data="{ still: false }" @if (! $isToday) open @endif>
                {{-- Who, and their day in a line --}}
                <summary class="flex cursor-pointer list-none flex-wrap items-center gap-3 px-4 py-3.5 group-open:border-b group-open:border-white/10 sm:px-5 [&::-webkit-details-marker]:hidden">
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
                        @foreach (['added' => 'added', 'started' => 'started', 'fixes' => 'corrections', 'held' => 'on hold', 'moved' => 'moved to next day', 'done' => 'done', 'posted' => 'posted'] as $k => $label)
                            @if ($p['stats'][$k] > 0)
                                <span class="rounded-full px-2 py-0.5 {{ $tone[['fixes' => 'fix', 'held' => 'hold'][$k] ?? $k] }}">{{ $p['stats'][$k] }} {{ $label }}</span>
                            @endif
                        @endforeach
                    </div>
                    <span class="text-brand-100/40 transition group-open:rotate-180">▾</span>
                </summary>

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
            </details>
        @endforeach
    </div>
</x-app-layout>
