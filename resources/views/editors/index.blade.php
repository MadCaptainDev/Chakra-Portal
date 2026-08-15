@php
    use App\Models\TimesheetEntry;
    use App\Support\EditorThroughput as ET;
    use App\Support\TimesheetAnomalies as TA;

    /*
     * Two questions on one screen, kept apart on purpose: what the editors
     * produced, and what in the timesheet cannot be believed. The second is
     * upstream of the first -- every rate here is output divided by hours, and
     * a wrong hour is a wrong rate -- so the warning sits above the figures
     * rather than under them.
     */
    $tierTone = [
        ET::TIER_HIGH => 'bg-red-100 text-red-700',
        ET::TIER_MEDIUM => 'bg-amber-100 text-amber-800',
        ET::TIER_LOW => 'bg-emerald-100 text-emerald-700',
        ET::TIER_NONE => 'bg-gray-100 text-gray-500',
    ];

    $tierBar = [
        ET::TIER_HIGH => 'bg-red-400',
        ET::TIER_MEDIUM => 'bg-amber-400',
        ET::TIER_LOW => 'bg-emerald-400',
        ET::TIER_NONE => 'bg-gray-300',
    ];

    $severityTone = [
        TA::SEVERITY_HIGH => ['ring' => 'ring-red-300', 'bg' => 'bg-red-50', 'chip' => 'bg-red-100 text-red-700', 'label' => 'Cannot be true'],
        TA::SEVERITY_MEDIUM => ['ring' => 'ring-amber-300', 'bg' => 'bg-amber-50', 'chip' => 'bg-amber-100 text-amber-800', 'label' => 'Worth a look'],
        TA::SEVERITY_LOW => ['ring' => 'ring-gray-200', 'bg' => 'bg-white', 'chip' => 'bg-gray-100 text-gray-600', 'label' => 'Incomplete'],
    ];

    $highCount = ($flagsBySeverity[TA::SEVERITY_HIGH] ?? collect())->count();
    $peakItems = max(1, (int) $throughput['months']->max('items'));
@endphp

<x-app-layout title="Editor output">
    <x-slot name="header">
        <x-page-header title="Editor Output" eyebrow="Studio"
                       subtitle="What was published, what it cost in hours, and what the timesheet cannot vouch for.">
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
        <x-card class="p-4 sm:p-5 border border-brand-100/60">
            <div class="flex flex-wrap gap-x-8 gap-y-2 text-xs text-gray-600">
                <span><span class="font-semibold text-gray-900">{{ $from->format('M Y') }} – {{ $to->format('M Y') }}</span></span>
                <span>{{ number_format($throughput['totalItems']) }} items published</span>
                <span>Planners: {{ implode(', ', $throughput['sources']) ?: 'none synced' }}</span>
                <span>
                    Notion last synced
                    <span class="font-semibold text-gray-900">{{ $throughput['lastSynced'] ? \Illuminate\Support\Carbon::parse($throughput['lastSynced'])->diffForHumans() : 'never' }}</span>
                </span>
            </div>

            @if ($throughput['unattributedItems'] > 0 || $throughput['shared'] > 0)
                <p class="mt-2.5 text-xs text-gray-500">
                    @if ($throughput['unattributedItems'] > 0)
                        {{ $throughput['unattributedItems'] }} published items name no editor and are counted in the studio total only.
                    @endif
                    @if ($throughput['shared'] > 0)
                        {{ $throughput['shared'] }} were co-edited and are left out of everyone's rate rather than credited twice.
                    @endif
                </p>
            @endif
        </x-card>

        {{-- The warning goes above the figures because it is upstream of them. --}}
        @if ($highCount > 0)
            <a href="#suspect"
               class="flex items-start gap-3.5 rounded-xl bg-red-50 ring-1 ring-red-300 p-4 sm:p-5 hover:bg-red-100/70 transition-colors">
                <span class="shrink-0 inline-flex items-center justify-center w-9 h-9 rounded-lg bg-red-100 text-red-600">
                    <x-icon name="alert" class="w-5 h-5" />
                </span>
                <div class="min-w-0">
                    <p class="font-semibold text-red-900">
                        {{ $highCount }} timesheet {{ Str::plural('entry', $highCount) }} in this period cannot be true
                    </p>
                    <p class="mt-1 text-sm text-red-800/80">
                        Any rate below that divides by those hours is wrong. Details at the bottom &rarr;
                    </p>
                </div>
            </a>
        @endif

        {{-- ——— Per editor ——— --}}
        <section>
            <h2 class="text-[11px] font-semibold uppercase tracking-[0.16em] text-gray-400 mb-3.5">Per editor</h2>

            @if ($throughput['rows']->isEmpty())
                <x-empty-state message="Nothing published and no editing logged in this period." />
            @else
                <div class="space-y-3">
                    @foreach ($throughput['rows'] as $row)
                        @php
                            $hit = $row['user'] ? ($impact[$row['user']->id] ?? null) : null;
                            $tainted = $hit && $hit['share'] >= 0.15;
                        @endphp

                        <x-card class="p-4 sm:p-5 {{ $tainted ? 'border border-red-200' : 'border border-brand-100/40' }}">
                            <div class="flex flex-wrap items-start justify-between gap-4">
                                <div class="flex items-center gap-3 min-w-0">
                                    @if ($row['user'])
                                        <x-avatar :name="$row['user']->name" :src="$row['user']->avatarUrl()" class="shrink-0" />
                                    @endif
                                    <div class="min-w-0">
                                        <p class="font-semibold text-gray-900 truncate">{{ $row['label'] }}</p>
                                        <p class="text-xs text-gray-500">
                                            @if (! $row['user'])
                                                In Notion only — no portal login matches this name
                                            @elseif ($row['items'] === 0)
                                                Logged editing time, published nothing tracked
                                            @else
                                                {{ $row['days'] }} {{ Str::plural('day', $row['days']) }} editing
                                                @if ($row['hoursPerDay']) &middot; {{ $row['hoursPerDay'] }}h a day @endif
                                            @endif
                                        </p>
                                    </div>
                                </div>

                                {{-- The headline number, and the honest blank
                                     when it cannot be computed. --}}
                                <div class="shrink-0 text-right">
                                    @if ($tainted)
                                        <p class="text-2xl font-extrabold leading-none text-red-600 tabular-nums">—</p>
                                        <p class="mt-1 text-[10px] font-semibold uppercase tracking-wider text-red-600">
                                            {{ round($hit['share'] * 100) }}% of hours suspect
                                        </p>
                                    @elseif ($row['minutesPerItem'])
                                        <p class="text-2xl font-extrabold leading-none text-gray-900 tabular-nums">
                                            {{ TimesheetEntry::formatMinutes($row['minutesPerItem']) }}
                                        </p>
                                        <p class="mt-1 text-[10px] font-semibold uppercase tracking-wider text-gray-400">per item</p>
                                    @else
                                        <p class="text-2xl font-extrabold leading-none text-gray-300 tabular-nums">—</p>
                                        <p class="mt-1 text-[10px] font-semibold uppercase tracking-wider text-gray-400">no rate</p>
                                    @endif
                                </div>
                            </div>

                            <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-4">
                                <div>
                                    <p class="text-lg font-bold text-gray-900 tabular-nums">{{ $row['items'] }}</p>
                                    <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-400">Published</p>
                                </div>
                                <div>
                                    <p class="text-lg font-bold text-gray-900 tabular-nums">{{ TimesheetEntry::formatMinutes($row['minutes']) }}</p>
                                    <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-400">Editing time</p>
                                </div>
                                <div>
                                    <p class="text-lg font-bold text-gray-900 tabular-nums">{{ $row['itemsPerDay'] ?? '—' }}</p>
                                    <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-400">Items a day</p>
                                </div>
                                <div>
                                    <p class="text-lg font-bold text-gray-900 tabular-nums">{{ $row['hardShare'] !== null ? $row['hardShare'].'%' : '—' }}</p>
                                    <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-400">Medium or high</p>
                                </div>
                            </div>

                            {{-- Tier mix. Two editors' rates only compare if
                                 their mix does, so it sits on the same card. --}}
                            @if ($row['items'] > 0)
                                <div class="mt-4">
                                    <div class="flex h-2 rounded-full overflow-hidden bg-gray-100">
                                        @foreach ([ET::TIER_HIGH, ET::TIER_MEDIUM, ET::TIER_LOW, ET::TIER_NONE] as $tier)
                                            @if ($row['tiers'][$tier] > 0)
                                                <div class="{{ $tierBar[$tier] }}"
                                                     style="width: {{ $row['tiers'][$tier] / $row['items'] * 100 }}%"
                                                     title="{{ ET::TIER_LABELS[$tier] }}: {{ $row['tiers'][$tier] }}"></div>
                                            @endif
                                        @endforeach
                                    </div>
                                    <div class="mt-2 flex flex-wrap gap-1.5">
                                        @foreach ([ET::TIER_HIGH, ET::TIER_MEDIUM, ET::TIER_LOW, ET::TIER_NONE] as $tier)
                                            @if ($row['tiers'][$tier] > 0)
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold {{ $tierTone[$tier] }}">
                                                    {{ ET::TIER_LABELS[$tier] }} {{ $row['tiers'][$tier] }}
                                                </span>
                                            @endif
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </x-card>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- ——— Month by month ——— --}}
        <section>
            <h2 class="text-[11px] font-semibold uppercase tracking-[0.16em] text-gray-400 mb-3.5">Month by month</h2>

            <x-card class="p-4 sm:p-6 border border-brand-100/40">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-[10px] font-semibold uppercase tracking-wider text-gray-400">
                                <th class="pb-3 pr-4">Month</th>
                                <th class="pb-3 pr-4 text-right">Published</th>
                                <th class="pb-3 pr-4">Tier mix</th>
                                <th class="pb-3 pr-4 text-right">Editing time</th>
                                <th class="pb-3 text-right">Per item</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($throughput['months'] as $month)
                                @php
                                    $perItem = $month['items'] > 0 && $month['minutes'] > 0
                                        ? (int) round($month['minutes'] / $month['items'])
                                        : null;
                                @endphp
                                <tr>
                                    <td class="py-3 pr-4 font-semibold text-gray-900 whitespace-nowrap">{{ $month['label'] }}</td>
                                    <td class="py-3 pr-4 text-right tabular-nums">{{ $month['items'] }}</td>
                                    <td class="py-3 pr-4 w-1/3 min-w-[140px]">
                                        @if ($month['items'] > 0)
                                            <div class="flex h-1.5 rounded-full overflow-hidden bg-gray-100"
                                                 style="width: {{ max(12, round($month['items'] / $peakItems * 100)) }}%">
                                                @foreach ([ET::TIER_HIGH, ET::TIER_MEDIUM, ET::TIER_LOW, ET::TIER_NONE] as $tier)
                                                    @if ($month['tiers'][$tier] > 0)
                                                        <div class="{{ $tierBar[$tier] }}"
                                                             style="width: {{ $month['tiers'][$tier] / $month['items'] * 100 }}%"
                                                             title="{{ ET::TIER_LABELS[$tier] }}: {{ $month['tiers'][$tier] }}"></div>
                                                    @endif
                                                @endforeach
                                            </div>
                                        @else
                                            <span class="text-xs text-gray-400">—</span>
                                        @endif
                                    </td>
                                    <td class="py-3 pr-4 text-right tabular-nums text-gray-600">{{ TimesheetEntry::formatMinutes($month['minutes']) }}</td>
                                    <td class="py-3 text-right tabular-nums font-semibold text-gray-900">
                                        {{ $perItem ? TimesheetEntry::formatMinutes($perItem) : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <p class="mt-4 text-xs text-gray-500">
                    "Per item" is every editor's hours over every tracked item, so it moves with the tier mix
                    as well as with pace. Read it beside the mix bar, not on its own.
                </p>
            </x-card>
        </section>

        {{-- ——— The separate area: what the timesheet cannot vouch for ——— --}}
        <section id="suspect" class="scroll-mt-6">
            <h2 class="text-[11px] font-semibold uppercase tracking-[0.16em] text-gray-400 mb-1">Suspect timesheet entries</h2>
            <p class="text-sm text-gray-600 mb-3.5">
                Kept apart from the figures above because it is upstream of them. Most of this is
                somebody logging how long a <em>job</em> ran rather than how long they worked — a
                misread form, not a false claim.
            </p>

            @if ($flags->isEmpty())
                <x-card class="p-8 text-center border border-brand-100/40">
                    <p class="text-sm text-gray-600">Nothing in this period looks wrong.</p>
                </x-card>
            @else
                {{-- Grouped by person, because that is who has to be talked to,
                     and because a flat list of every flag is a wall nobody
                     reads. Worst offender first. --}}
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
                                    <p class="font-semibold text-gray-900">{{ $person }}</p>
                                    <p class="mt-1 flex flex-wrap gap-1.5">
                                        @foreach ($theirs->groupBy('kind') as $kind => $rows)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold bg-white/70 ring-1 ring-gray-900/5 text-gray-700">
                                                {{ $rows->count() }} {{ str_replace('_', ' ', $kind) }}
                                            </span>
                                        @endforeach
                                    </p>
                                </div>

                                <div class="shrink-0 flex items-center gap-3">
                                    @if ($hit && $hit['share'] >= 0.15)
                                        <span class="text-xs font-semibold text-red-700">
                                            {{ round($hit['share'] * 100) }}% of editing hours affected
                                        </span>
                                    @endif
                                    <x-icon name="chevron-right" class="w-4 h-4 text-gray-400 transition-transform" ::class="open && 'rotate-90'" />
                                </div>
                            </button>

                            <div x-show="open" x-cloak class="px-4 pb-4 space-y-2">
                                @foreach ($theirs as $flag)
                                    <div class="rounded-lg bg-white ring-1 ring-gray-900/5 p-3">
                                        <div class="flex flex-wrap items-start justify-between gap-3">
                                            <div class="min-w-0">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide {{ $severityTone[$flag['severity']]['chip'] }}">
                                                        {{ $severityTone[$flag['severity']]['label'] }}
                                                    </span>
                                                    <p class="text-sm font-semibold text-gray-900">{{ $flag['title'] }}</p>
                                                </div>
                                                <p class="mt-1 text-xs text-gray-600">{{ $flag['detail'] }}</p>
                                                @if (! empty($flag['task']))
                                                    <p class="mt-1 text-xs text-gray-500 truncate">Entry: &ldquo;{{ $flag['task'] }}&rdquo;</p>
                                                @endif
                                            </div>

                                            <div class="shrink-0 text-right">
                                                @if ($flag['date'])
                                                    <p class="text-xs text-gray-500 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($flag['date'])->format('D j M Y') }}</p>
                                                    @if ($flag['user_id'])
                                                        <a href="{{ route('timesheets.show', [$flag['user_id'], 'month' => \Illuminate\Support\Carbon::parse($flag['date'])->format('Y-m')]) }}"
                                                           class="mt-1 inline-block text-xs font-semibold text-brand-500 hover:text-brand-600">
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
