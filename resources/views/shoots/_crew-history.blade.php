{{--
    Past shoots, month by month: the record on My Shoots and on Shoots →
    Crew record. See App\Support\CrewShootRecord.

    $shoots  past shoots, newest first (CrewShootRecord::shoots)
    $person  whose record this is (left out of "with ..."), or null
--}}
@php
    use App\Support\CrewShootRecord;
    $summary = CrewShootRecord::summary($shoots);
    $canOpen = auth()->user()?->can('shoots.view');
    $hm = fn (int $m) => $m >= 60 ? intdiv($m, 60).'h'.($m % 60 ? ' '.($m % 60).'m' : '') : $m.'m';
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
            Since {{ $summary['first']->format('F Y') }}@if ($summary['clients']) · mostly for {{ implode(', ', $summary['clients']) }}@endif
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
                    @endphp
                    <li class="flex gap-3 px-4 py-3">
                        <div class="w-10 shrink-0 text-center">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-brand-300">{{ $shoot->starts_at->format('D') }}</p>
                            <p class="text-lg font-bold leading-tight tabular-nums text-white">{{ $shoot->starts_at->format('j') }}</p>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-2">
                                @if ($canOpen)
                                    <a href="{{ route('shoots.show', $shoot) }}" class="min-w-0 truncate font-semibold text-white hover:underline">{{ $shoot->title }}</a>
                                @else
                                    <p class="min-w-0 truncate font-semibold text-white">{{ $shoot->title }}</p>
                                @endif
                                @if ($unmarked)
                                    <span class="shrink-0 rounded-full bg-white/10 px-2 py-0.5 text-[11px] font-semibold text-brand-100/60" title="The shoot's status was never moved to Completed">Not marked done</span>
                                @endif
                            </div>
                            <p class="truncate text-xs text-brand-100/60">{{ $shoot->client?->name ?? 'No client' }}@if ($shoot->location) · {{ $shoot->location }}@endif</p>
                            <p class="mt-0.5 text-xs text-brand-100/45">
                                @if ($others->isNotEmpty()){{ $person ? 'With ' : '' }}{{ $others->implode(', ') }}@else{{ $person ? 'On your own' : 'No crew listed' }}@endif
                                @if ($shoot->videos_count) · {{ $shoot->videos_count }} {{ Str::plural('video', $shoot->videos_count) }} logged @endif
                                @if ($onSite > 0) · {{ $hm($onSite) }} shooting @endif
                            </p>
                        </div>
                    </li>
                @endforeach
            </ul>
        </details>
    @empty
        <div class="rounded-xl border border-dashed border-white/15 px-6 py-6 text-center text-sm text-brand-100/60">No past shoots yet.</div>
    @endforelse
</div>
