{{-- The Work Report: a day, a week or a month. WorkReportController / App\Support\WorkReport. --}}
@php
    use App\Support\WorkReport;
    $hm = fn (int $m) => WorkReport::hm($m);
    $t = $r->totals();
    $people = $r->people();
    $v = $r->videos();
    $shoots = $r->shoots();
    $clients = $r->clients();
    $types = $r->types();
    $daily = $r->period === 'day' ? collect() : $r->daily();
    $maxDay = max(1, (int) $daily->max());
    $maxClient = max(1, (int) $clients->max());
    $typeTone = ['Shooting' => 'bg-red-400', 'Editing' => 'bg-sky-400', 'Posting' => 'bg-emerald-400', 'Other Task' => 'bg-white/40'];
    $q = fn (array $x) => route('work-report', array_merge(['period' => $r->period, 'date' => $r->from->toDateString()], $x));
@endphp
<x-app-layout title="Work Report">
    <div class="mx-auto max-w-6xl space-y-6 pb-16">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-300">Team · Report</p>
                <h1 class="mt-1 text-[30px] font-extrabold tracking-tight text-white">Work Report</h1>
                <p class="text-sm text-brand-100/70">{{ $r->label() }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <nav class="inline-flex rounded-xl bg-white/[0.06] p-1 ring-1 ring-white/10">
                    @foreach (WorkReport::PERIODS as $key => $label)
                        <a href="{{ route('work-report', ['period' => $key, 'date' => $r->from->toDateString()]) }}"
                           class="h-9 rounded-lg px-4 text-sm font-semibold leading-9 {{ $r->period === $key ? 'bg-white text-brand-900' : 'text-brand-100/70 hover:text-white' }}">{{ $label }}</a>
                    @endforeach
                </nav>
                <nav class="inline-flex items-center rounded-xl bg-white/[0.06] p-1 ring-1 ring-white/10">
                    <a href="{{ $q(['date' => $r->previous()]) }}" class="flex h-9 w-9 items-center justify-center rounded-lg text-lg text-brand-100/70 hover:bg-white/10" aria-label="Earlier">‹</a>
                    <a href="{{ route('work-report', ['period' => $r->period]) }}" class="h-9 rounded-lg px-3 text-sm font-semibold leading-9 text-brand-100/80 hover:text-white">Now</a>
                    @if ($r->next())
                        <a href="{{ $q(['date' => $r->next()]) }}" class="flex h-9 w-9 items-center justify-center rounded-lg text-lg text-brand-100/70 hover:bg-white/10" aria-label="Later">›</a>
                    @else
                        <span class="flex h-9 w-9 items-center justify-center text-lg text-brand-100/20">›</span>
                    @endif
                </nav>
            </div>
        </header>

        {{-- The numbers --}}
        <section class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
            @foreach ([
                ['Hours logged', $hm($t['minutes']), 'text-white'],
                ['People', $t['people'], 'text-white'],
                ['Sent for review', $t['videos_done'], 'text-sky-200'],
                ['Posted', $t['posted'], 'text-emerald-200'],
                ['Delivered', $v['delivered']->count(), 'text-teal-200'],
                ['Cancelled', $t['cancelled'], $t['cancelled'] ? 'text-red-300' : 'text-white'],
                ['Shoots', $t['shoots'], 'text-amber-200'],
            ] as [$label, $value, $tone])
                <div class="rounded-2xl bg-white/[0.05] px-4 py-3 ring-1 ring-white/10">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-brand-100/50">{{ $label }}</p>
                    <p class="mt-0.5 text-2xl font-bold tabular-nums {{ $tone }}">{{ $value }}</p>
                </div>
            @endforeach
        </section>

        {{-- Hours by day (week / month) --}}
        @if ($daily->isNotEmpty())
            <x-card padding="md">
                <x-section-heading title="Hours by day" :subtitle="'Team total · '.$hm($t['minutes'])" />
                <div class="flex h-40 items-end gap-1">
                    @foreach ($daily as $date => $m)
                        @php $d = \Illuminate\Support\Carbon::parse($date); @endphp
                        <a href="{{ route('work-report', ['period' => 'day', 'date' => $date]) }}" class="group flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1" title="{{ $d->format('D j M') }} · {{ $hm($m) }}">
                            <span class="hidden text-[10px] tabular-nums text-brand-100/50 group-hover:block">{{ $hm($m) }}</span>
                            <span class="w-full rounded-t-md {{ $d->isSunday() ? 'bg-white/10' : 'bg-brand-400/80 group-hover:bg-brand-300' }}" style="height: {{ max(2, round($m / $maxDay * 100)) }}%"></span>
                            <span class="text-[10px] text-brand-100/45">{{ $r->period === 'week' ? $d->format('D') : $d->format('j') }}</span>
                        </a>
                    @endforeach
                </div>
            </x-card>
        @endif

        {{-- People --}}
        <x-card padding="md">
            <x-section-heading title="People" subtitle="Hours logged, videos, and the days with no timesheet (Sundays not counted)" />
            <div class="-mx-2 overflow-x-auto">
                <table class="w-full min-w-[720px] text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wider text-brand-100/45">
                            <th class="px-2 py-2 font-semibold">Name</th>
                            <th class="px-2 py-2 font-semibold">Hours</th>
                            <th class="px-2 py-2 font-semibold">Split</th>
                            <th class="px-2 py-2 font-semibold">Days</th>
                            <th class="px-2 py-2 font-semibold">For review</th>
                            <th class="px-2 py-2 font-semibold">Posted</th>
                            <th class="px-2 py-2 font-semibold">Cancelled</th>
                            <th class="px-2 py-2 font-semibold">No timesheet</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        @forelse ($people as $p)
                            <tr>
                                <td class="px-2 py-2.5 font-semibold text-white">
                                    <a href="{{ route('timesheets.show', $p['user']) }}" class="hover:underline">{{ $p['user']->name }}</a>
                                </td>
                                <td class="px-2 py-2.5 tabular-nums text-white">{{ $hm($p['minutes']) }}</td>
                                <td class="px-2 py-2.5">
                                    @if ($p['minutes'] > 0)
                                        <div class="flex h-2.5 w-36 overflow-hidden rounded-full bg-white/10" title="@foreach ($p['by_type'] as $k => $m){{ (\App\Models\TimesheetEntry::TASK_TYPES[$k] ?? 'Other') }} {{ $hm($m) }} · @endforeach">
                                            @foreach ($p['by_type'] as $k => $m)
                                                <span class="{{ $typeTone[\App\Models\TimesheetEntry::TASK_TYPES[$k] ?? 'Other Task'] ?? 'bg-white/40' }}" style="width: {{ $m / $p['minutes'] * 100 }}%"></span>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                                <td class="px-2 py-2.5 tabular-nums text-brand-100/80">{{ $p['days_logged'] }}</td>
                                <td class="px-2 py-2.5 tabular-nums text-sky-200">{{ $p['videos_done'] ?: '—' }}</td>
                                <td class="px-2 py-2.5 tabular-nums text-emerald-200">{{ $p['posted'] ?: '—' }}</td>
                                <td class="px-2 py-2.5 tabular-nums {{ $p['cancelled'] ? 'text-red-300' : 'text-brand-100/40' }}">{{ $p['cancelled'] ?: '—' }}</td>
                                <td class="px-2 py-2.5 text-xs {{ $p['missing']->isNotEmpty() ? 'text-amber-300' : 'text-emerald-300' }}">
                                    @if ($p['missing']->isEmpty())
                                        ✓ every day
                                    @elseif ($r->period === 'day')
                                        Not filled
                                    @else
                                        {{ $p['missing']->count() }} {{ Str::plural('day', $p['missing']->count()) }}:
                                        {{ $p['missing']->take(6)->map(fn ($d) => \Illuminate\Support\Carbon::parse($d)->format($r->period === 'week' ? 'D' : 'j M'))->implode(', ') }}{{ $p['missing']->count() > 6 ? '…' : '' }}
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-2 py-6 text-center text-brand-100/50">No work logged in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($types->isNotEmpty())
                <div class="mt-3 flex flex-wrap gap-3 text-xs text-brand-100/60">
                    @foreach ($types as $label => $m)
                        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full {{ $typeTone[$label] ?? 'bg-white/40' }}"></span>{{ $label }} {{ $hm($m) }}</span>
                    @endforeach
                </div>
            @endif
        </x-card>

        <div class="grid gap-6 lg:grid-cols-2">
            {{-- Clients --}}
            <x-card padding="md">
                <x-section-heading title="Hours by client" :subtitle="$clients->count().' '.Str::plural('client', $clients->count())" />
                <ul class="space-y-2">
                    @forelse ($clients->take(15) as $name => $m)
                        <li>
                            <div class="flex justify-between text-sm"><span class="truncate text-white">{{ $name }}</span><span class="tabular-nums text-brand-100/70">{{ $hm($m) }}</span></div>
                            <div class="mt-1 h-1.5 rounded-full bg-white/10"><div class="h-full rounded-full bg-brand-400" style="width: {{ $m / $maxClient * 100 }}%"></div></div>
                        </li>
                    @empty
                        <li class="text-sm text-brand-100/50">Nothing logged.</li>
                    @endforelse
                </ul>
            </x-card>

            {{-- Shoots --}}
            <x-card padding="md">
                <x-section-heading title="Shoots" :subtitle="$shoots->count().' '.Str::plural('shoot', $shoots->count())" />
                <ul class="divide-y divide-white/5">
                    @forelse ($shoots as $s)
                        <li class="flex items-center justify-between gap-3 py-2 text-sm">
                            <a href="{{ route('shoots.show', $s['shoot']) }}" class="min-w-0 truncate text-white hover:underline">{{ $s['shoot']->title }}</a>
                            <span class="shrink-0 text-xs text-brand-100/60">{{ $s['shoot']->started_at->format($r->period === 'day' ? 'g:i A' : 'D j M') }} · {{ $s['minutes'] !== null ? $hm($s['minutes']) : 'rolling' }} · {{ $s['shoot']->videos_count }} {{ Str::plural('video', $s['shoot']->videos_count) }}</span>
                        </li>
                    @empty
                        <li class="py-2 text-sm text-brand-100/50">No shoots.</li>
                    @endforelse
                </ul>
            </x-card>
        </div>

        {{-- The YouTube Short duty --}}
        @php $shorts = $r->shorts(); @endphp
        <x-card padding="md">
            <x-section-heading title="YouTube Shorts" subtitle="Every reel posted on Instagram goes up on YouTube too (same video, no copyrighted music). On time = within a day." />
            @if ($shorts->isEmpty())
                <p class="text-sm text-brand-100/50">No reels posted in this period.</p>
            @else
                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($shorts as $s)
                        <div class="flex items-center justify-between gap-3 rounded-xl px-3 py-2.5 {{ $s['missing'] ? 'bg-red-500/10 ring-1 ring-red-400/30' : 'bg-white/[0.04]' }}">
                            <span class="font-semibold text-white">{{ $s['user']->name }}</span>
                            <span class="text-xs tabular-nums">
                                <span class="text-emerald-300">✓ {{ $s['on_time'] }}</span>
                                @if ($s['late']) · <span class="text-amber-300">late {{ $s['late'] }}</span>@endif
                                @if ($s['missing']) · <span class="font-bold text-red-300">missing {{ $s['missing'] }}</span>@endif
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-card>

        {{-- Cancelled videos, with why --}}
        <x-card padding="md">
            <x-section-heading title="Cancelled videos" subtitle="Called off, and how far they had got — after editing is time spent for nothing" />
            <ul class="divide-y divide-white/5">
                @forelse ($v['cancelled'] as $c)
                    <li class="py-2.5 text-sm">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <a href="{{ route('videos.card', $c['todo']) }}" class="font-semibold text-white hover:underline">{{ $c['todo']->title }}</a>
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $c['edited'] ? 'bg-red-500/15 text-red-200' : 'bg-white/10 text-brand-100/60' }}">{{ $c['edited'] ? 'after editing' : $c['stage'] }}</span>
                        </div>
                        <p class="mt-0.5 text-xs text-brand-100/60">{{ $c['todo']->user?->name }} · {{ $c['todo']->venture }} · {{ $c['at']->format('D j M, g:i A') }}@if ($c['by']) · by {{ $c['by'] }}@endif</p>
                        @if ($c['reason'])<p class="mt-0.5 text-xs text-brand-100/80">“{{ $c['reason'] }}”</p>@endif
                    </li>
                @empty
                    <li class="py-2 text-sm text-brand-100/50">Nothing cancelled. 🎉</li>
                @endforelse
            </ul>
        </x-card>

        {{-- Videos --}}
        <div class="grid gap-6 lg:grid-cols-2">
            <x-card padding="md">
                <x-section-heading title="Posted" :subtitle="$v['posted']->count().' posted · '.$v['delivered']->count().' delivered'" />
                <ul class="divide-y divide-white/5">
                    @forelse ($v['posted']->concat($v['delivered'])->sortBy('posted_at') as $p)
                        <li class="flex justify-between gap-3 py-2 text-sm">
                            <a href="{{ route('videos.card', $p) }}" class="min-w-0 truncate text-white hover:underline">{{ $p->title }}</a>
                            <span class="shrink-0 text-xs text-brand-100/60">{{ \App\Models\Todo::KINDS[$p->kind] ?? 'Reel' }} · {{ $p->user?->name }} · {{ $p->posted_at->format($r->period === 'day' ? 'g:i A' : 'D j M') }}</span>
                        </li>
                    @empty
                        <li class="py-2 text-sm text-brand-100/50">Nothing posted.</li>
                    @endforelse
                </ul>
            </x-card>
            <x-card padding="md">
                <x-section-heading title="Corrections asked" :subtitle="$v['corrections']->count().' '.Str::plural('correction', $v['corrections']->count())" />
                <ul class="divide-y divide-white/5">
                    @forelse ($v['corrections']->groupBy(fn ($c) => $c->parent?->title ?? 'Video') as $title => $fixes)
                        <li class="py-2 text-sm">
                            <span class="text-white">{{ $title }}</span>
                            <span class="ml-1 rounded-full bg-orange-400/15 px-2 text-xs text-orange-200">{{ $fixes->count() }}</span>
                        </li>
                    @empty
                        <li class="py-2 text-sm text-brand-100/50">No corrections.</li>
                    @endforelse
                </ul>
            </x-card>
        </div>
    </div>
</x-app-layout>
