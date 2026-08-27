@php
    use App\Models\TimesheetEntry;
    use App\Support\EditorThroughput as ET;
    use App\Support\TimesheetAnomalies as TA;

    /*
     * Two questions on one screen, kept apart on purpose: what the editors
     * produced by planner, and what in the timesheet cannot be believed. The
     * second is upstream of the first -- hours sit beside counts, and a wrong
     * hour still pollutes the compare -- so the warning sits above the figures
     * rather than under them.
     */
    $tierBar = [
        ET::TIER_HIGH => 'bg-red-400',
        ET::TIER_MEDIUM => 'bg-amber-400',
        ET::TIER_LOW => 'bg-emerald-400',
        ET::TIER_NONE => 'bg-white/20',
    ];

    $severityTone = [
        TA::SEVERITY_HIGH => ['ring' => 'ring-red-400/30', 'bg' => 'bg-red-400/10', 'chip' => 'bg-red-400/15 text-red-200', 'label' => 'Cannot be true'],
        TA::SEVERITY_MEDIUM => ['ring' => 'ring-amber-400/30', 'bg' => 'bg-amber-400/10', 'chip' => 'bg-amber-400/15 text-amber-200', 'label' => 'Worth a look'],
        TA::SEVERITY_LOW => ['ring' => 'ring-white/10', 'bg' => 'bg-white/5', 'chip' => 'bg-white/10 text-brand-100/70', 'label' => 'Incomplete'],
    ];

    $highCount = ($flagsBySeverity[TA::SEVERITY_HIGH] ?? collect())->count();
    $plannerKeys = ET::PLANNERS;
@endphp

<x-app-layout title="Editor output">
    <x-slot name="header">
        <x-page-header title="Editor Output" eyebrow="Studio"
                       subtitle="Planner counts (Reels, Posts, Stories) beside editing hours from the timesheet. YouTube is omitted — same videos as Reels.">
            <x-slot name="actions">
                <form method="GET" class="inline">
                    <x-select name="months" onchange="this.form.submit()">
                        @foreach ($periods as $value => $label)
                            <option value="{{ $value }}" @selected($months === $value)>{{ $label }}</option>
                        @endforeach
                    </x-select>
                </form>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-6">

        {{-- ——— Where the numbers come from, and what is missing from them ——— --}}
        <x-card class="p-4 sm:p-5 border border-white/10">
            <div class="flex flex-wrap gap-x-8 gap-y-2 text-xs text-brand-100/70">
                <span><span class="font-semibold text-white">{{ $from->format('M Y') }} – {{ $to->format('M Y') }}</span></span>
                @foreach ($plannerKeys as $source)
                    <span>
                        <span class="font-semibold text-white tabular-nums">{{ number_format($throughput['byPlannerTotals'][$source]) }}</span>
                        {{ ET::PLANNER_LABELS[$source] }}
                    </span>
                @endforeach
                <span>
                    Notion last synced
                    <span class="font-semibold text-white">{{ $throughput['lastSynced'] ? \Illuminate\Support\Carbon::parse($throughput['lastSynced'])->diffForHumans() : 'never' }}</span>
                </span>
            </div>

            @if ($throughput['unattributedItems'] > 0 || $throughput['shared'] > 0)
                <p class="mt-2.5 text-xs text-brand-100/60">
                    @if ($throughput['unattributedItems'] > 0)
                        {{ $throughput['unattributedItems'] }} published items name no editor and are counted in the studio totals only.
                    @endif
                    @if ($throughput['shared'] > 0)
                        {{ $throughput['shared'] }} were co-edited and are left out of everyone's counts rather than credited twice.
                    @endif
                </p>
            @endif
        </x-card>

        {{-- The warning goes above the figures because it is upstream of them. --}}
        @if ($highCount > 0)
            <a href="#suspect"
               class="flex items-start gap-3.5 rounded-xl bg-red-400/10 ring-1 ring-red-400/30 p-4 sm:p-5 hover:bg-red-400/15 transition-colors">
                <span class="shrink-0 inline-flex items-center justify-center w-9 h-9 rounded-lg bg-red-400/15 text-red-300">
                    <x-icon name="alert" class="w-5 h-5" />
                </span>
                <div class="min-w-0">
                    <p class="font-semibold text-red-200">
                        {{ $highCount }} timesheet {{ Str::plural('entry', $highCount) }} in this period cannot be true
                    </p>
                    <p class="mt-1 text-sm text-red-200/80">
                        Hours below that sit beside the counts — treat those people's compare carefully. Details at the bottom &rarr;
                    </p>
                </div>
            </a>
        @endif

        {{-- ——— Month by month: each month lists editors with planner counts vs hours ——— --}}
        @if ($throughput['months']->every(fn ($m) => $m['editors']->isEmpty() && $m['minutes'] === 0 && array_sum($m['byPlanner']) === 0))
            <x-empty-state message="Nothing published and no editing logged in this period." />
        @else
            <div class="space-y-8">
                @foreach ($throughput['months'] as $month)
                    @php
                        $monthPublished = array_sum($month['byPlanner']);
                    @endphp
                    <section @if ($month['isCurrent']) id="this-month" @endif class="scroll-mt-6">
                        <div class="flex flex-wrap items-end justify-between gap-3 mb-3.5">
                            <div class="min-w-0">
                                <h2 class="flex flex-wrap items-center gap-2 text-base font-bold text-white">
                                    @if ($month['isCurrent'])
                                        <span class="w-1.5 h-5 rounded-full bg-brand-500 shrink-0" aria-hidden="true"></span>
                                    @endif
                                    {{ $month['label'] }}
                                    @if ($month['isCurrent'])
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-brand-400/20 text-brand-200">This month</span>
                                    @endif
                                </h2>
                                <p class="mt-1 text-xs text-brand-100/60">
                                    @foreach ($plannerKeys as $source)
                                        <span class="tabular-nums font-semibold text-brand-100/80">{{ $month['byPlanner'][$source] }}</span>
                                        {{ ET::PLANNER_LABELS[$source] }}@if (! $loop->last) · @endif
                                    @endforeach
                                    · {{ TimesheetEntry::formatMinutes($month['minutes']) }} editing
                                </p>
                            </div>
                        </div>

                        <x-card class="overflow-hidden {{ $month['isCurrent'] ? 'border border-brand-200 ring-1 ring-white/10' : 'border border-white/10' }}">
                            @if ($month['editors']->isEmpty())
                                <p class="p-5 text-sm text-brand-100/60">No editors with output or editing hours this month.</p>
                            @else
                                <div class="overflow-x-auto">
                                    <table class="w-full text-sm">
                                        <thead>
                                            <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-brand-100/50 border-b border-white/10">
                                                <th class="px-4 sm:px-5 py-3">Editor</th>
                                                @foreach ($plannerKeys as $source)
                                                    <th class="px-3 py-3 text-right">{{ ET::PLANNER_LABELS[$source] }}</th>
                                                @endforeach
                                                <th class="px-4 sm:px-5 py-3 text-right">Editing time</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-white/10">
                                            @foreach ($month['editors'] as $row)
                                                @php
                                                    $hit = $row['user'] ? ($impact[$row['user']->id] ?? null) : null;
                                                    $tainted = $hit && $hit['share'] >= 0.15;
                                                    $published = array_sum($row['byPlanner']);
                                                @endphp
                                                <tr class="{{ $tainted ? 'bg-red-400/10' : '' }}">
                                                    <td class="px-4 sm:px-5 py-3">
                                                        <div class="flex items-center gap-3 min-w-0">
                                                            @if ($row['user'])
                                                                <x-avatar :name="$row['user']->name" :src="$row['user']->avatarUrl()" class="shrink-0 !w-8 !h-8 text-xs" />
                                                            @endif
                                                            <div class="min-w-0">
                                                                <p class="font-semibold text-white truncate">{{ $row['label'] }}</p>
                                                                @if (! $row['user'])
                                                                    <p class="text-[11px] text-brand-100/50">Notion only</p>
                                                                @elseif ($published === 0)
                                                                    <p class="text-[11px] text-brand-100/50">Hours only — nothing on planners</p>
                                                                @elseif ($tainted)
                                                                    <p class="text-[11px] font-semibold text-red-300">{{ round($hit['share'] * 100) }}% hours suspect</p>
                                                                @endif
                                                            </div>
                                                        </div>
                                                    </td>
                                                    @foreach ($plannerKeys as $source)
                                                        <td class="px-3 py-3 text-right tabular-nums text-white">{{ $row['byPlanner'][$source] }}</td>
                                                    @endforeach
                                                    <td class="px-4 sm:px-5 py-3 text-right tabular-nums font-semibold {{ $tainted ? 'text-red-300' : 'text-white' }}">
                                                        {{ $tainted ? '—' : TimesheetEntry::formatMinutes($row['minutes']) }}
                                                    </td>
                                                </tr>
                                                @if ($published > 0)
                                                    <tr class="{{ $tainted ? 'bg-red-400/10' : '' }}">
                                                        <td colspan="{{ 2 + count($plannerKeys) }}" class="px-4 sm:px-5 pb-3 pt-0">
                                                            <div class="flex h-1.5 max-w-xs rounded-full overflow-hidden bg-white/10">
                                                                @foreach ([ET::TIER_HIGH, ET::TIER_MEDIUM, ET::TIER_LOW, ET::TIER_NONE] as $tier)
                                                                    @if ($row['tiers'][$tier] > 0)
                                                                        <div class="{{ $tierBar[$tier] }}"
                                                                             style="width: {{ $row['tiers'][$tier] / $published * 100 }}%"
                                                                             title="{{ ET::TIER_LABELS[$tier] }}: {{ $row['tiers'][$tier] }}"></div>
                                                                    @endif
                                                                @endforeach
                                                            </div>
                                                        </td>
                                                    </tr>
                                                @endif
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </x-card>
                    </section>
                @endforeach
            </div>

            <p class="text-xs text-brand-100/60">
                Editing time is one total per month — the timesheet does not say Reel vs Post vs Story.
                Read hours beside the planner columns for that month, not as a single rate across the whole period.
            </p>
        @endif

        {{-- ——— The separate area: what the timesheet cannot vouch for ——— --}}
        <section id="suspect" class="scroll-mt-6">
            <h2 class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-100/50 mb-1">Suspect timesheet entries</h2>
            <p class="text-sm text-brand-100/70 mb-3.5">
                Kept apart from the figures above because it is upstream of them. Most of this is
                somebody logging how long a <em>job</em> ran rather than how long they worked — a
                misread form, not a false claim.
            </p>

            @if ($flags->isEmpty())
                <x-card class="p-8 text-center border border-white/10">
                    <p class="text-sm text-brand-100/70">Nothing in this period looks wrong.</p>
                </x-card>
            @else
                <div class="space-y-3">
                    @foreach ($flags->groupBy('person')->sortByDesc(fn ($g) => $g->where('severity', TA::SEVERITY_HIGH)->count()) as $person => $theirs)
                        @php
                            $worst = $theirs->contains('severity', TA::SEVERITY_HIGH)
                                ? TA::SEVERITY_HIGH
                                : ($theirs->contains('severity', TA::SEVERITY_MEDIUM) ? TA::SEVERITY_MEDIUM : TA::SEVERITY_LOW);
                            $tone = $severityTone[$worst];
                            $hit = $theirs->first()['user_id'] ? ($impact[$theirs->first()['user_id']] ?? null) : null;
                        @endphp

                        <div class="rounded-xl {{ $tone['bg'] }} ring-1 {{ $tone['ring'] }} overflow-hidden"
                             x-data="{ open: {{ $worst === TA::SEVERITY_HIGH ? 'true' : 'false' }} }">

                            <button type="button" @click="open = ! open"
                                    class="w-full flex flex-wrap items-center justify-between gap-3 p-4 text-left min-h-[56px]">
                                <div class="min-w-0">
                                    <p class="font-semibold text-white">{{ $person }}</p>
                                    <p class="mt-1 flex flex-wrap gap-1.5">
                                        @foreach ($theirs->groupBy('kind') as $kind => $rows)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-white/70 ring-1 ring-white/10 text-brand-100/80">
                                                {{ $rows->count() }} {{ str_replace('_', ' ', $kind) }}
                                            </span>
                                        @endforeach
                                    </p>
                                </div>

                                <div class="shrink-0 flex items-center gap-3">
                                    @if ($hit && $hit['share'] >= 0.15)
                                        <span class="text-xs font-semibold text-red-200">
                                            {{ round($hit['share'] * 100) }}% of editing hours affected
                                        </span>
                                    @endif
                                    <x-icon name="chevron-right" class="w-4 h-4 text-brand-100/50 transition-transform" ::class="open && 'rotate-90'" />
                                </div>
                            </button>

                            <div x-show="open" x-cloak class="px-4 pb-4 space-y-2">
                                @foreach ($theirs as $flag)
                                    <div class="rounded-lg bg-white/5 ring-1 ring-white/10 p-3">
                                        <div class="flex flex-wrap items-start justify-between gap-3">
                                            <div class="min-w-0">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide {{ $severityTone[$flag['severity']]['chip'] }}">
                                                        {{ $severityTone[$flag['severity']]['label'] }}
                                                    </span>
                                                    <p class="text-sm font-semibold text-white">{{ $flag['title'] }}</p>
                                                </div>
                                                <p class="mt-1 text-xs text-brand-100/70">{{ $flag['detail'] }}</p>
                                                @if (! empty($flag['task']))
                                                    <p class="mt-1 text-xs text-brand-100/60 truncate">Entry: &ldquo;{{ $flag['task'] }}&rdquo;</p>
                                                @endif
                                            </div>

                                            <div class="shrink-0 text-right">
                                                @if ($flag['date'])
                                                    <p class="text-xs text-brand-100/60 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($flag['date'])->format('D j M Y') }}</p>
                                                    @if ($flag['user_id'])
                                                        <a href="{{ route('timesheets.show', [$flag['user_id'], 'month' => \Illuminate\Support\Carbon::parse($flag['date'])->format('Y-m')]) }}"
                                                           class="mt-1 inline-block text-xs font-semibold text-brand-500 hover:text-brand-300">
                                                            Open &rarr;
                                                        </a>
                                                    @endif
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</x-app-layout>
