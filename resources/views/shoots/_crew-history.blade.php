{{--
    Past shoots, month by month: the record on My Shoots and on Shoots →
    Crew record. See App\Support\CrewShootRecord. Each shoot opens to show
    its videos: the Reel Planner reels linked to it in Notion, and any
    videos the crew logged in the app on the day.

    $shoots  past shoots, newest first (CrewShootRecord::shoots)
    $person  whose record this is (left out of "with ..."), or null
--}}
@php
    use App\Support\CrewShootRecord;
    $summary = CrewShootRecord::summary($shoots);
    $canOpen = auth()->user()?->can('shoots.view');
    $hm = fn (int $m) => $m >= 60 ? intdiv($m, 60).'h'.($m % 60 ? ' '.($m % 60).'m' : '') : $m.'m';
    $chip = fn (?string $status) => match (true) {
        in_array($status, ['Published', 'Delivered'], true) => 'bg-emerald-400/15 text-emerald-200',
        $status === 'Canceled' => 'bg-red-400/15 text-red-200',
        in_array($status, ['Scheduled', 'Video Ready'], true) => 'bg-sky-400/15 text-sky-200',
        default => 'bg-amber-400/15 text-amber-200',
    };
@endphp

<div class="space-y-3">
    <div class="grid grid-cols-3 gap-2">
        @foreach ([['Total', $summary['total']], ['This month', $summary['this_month']], ['Last month', $summary['last_month']]] as [$label, $n])
            <div class="rounded-xl bg-white/5 px-3 py-2.5 ring-1 ring-white/10">
                <p class="text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-100/50">{{ $label }}</p>
                <p class="mt-0.5 text-2xl font-bold tabular-nums text-white">{{ $n }}</p>
            </div>
        @endforeach
    </div>
    @if ($summary['total'] > 0)
        <p class="text-xs text-brand-100/55">
            Since {{ $summary['first']->format('F Y') }}
            @if ($summary['videos']) · {{ $summary['videos'] }} {{ Str::plural('video', $summary['videos']) }} came out of these shoots @endif
            @if ($summary['clients']) · mostly for {{ implode(', ', $summary['clients']) }}@endif
        </p>
    @endif

    @forelse (CrewShootRecord::byMonth($shoots) as $month => $inMonth)
        <details class="group rounded-2xl bg-white/[0.03] ring-1 ring-white/10" @if ($loop->index < 2) open @endif>
            <summary class="flex min-h-[48px] cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 [&::-webkit-details-marker]:hidden">
                <span class="font-semibold text-white">{{ $month }}</span>
                <span class="flex items-center gap-2 text-xs text-brand-100/60">
                    {{ $inMonth->count() }} {{ Str::plural('shoot', $inMonth->count()) }}
                    <span class="transition group-open:rotate-180" aria-hidden="true">▾</span>
                </span>
            </summary>
            <ul class="divide-y divide-white/5 border-t border-white/10">
                @foreach ($inMonth as $shoot)
                    @php
                        $others = $shoot->crew->map(fn ($c) => $c->user)->filter()
                            ->reject(fn ($u) => $person && $u->id === $person->id)->pluck('name');
                        $onSite = $shoot->started_at && $shoot->finished_at ? (int) $shoot->started_at->diffInMinutes($shoot->finished_at) : 0;
                        $unmarked = in_array($shoot->status, [\App\Models\Shoot::STATUS_PLANNED, \App\Models\Shoot::STATUS_CONFIRMED], true);
                        $reels = CrewShootRecord::reels($shoot);
                        $logged = $shoot->videos;
                        $planned = CrewShootRecord::planned($shoot);
                        $published = $reels->whereIn('status', ['Published', 'Delivered'])->count();
                        $count = max($reels->count(), $logged->count());
                    @endphp
                    <li>
                        <details class="group/shoot">
                            <summary class="flex cursor-pointer list-none gap-3 px-4 py-3 hover:bg-white/[0.02] [&::-webkit-details-marker]:hidden">
                                <div class="w-10 shrink-0 text-center">
                                    <p class="text-[10px] font-semibold uppercase tracking-wider text-brand-300">{{ $shoot->starts_at->format('D') }}</p>
                                    <p class="text-lg font-bold leading-tight tabular-nums text-white">{{ $shoot->starts_at->format('j') }}</p>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-start justify-between gap-2">
                                        <p class="min-w-0 truncate font-semibold text-white">{{ $shoot->title }}</p>
                                        @if ($unmarked)
                                            <span class="shrink-0 rounded-full bg-white/10 px-2 py-0.5 text-[11px] font-semibold text-brand-100/60" title="The shoot's status was never moved to Completed">Not marked done</span>
                                        @endif
                                    </div>
                                    <p class="truncate text-xs text-brand-100/60">{{ $shoot->client?->name ?? 'No client' }}@if ($shoot->location) · {{ $shoot->location }}@endif</p>
                                    <p class="mt-0.5 text-xs text-brand-100/45">
                                        @if ($others->isNotEmpty()){{ $person ? 'With ' : '' }}{{ $others->implode(', ') }}@else{{ $person ? 'On your own' : 'No crew listed' }}@endif
                                        @if ($onSite > 0) · {{ $hm($onSite) }} shooting @endif
                                    </p>
                                    <p class="mt-1 flex items-center gap-1.5 text-xs font-semibold {{ $count ? 'text-brand-200' : 'text-brand-100/40' }}">
                                        <span aria-hidden="true">🎬</span>
                                        @if ($count)
                                            {{ $count }} {{ Str::plural('video', $count) }}@if ($planned && $planned !== $count) of {{ $planned }} planned @endif
                                            @if ($published) · {{ $published }} published @endif
                                            <span class="ml-auto text-brand-100/40 transition group-open/shoot:rotate-180" aria-hidden="true">▾</span>
                                        @else
                                            No videos linked yet @if ($planned) ({{ $planned }} planned) @endif
                                        @endif
                                    </p>
                                </div>
                            </summary>

                            @if ($count || $canOpen)
                                <div class="space-y-3 px-4 pb-4 pl-[4.25rem]">
                                    @if ($reels->isNotEmpty())
                                        <div>
                                            <p class="mb-1.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-100/45">From the Reel Planner</p>
                                            <ul class="space-y-1.5">
                                                @foreach ($reels as $reel)
                                                    <li class="flex items-center justify-between gap-2 rounded-lg bg-white/[0.04] px-2.5 py-2">
                                                        <span class="min-w-0">
                                                            <span class="block truncate text-sm text-white">{{ $reel->title }}</span>
                                                            @if ($reel->published_date)
                                                                <span class="block text-[11px] text-brand-100/50">{{ $reel->published_date->isPast() && ! $reel->published_date->isToday() ? 'Went out' : 'Goes out' }} {{ $reel->published_date->format('D j M') }}</span>
                                                            @endif
                                                        </span>
                                                        <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $chip($reel->status) }}">{{ $reel->status ?? '—' }}</span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endif
                                    @if ($logged->isNotEmpty())
                                        <div>
                                            <p class="mb-1.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-100/45">Logged on the day</p>
                                            <ul class="space-y-1.5">
                                                @foreach ($logged as $video)
                                                    <li class="flex items-center gap-2.5 rounded-lg bg-white/[0.04] p-2">
                                                        @if ($video->photoUrl())
                                                            <a href="{{ $video->photoUrl() }}" target="_blank" rel="noopener" class="shrink-0">
                                                                <img src="{{ $video->photoUrl() }}" alt="" loading="lazy" class="h-11 w-11 rounded-md object-cover">
                                                            </a>
                                                        @else
                                                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-md bg-white/5 text-xs text-brand-100/40">{{ $video->position }}</span>
                                                        @endif
                                                        <span class="min-w-0">
                                                            <span class="block truncate text-sm text-white">{{ $video->name }}</span>
                                                            <span class="block truncate text-[11px] text-brand-100/50">{{ $video->created_at->format('g:i A') }}@if ($video->recordedBy) · {{ $video->recordedBy->name }}@endif @if ($video->notes) · {{ $video->notes }}@endif</span>
                                                        </span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endif
                                    @if ($canOpen)
                                        <a href="{{ route('shoots.show', $shoot) }}" class="inline-block text-xs font-semibold text-brand-200 hover:text-white">Open the shoot →</a>
                                    @endif
                                </div>
                            @endif
                        </details>
                    </li>
                @endforeach
            </ul>
        </details>
    @empty
        <div class="rounded-xl border border-dashed border-white/15 px-6 py-6 text-center text-sm text-brand-100/60">No past shoots yet.</div>
    @endforelse
</div>
