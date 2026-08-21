@php
    /*
     * One account's month, piece by piece.
     *
     * The dashboard answers "did we hit the number". This answers "which
     * pieces, and did they work" -- so each row carries the real Instagram
     * post it was matched to, where one could be.
     *
     * A dash in the performance columns means no matched Instagram post,
     * which is ordinary: only three clients have Instagram connected, and
     * nothing published before that connection exists locally to match.
     */
    $labels = $targeted + \App\Support\ContentDashboard::UNTARGETED;
    $byType = $items->groupBy('source');
@endphp

<x-app-layout title="{{ $account->name }}">
    <x-slot name="header">
        <x-page-header :title="$account->name"
                       eyebrow="{{ $account->client?->name }}"
                       subtitle="{{ $month->format('F Y') }} — published pieces and how they performed.">
            <x-slot name="actions">
                <a href="{{ route('content-dashboard.index', ['month' => $month->format('Y-m')]) }}"
                   class="text-xs font-semibold uppercase tracking-widest text-brand-600 hover:text-brand-800">
                    ← Content Dashboard
                </a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-5">

        <div class="flex flex-wrap items-center justify-between gap-3">
            <form method="GET" class="flex items-center gap-2">
                <label for="month" class="text-xs font-semibold uppercase tracking-wider text-gray-500">Month</label>
                <select id="month" name="month" onchange="this.form.submit()"
                        class="rounded-md border-gray-300 text-sm py-1.5 pr-8">
                    @foreach ($months as $m)
                        <option value="{{ $m->format('Y-m') }}" @selected($m->format('Y-m') === $month->format('Y-m'))>
                            {{ $m->format('F Y') }}
                        </option>
                    @endforeach
                </select>
            </form>
            <p class="text-xs text-gray-500">
                Ventures: {{ implode(', ', $account->ventureNames()) ?: 'none assigned' }}
            </p>
        </div>

        {{-- Per-type standing for this account. --}}
        <div class="grid grid-cols-2 lg:grid-cols-3 gap-3">
            @foreach ($targeted as $source => $label)
                @php
                    $actual = $byType->get($source, collect())->count();
                    $target = $account->targetFor($source);
                @endphp
                <x-stat-card :label="$label"
                             :value="$target !== null ? $actual.' / '.$target : (string) $actual"
                             icon="check-circle"
                             :accent="$target === null ? 'gray' : ($actual >= $target ? 'green' : 'red')" />
            @endforeach
        </div>

        <x-card padding="none">
            <div class="p-4 sm:p-5 pb-0">
                <x-section-heading title="Published this month"
                                   subtitle="{{ $items->count() }} piece(s). Reach, views and likes come from the matched Instagram post." />
            </div>

            @if ($items->isEmpty())
                <div class="p-4 sm:p-5">
                    <x-empty-state message="Nothing published for this account in {{ $month->format('F Y') }}." />
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-[11px] font-semibold uppercase tracking-wider text-gray-500 border-b border-gray-200">
                                <th class="px-4 sm:px-5 py-2.5">Piece</th>
                                <th class="px-3 py-2.5">Type</th>
                                <th class="px-3 py-2.5">Published</th>
                                <th class="px-3 py-2.5">Editor</th>
                                <th class="px-3 py-2.5 text-right">Reach</th>
                                <th class="px-3 py-2.5 text-right">Views</th>
                                <th class="px-3 py-2.5 text-right">Likes</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($items as $item)
                                @php $media = $item->socialMediaItem; @endphp
                                <tr>
                                    <td class="px-4 sm:px-5 py-2.5">
                                        @if ($item->notion_url)
                                            <a href="{{ $item->notion_url }}" target="_blank" rel="noopener"
                                               class="text-gray-900 hover:text-brand-600 font-medium truncate block max-w-sm">
                                                {{ $item->title ?: '(untitled)' }}
                                            </a>
                                        @else
                                            <span class="text-gray-900 truncate block max-w-sm">{{ $item->title ?: '(untitled)' }}</span>
                                        @endif
                                        @if ($media?->permalink)
                                            <a href="{{ $media->permalink }}" target="_blank" rel="noopener"
                                               class="text-[11px] text-brand-600 hover:text-brand-800">View on Instagram →</a>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5 whitespace-nowrap">
                                        <x-badge>{{ $labels[$item->source] ?? ucfirst($item->source) }}</x-badge>
                                    </td>
                                    <td class="px-3 py-2.5 whitespace-nowrap text-gray-600">{{ $item->published_date?->format('j M') }}</td>
                                    <td class="px-3 py-2.5 whitespace-nowrap text-gray-500">{{ $item->editor ?: '—' }}</td>
                                    @foreach (['reach', 'views', 'likes'] as $metric)
                                        <td class="px-3 py-2.5 text-right tabular-nums">
                                            @if ($media && $media->metricValue($metric) !== null)
                                                <span class="text-gray-900">{{ number_format($media->metricValue($metric)) }}</span>
                                            @else
                                                <span class="text-gray-300">—</span>
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
