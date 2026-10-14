{{--
    One editor's day (EditorUpdatesController::editor): their lane drawn large
    with each block named by its video, every video with its steps in order,
    and what is on hold and why. Opened from an editor's card on Editor Updates.
--}}
@php
    [$from, $to] = $range;
    $x = fn (int $ms) => max(0, min(100, ($ms - $from) / max(1, $to - $from) * 100));
    $hm = fn (int $m) => $m >= 60 ? intdiv($m, 60).'h '.($m % 60).'m' : $m.'m';
    $hours = [];
    for ($t = \Illuminate\Support\Carbon::createFromTimestampMs($from)->startOfHour(); $t->getTimestampMs() <= $to; $t->addHour()) {
        if ($t->getTimestampMs() >= $from) {
            $hours[] = ['x' => $x($t->getTimestampMs()), 'label' => $t->format('ga')];
        }
    }
    $icon = ['added' => '+', 'started' => '▶', 'fix' => '⚠', 'fixed' => '✓', 'hold' => '⏸', 'moved' => '→', 'done' => '●', 'ready' => '◷', 'posted' => '✓', 'other' => '•'];
    $markColor = ['added' => '#cfe2ea', 'started' => '#F4B740', 'fix' => '#F4B740', 'fixed' => '#34d399', 'hold' => '#c4b5fd', 'moved' => '#8aa6b4', 'done' => '#5BC0EB', 'ready' => '#c4b5fd', 'posted' => '#34d399', 'other' => '#8aa6b4'];
    $chip = [
        'started' => ['Editing', 'bg-amber-400/15 text-amber-200'], 'waiting' => ['To do', 'bg-white/10 text-brand-100/80'],
        'blocked' => ['On hold', 'bg-violet-400/15 text-violet-200'], 'review' => ['In review', 'bg-sky-400/15 text-sky-200'],
        'ready' => ['Video ready', 'bg-violet-400/15 text-violet-200'], 'posted' => ['Posted', 'bg-emerald-400/15 text-emerald-200'],
        'delivered' => ['Delivered', 'bg-teal-400/15 text-teal-200'], 'completed' => ['Done', 'bg-sky-400/15 text-sky-200'],
        'cancelled' => ['Removed', 'bg-white/5 text-brand-100/50'],
    ];
    $hue = crc32($editor->name) % 360;
    $state = $lane['state'] ?? 'idle';
    $stats = $person['stats'] ?? ['done' => 0, 'posted' => 0];
@endphp

<x-app-layout title="{{ $editor->name }} — Editor Updates">
    <div class="mx-auto max-w-6xl space-y-6 pb-16">
        <a href="{{ route('editor-updates', ['date' => $day->toDateString()]) }}" class="inline-flex min-h-[40px] items-center text-sm font-semibold text-brand-300 hover:text-white">‹ All editors</a>

        {{-- Who --}}
        <header class="flex flex-wrap items-center gap-5 rounded-[20px] border bg-[#15303f] px-6 py-5 {{ $state === 'working' ? 'border-amber-400/45' : 'border-white/[0.08]' }}">
            <span class="flex h-[72px] w-[72px] shrink-0 items-center justify-center rounded-full text-3xl font-extrabold text-[#0f2230]"
                  style="background: hsl({{ $hue }} 70% 58%); box-shadow: 0 0 0 4px #15303f, 0 0 0 7px {{ $state === 'working' ? '#F4B740' : ($state === 'hold' ? '#A78BFA' : '#7f9aa8') }};">{{ mb_strtoupper(mb_substr($editor->name, 0, 1)) }}</span>
            <div class="min-w-0 flex-1">
                <h1 class="text-2xl font-extrabold text-white sm:text-[26px]">{{ $editor->name }}</h1>
                <p class="mt-1 text-[15px] {{ $state === 'working' ? 'text-amber-200' : 'text-brand-100/70' }}">
                    @if ($state === 'working')
                        <strong class="font-semibold">Editing</strong> {{ $lane['doing'] }}
                    @elseif ($state === 'hold')
                        Everything on hold
                    @elseif ($lane && $lane['doing'])
                        Last: {{ $lane['doing'] }}
                    @else
                        No moves {{ $day->isToday() ? 'yet today' : 'that day' }}
                    @endif
                    · {{ $day->isToday() ? 'today' : $day->format('l j M') }}
                </p>
            </div>
            <div class="grid grid-cols-4 gap-2.5 text-center">
                @foreach ([[$stats['done'] ?? 0, 'done', 'text-sky-300'], [$stats['posted'] ?? 0, 'out', 'text-emerald-300'], [$holds->count(), 'on hold', 'text-violet-300'], [$hm($person['minutes'] ?? 0), 'editing', 'text-white']] as [$n, $label, $color])
                    <div class="min-w-[84px] rounded-[14px] bg-[#1a3a4b] px-2 py-2.5">
                        <p class="text-2xl font-extrabold {{ $color }}">{{ $n }}</p>
                        <p class="text-xs text-brand-100/65">{{ $label }}</p>
                    </div>
                @endforeach
            </div>
        </header>

        {{-- The day, large --}}
        <section class="rounded-[20px] border border-white/[0.08] bg-[#15303f] px-6 py-5">
            <h2 class="text-[17px] font-bold text-white">{{ $day->isToday() ? 'The day so far' : 'The day' }}</h2>
            <div class="overflow-x-auto">
                <div class="min-w-[720px]">
                    <div class="relative mt-3 h-5 text-[11px] text-brand-100/45">
                        @foreach ($hours as $h)
                            <span class="absolute -translate-x-1/2" style="left: {{ $h['x'] }}%">{{ $h['label'] }}</span>
                        @endforeach
                    </div>
                    <div class="relative mt-1 h-24 rounded-[14px] bg-[#0f2230]">
                        @foreach ($lane['blocks'] ?? [] as $b)
                            <span class="absolute top-10 h-10 overflow-hidden truncate rounded-[10px] px-2 py-1 text-[11px] font-semibold text-[#0f2230] {{ $b['type'] === 'hold' ? 'text-violet-100' : '' }}"
                                  style="left: {{ $x($b['from']) }}%; width: {{ max(0.6, $x($b['to']) - $x($b['from'])) }}%; {{ $b['type'] === 'work' ? 'background: #F4B740;' : 'background: repeating-linear-gradient(-45deg, rgba(167,139,250,.6) 0 4px, rgba(167,139,250,.25) 4px 8px);' }}"
                                  title="{{ ($b['type'] === 'work' ? 'Editing · ' : 'On hold · ').$b['title'] }}">{{ $b['type'] === 'work' ? $b['title'] : 'Hold' }}</span>
                        @endforeach
                        @foreach ($lane['marks'] ?? [] as $m)
                            <span class="absolute top-2 flex h-[22px] w-[22px] -translate-x-1/2 items-center justify-center rounded-full border bg-[#15303f] text-[11px] font-bold"
                                  style="left: {{ $x($m['at']) }}%; border-color: {{ $markColor[$m['type']] ?? '#8aa6b4' }}; color: {{ $markColor[$m['type']] ?? '#8aa6b4' }};"
                                  title="{{ $m['time'] }} · {{ $m['label'] }}{{ $m['detail'] ? ' ('.$m['detail'].')' : '' }} — {{ $m['title'] }}">{{ $m['label'] === 'Delivered' ? '◆' : ($icon[$m['type']] ?? '•') }}</span>
                        @endforeach
                        @if ($now)
                            <span class="absolute -bottom-1 -top-1 w-0.5 rounded bg-red-400" style="left: {{ $x($now) }}%"></span>
                        @endif
                        @if (empty($lane['blocks']) && empty($lane['marks']))
                            <p class="absolute inset-0 flex items-center justify-center text-sm text-brand-100/40">Nothing yet</p>
                        @endif
                    </div>
                </div>
            </div>
        </section>

        <div class="grid gap-5 lg:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)]">
            {{-- Every video, its steps --}}
            <section class="overflow-hidden rounded-[20px] border border-white/[0.08] bg-[#15303f]">
                <h2 class="px-6 py-4 text-[17px] font-bold text-white">Every video {{ $day->isToday() ? 'today' : 'that day' }}</h2>
                @forelse ($person['moved'] ?? [] as $v)
                    @php [$label, $cls] = $chip[$v['status']] ?? [ucfirst($v['status']), 'bg-white/10 text-brand-100/80']; @endphp
                    <div class="space-y-2.5 border-t border-white/[0.06] px-6 py-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-[15px] font-semibold text-white">{{ $v['title'] }}</p>
                                <p class="text-xs text-brand-100/50">{{ $v['client'] }}@if ($v['minutes'] > 0) · {{ $hm($v['minutes']) }} editing @endif</p>
                            </div>
                            <span class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $cls }}">{{ $label }}</span>
                        </div>
                        <ol class="flex flex-wrap items-center gap-1.5">
                            @foreach ($v['events'] as $e)
                                <li class="inline-flex max-w-full items-center gap-1.5 rounded-lg bg-[#1a3a4b] px-2.5 py-1 text-xs">
                                    <span class="tabular-nums text-brand-100/50">{{ $e['at']->format('g:i') }}</span>
                                    <span class="truncate font-semibold" style="color: {{ $markColor[$e['type']] ?? '#cfe2ea' }}">{{ $e['label'] }}{{ $e['detail'] && in_array($e['type'], ['fix', 'hold', 'ready'], true) ? ': '.\Illuminate\Support\Str::limit($e['detail'], 40) : '' }}</span>
                                </li>
                            @endforeach
                        </ol>
                    </div>
                @empty
                    <p class="border-t border-white/[0.06] px-6 py-8 text-center text-sm text-brand-100/45">No moves {{ $day->isToday() ? 'yet today' : 'that day' }}.</p>
                @endforelse
                @if (($person['still'] ?? collect())->isNotEmpty())
                    <div class="border-t border-white/[0.06] px-6 py-4">
                        <p class="text-xs font-semibold uppercase tracking-wider text-brand-100/45">On the board, untouched</p>
                        <ul class="mt-2 space-y-1 text-sm text-brand-100/75">
                            @foreach ($person['still'] as $v)
                                <li class="truncate">{{ $v['title'] }} <span class="text-xs text-brand-100/40">· {{ ($chip[$v['status']] ?? [ucfirst($v['status'])])[0] }}</span></li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </section>

            {{-- On hold, and why --}}
            <section class="h-fit rounded-[20px] border border-violet-400/30 bg-[#15303f] px-6 py-5">
                <h2 class="text-[17px] font-bold text-white">On hold <span class="ml-1.5 rounded-full bg-violet-400/20 px-2.5 py-0.5 text-[13px] text-violet-200">{{ $holds->count() }}</span></h2>
                <div class="mt-3 space-y-2.5">
                    @forelse ($holds as $h)
                        <div class="rounded-[14px] bg-[#1a3a4b] p-3.5">
                            <p class="text-sm font-semibold text-white">{{ $h['title'] }}</p>
                            @if ($h['note'])<p class="mt-0.5 text-[13px] text-violet-300">“{{ $h['note'] }}”</p>@endif
                            @if ($h['since'])<p class="mt-0.5 text-xs text-brand-100/50">on hold {{ $hm((int) $h['since']->diffInMinutes(now())) }}</p>@endif
                        </div>
                    @empty
                        <p class="rounded-[14px] border border-dashed border-white/15 px-4 py-6 text-center text-sm text-brand-100/45">Nothing on hold.</p>
                    @endforelse
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
