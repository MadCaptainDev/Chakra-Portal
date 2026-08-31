@php
    /*
     * One account's month, piece by piece — pipeline tracking view.
     *
     * Shows ALL items with published_date in the month, not just Published.
     * Each row carries status, and filter chips let you drill into specific
     * pipeline stages.
     *
     * A dash in the performance columns means no matched Instagram post,
     * which is ordinary: only three clients have Instagram connected, and
     * nothing published before that connection exists locally to match.
     */
    $labels = $targeted + \App\Support\ContentDashboard::UNTARGETED;
    $byType = $items->groupBy('source');
    $byStatus = $items->groupBy('status');
@endphp

<x-app-layout title="{{ $account->name }}">
    <x-slot name="header">
        <x-page-header :title="$account->name"
                       eyebrow="{{ $account->client?->name }}"
                       subtitle="{{ $month->format('F Y') }} — pipeline tracking and performance.">
            <x-slot name="actions">
                <a href="{{ route('content-dashboard.index', ['month' => $month->format('Y-m')]) }}"
                   class="text-xs font-semibold uppercase tracking-widest text-brand-300 hover:text-brand-200">
                    ← Content Dashboard
                </a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-5" x-data="{
        filters: {
            published: true,
            in_progress: true,
            scheduled: true,
            canceled: false
        },
        statusGroups: @js($statusGroups),
        // One entry per content type actually present this month
        // (@js($byType->keys())), all on by default -- a type with
        // nothing planned gets no chip at all rather than a dead toggle.
        types: Object.fromEntries(@js($byType->keys()->all()).map((t) => [t, true])),
        shouldShow(status, source) {
            if (this.types[source] === false) return false;
            if (!status) return true;
            for (const [group, statuses] of Object.entries(this.statusGroups)) {
                if (statuses.includes(status) && this.filters[group]) return true;
            }
            return false;
        }
    }">

        <div class="flex flex-wrap items-center justify-between gap-3">
            <form method="GET" class="flex items-center gap-2">
                <label for="month" class="text-xs font-semibold uppercase tracking-wider text-brand-100/60">Month</label>
                <select id="month" name="month" onchange="this.form.submit()"
                        class="rounded-md border-white/15 text-sm py-1.5 pr-8">
                    @foreach ($months as $m)
                        <option value="{{ $m->format('Y-m') }}" @selected($m->format('Y-m') === $month->format('Y-m'))>
                            {{ $m->format('F Y') }}
                        </option>
                    @endforeach
                </select>
            </form>
            <p class="text-xs text-brand-100/60">
                Ventures: {{ implode(', ', $account->ventureNames()) ?: 'none assigned' }}
            </p>
        </div>

        {{-- Pipeline overview for this account --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <x-stat-card label="Published"
                         :value="(string) $pipeline['published']"
                         icon="check-circle"
                         accent="green" />
            <x-stat-card label="In Progress"
                         :value="(string) $pipeline['in_progress']"
                         icon="refresh"
                         accent="amber" />
            <x-stat-card label="Scheduled"
                         :value="(string) $pipeline['scheduled']"
                         icon="clock"
                         accent="blue" />
            <x-stat-card label="Total Planned"
                         :value="(string) $pipeline['total']"
                         icon="collection"
                         accent="brand" />
        </div>

        {{-- Per-type breakdown --}}
        <div class="grid grid-cols-2 lg:grid-cols-{{ count($targeted) }} gap-3">
            @foreach ($targeted as $source => $label)
                @php
                    $published = $byType->get($source, collect())->where('status', 'Published')->count();
                    $planned = $byType->get($source, collect())->count();
                    $target = $account->targetFor($source);
                @endphp
                <div class="bg-white/5 rounded-lg ring-1 ring-white/10 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wider text-brand-100/60">{{ $label }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums">
                        <span @class([
                            'text-green-300' => $target !== null && $published >= $target,
                            'text-amber-300' => $target !== null && $published < $target && $planned >= $target,
                            'text-red-300' => $target !== null && $planned < $target,
                            'text-white' => $target === null,
                        ])>{{ $published }}</span>
                        @if ($planned > $published)
                            <span class="text-lg text-amber-300 font-normal">/ {{ $planned }}</span>
                        @endif
                        @if ($target !== null)
                            <span class="text-lg text-brand-100/50 font-normal">of {{ $target }}</span>
                        @endif
                    </p>
                    @if ($planned > $published)
                        <p class="text-xs text-amber-300 mt-1">{{ $planned - $published }} pending</p>
                    @endif
                </div>
            @endforeach
        </div>

        <x-card padding="none">
            <div class="p-4 sm:p-5 pb-3">
                <x-section-heading title="Content this month"
                                   subtitle="{{ $items->count() }} piece(s). Filter by status or type below." />
            </div>

            {{-- Status filter chips --}}
            <div class="px-4 sm:px-5 pb-3 flex flex-wrap gap-2">
                <x-filter-chip
                    x-on:click="filters.published = !filters.published"
                    x-bind:class="filters.published ? 'bg-green-500 text-white' : 'bg-white/10 text-brand-100/70 hover:bg-white/[0.16]'"
                    :count="$pipeline['published']">
                    Published
                </x-filter-chip>
                <x-filter-chip
                    x-on:click="filters.in_progress = !filters.in_progress"
                    x-bind:class="filters.in_progress ? 'bg-amber-500 text-white' : 'bg-white/10 text-brand-100/70 hover:bg-white/[0.16]'"
                    :count="$pipeline['in_progress']">
                    In Progress
                </x-filter-chip>
                <x-filter-chip
                    x-on:click="filters.scheduled = !filters.scheduled"
                    x-bind:class="filters.scheduled ? 'bg-blue-500 text-white' : 'bg-white/10 text-brand-100/70 hover:bg-white/[0.16]'"
                    :count="$pipeline['scheduled']">
                    Scheduled
                </x-filter-chip>
                @if ($pipeline['canceled'] > 0)
                    <x-filter-chip
                        x-on:click="filters.canceled = !filters.canceled"
                        x-bind:class="filters.canceled ? 'bg-red-500 text-white' : 'bg-white/10 text-brand-100/70 hover:bg-white/[0.16]'"
                        :count="$pipeline['canceled']">
                        Canceled
                    </x-filter-chip>
                @endif
            </div>

            {{-- Type filter chips -- one per content type actually present
                 this month; a type with nothing planned gets no chip. --}}
            @if ($byType->keys()->count() > 1)
                <div class="px-4 sm:px-5 pb-4 flex flex-wrap gap-2 border-t border-white/5 pt-3">
                    @foreach ($byType as $source => $sourceItems)
                        <x-filter-chip
                            x-on:click="types['{{ $source }}'] = !types['{{ $source }}']"
                            x-bind:class="types['{{ $source }}'] ? 'bg-white/20 text-white' : 'bg-white/10 text-brand-100/50 hover:bg-white/[0.16]'"
                            :count="$sourceItems->count()">
                            @if (in_array($source, [\App\Models\ContentItem::SOURCE_REEL, \App\Models\ContentItem::SOURCE_POST, \App\Models\ContentItem::SOURCE_STORY]))
                                <x-brand-icon name="instagram" class="w-3.5 h-3.5 shrink-0" />
                            @elseif ($source === \App\Models\ContentItem::SOURCE_YOUTUBE)
                                <x-brand-icon name="youtube" class="w-3.5 h-3.5 shrink-0" />
                            @endif
                            {{ $labels[$source] ?? ucfirst($source) }}
                        </x-filter-chip>
                    @endforeach
                </div>
            @endif

            @if ($items->isEmpty())
                <div class="p-4 sm:p-5">
                    <x-empty-state message="No content planned for this account in {{ $month->format('F Y') }}." />
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-[11px] font-semibold uppercase tracking-wider text-brand-100/60 border-b border-white/10">
                                <th class="px-4 sm:px-5 py-2.5">Piece</th>
                                <th class="px-3 py-2.5">Status</th>
                                <th class="px-3 py-2.5">Type</th>
                                <th class="px-3 py-2.5">Date</th>
                                <th class="px-3 py-2.5">Editor</th>
                                <th class="px-3 py-2.5 text-right">Reach</th>
                                <th class="px-3 py-2.5 text-right">Views</th>
                                <th class="px-3 py-2.5 text-right">Likes</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/10">
                            @foreach ($items as $item)
                                @php $media = $item->socialMediaItem; @endphp
                                <tr x-show="shouldShow(@js($item->status), @js($item->source))" x-cloak>
                                    <td class="px-4 sm:px-5 py-2.5">
                                        @if ($item->notion_url)
                                            <a href="{{ $item->notion_url }}" target="_blank" rel="noopener"
                                               class="text-white hover:text-brand-300 font-medium truncate block max-w-sm">
                                                {{ $item->title ?: '(untitled)' }}
                                            </a>
                                        @else
                                            <span class="text-white truncate block max-w-sm">{{ $item->title ?: '(untitled)' }}</span>
                                        @endif
                                        @if ($media?->permalink)
                                            <a href="{{ $media->permalink }}" target="_blank" rel="noopener"
                                               class="text-[11px] text-brand-300 hover:text-brand-200">View on Instagram →</a>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5 whitespace-nowrap">
                                        <x-badge :status="$item->status" />
                                    </td>
                                    <td class="px-3 py-2.5 whitespace-nowrap">
                                        <span class="text-brand-100/70">{{ $labels[$item->source] ?? ucfirst($item->source) }}</span>
                                    </td>
                                    <td class="px-3 py-2.5 whitespace-nowrap text-brand-100/70">
                                        {{ $item->published_date?->format('j M') }}
                                        @if ($item->published_date?->isFuture())
                                            <span class="text-[10px] text-amber-300 block">upcoming</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5 whitespace-nowrap text-brand-100/60">{{ $item->editor ?: '—' }}</td>
                                    @foreach (['reach', 'views', 'likes'] as $metric)
                                        <td class="px-3 py-2.5 text-right tabular-nums">
                                            @if ($media && $media->metricValue($metric) !== null)
                                                <span class="text-white">{{ number_format($media->metricValue($metric)) }}</span>
                                            @else
                                                <span class="text-brand-100/40">—</span>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>
