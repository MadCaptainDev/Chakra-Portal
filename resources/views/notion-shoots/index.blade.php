@php
    /*
     * Notion's shoot planner, and the one-way bridge into the portal's own
     * Shoots module.
     *
     * Import is explicit and one-way: the Notion token is read-only, so
     * nothing here can be pushed back, and a re-import refreshes only what
     * Notion owns (title, date, location, status) -- never the crew or kit
     * added on the portal side.
     */
    $filters = ['all' => 'All', 'unimported' => 'Not imported', 'unmapped' => 'No client'];
@endphp

<x-app-layout title="Notion Shoots">
    <x-slot name="header">
        <x-page-header title="Notion Shoots"
                       subtitle="Shoots planned in Notion. Import one to plan crew and kit for it here.">
            <x-slot name="actions">
                @can('shoots.create')
                    <form method="POST" action="{{ route('notion-shoots.import-all') }}">
                        @csrf
                        <x-primary-button type="submit">Import all</x-primary-button>
                    </form>
                @endcan
                <a href="{{ route('shoots.index') }}"
                   class="text-xs font-semibold uppercase tracking-widest text-brand-600 hover:text-brand-800">
                    Portal shoots →
                </a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-5">

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <x-stat-card label="In Notion" :value="number_format($total)" icon="camera" accent="brand" />
            <x-stat-card label="Not imported" :value="number_format($unimportedCount)" icon="plus"
                         :accent="$unimportedCount > 0 ? 'amber' : 'green'" />
            <x-stat-card label="No client mapped" :value="number_format($unmappedCount)" icon="users"
                         :accent="$unmappedCount > 0 ? 'amber' : 'green'" />
            <x-stat-card label="No date in Notion" :value="number_format($undatedCount)" icon="calendar"
                         :accent="$undatedCount > 0 ? 'gray' : 'green'" />
        </div>

        @if ($unmappedCount > 0)
            <div class="rounded-lg bg-amber-50 ring-1 ring-amber-100 px-4 py-3 flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-amber-800">
                    <span class="font-semibold">{{ $unmappedCount }} shoot(s)</span> have a Notion client name that
                    is not matched to a portal client. They can still be imported, but arrive with no client attached.
                </p>
                <a href="{{ route('content-accounts.edit') }}"
                   class="shrink-0 text-xs font-semibold uppercase tracking-widest text-amber-900 hover:text-amber-700">Map them →</a>
            </div>
        @endif

        <div class="flex flex-wrap items-center gap-2">
            @foreach ($filters as $key => $label)
                <a href="{{ route('notion-shoots.index', ['show' => $key]) }}"
                   @class([
                       'inline-flex items-center min-h-[36px] px-3 rounded-full text-xs font-semibold transition-colors',
                       'bg-brand-500 text-white' => $filter === $key,
                       'bg-white text-gray-600 ring-1 ring-gray-900/5 hover:ring-gray-900/10' => $filter !== $key,
                   ])>{{ $label }}</a>
            @endforeach
        </div>

        <x-card padding="none">
            @if ($shoots->isEmpty())
                <div class="p-4 sm:p-5">
                    <x-empty-state message="No shoots match this filter." />
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-[11px] font-semibold uppercase tracking-wider text-gray-500 border-b border-gray-200">
                                <th class="px-4 sm:px-5 py-2.5">Shoot</th>
                                <th class="px-3 py-2.5">Date</th>
                                <th class="px-3 py-2.5">Client</th>
                                <th class="px-3 py-2.5">Status</th>
                                <th class="px-3 py-2.5">Team</th>
                                <th class="px-3 py-2.5 text-right">In portal</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($shoots as $shoot)
                                <tr>
                                    <td class="px-4 sm:px-5 py-2.5">
                                        @if ($shoot->notion_url)
                                            <a href="{{ $shoot->notion_url }}" target="_blank" rel="noopener"
                                               class="text-gray-900 hover:text-brand-600 font-medium truncate block max-w-xs">
                                                {{ $shoot->title ?: '(untitled)' }}
                                            </a>
                                        @else
                                            <span class="text-gray-900 truncate block max-w-xs">{{ $shoot->title ?: '(untitled)' }}</span>
                                        @endif
                                        @if ($shoot->location)
                                            <span class="text-[11px] text-gray-400">{{ $shoot->location }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5 whitespace-nowrap text-gray-600">
                                        {{ $shoot->shoot_date?->format('j M Y') ?? '—' }}
                                    </td>
                                    <td class="px-3 py-2.5 whitespace-nowrap">
                                        @if ($shoot->mappedClient)
                                            <span class="text-gray-900">{{ $shoot->mappedClient->name }}</span>
                                        @else
                                            {{-- The raw Notion text, so it is obvious WHICH name needs mapping. --}}
                                            <span class="text-amber-600">{{ $shoot->getAttribute('client') ?: '—' }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5 whitespace-nowrap"><x-badge :status="$shoot->status" /></td>
                                    <td class="px-3 py-2.5 whitespace-nowrap text-gray-500 text-xs">
                                        {{ implode(', ', array_slice($shoot->teamMembers(), 0, 3)) ?: '—' }}
                                    </td>
                                    <td class="px-3 py-2.5 text-right whitespace-nowrap">
                                        @if ($shoot->shoot)
                                            <a href="{{ route('shoots.show', $shoot->shoot) }}"
                                               class="text-xs font-semibold text-brand-600 hover:text-brand-800">Open →</a>
                                        @elseif ($shoot->shoot_date === null)
                                            <span class="text-[11px] text-gray-400">needs a date</span>
                                        @else
                                            @can('shoots.create')
                                                <form method="POST" action="{{ route('notion-shoots.import', $shoot) }}">
                                                    @csrf
                                                    <button type="submit" class="text-xs font-semibold text-brand-600 hover:text-brand-800">
                                                        Import
                                                    </button>
                                                </form>
                                            @else
                                                <span class="text-[11px] text-gray-400">not imported</span>
                                            @endcan
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>
