@php
    $hasFilters = $filters['q'] !== '' || $filters['client'] !== '' || $filters['status'] !== '' || $filters['past'];
@endphp

<x-app-layout title="Shoots">
    <x-slot name="header">
        <x-page-header title="Shoots" eyebrow="Production"
                       subtitle="What is coming up, who is on it, and whether the kit is packed.">
            <x-slot name="actions">
                @can('shoots.create')
                    <form method="POST" action="{{ route('shoots.sync-notion') }}">
                        @csrf
                        <x-btn type="submit" variant="secondary" icon="refresh">Sync from Notion</x-btn>
                    </form>
                    <x-btn :href="route('shoots.create')" icon="plus">Plan a shoot</x-btn>
                @endcan
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-4">
        @can('shoots.create')
            @if ($notionPending > 0 || $notionUndated > 0 || $notionUnmapped > 0)
                <div class="rounded-xl bg-blue-50 border border-blue-200 p-4 flex items-start gap-3">
                    <x-icon name="globe" class="w-5 h-5 shrink-0 mt-0.5 text-blue-600" />
                    <div class="text-sm text-blue-900 space-y-0.5">
                        @if ($notionPending > 0)
                            <p>{{ $notionPending }} {{ Str::plural('shoot', $notionPending) }} in Notion {{ $notionPending === 1 ? "hasn't" : "haven't" }} synced here yet — press <span class="font-semibold">Sync from Notion</span> above.</p>
                        @endif
                        @if ($notionUnmapped > 0)
                            <p>{{ $notionUnmapped }} Notion {{ Str::plural('shoot', $notionUnmapped) }} {{ $notionUnmapped === 1 ? 'has' : 'have' }} a client Notion doesn't recognise, so nothing was mapped automatically.</p>
                        @endif
                        @if ($notionUndated > 0)
                            <p>{{ $notionUndated }} Notion {{ Str::plural('shoot', $notionUndated) }} {{ $notionUndated === 1 ? "hasn't" : "haven't" }} a date set, so {{ $notionUndated === 1 ? 'it' : 'they' }} can't be scheduled here yet — add one in Notion.</p>
                        @endif
                    </div>
                </div>
            @endif
        @endcan

        <div class="grid grid-cols-2 lg:grid-cols-3 gap-3 sm:gap-4">
            <x-stat-card label="Upcoming" :value="$upcomingCount" accent="brand" icon="camera" />
            <x-stat-card label="This week" :value="$thisWeek" accent="gray" icon="calendar" />
            <x-stat-card label="Kit still out" :value="$overdueKit"
                         :accent="$overdueKit > 0 ? 'red' : 'green'" icon="alert"
                         :href="$overdueKit > 0 ? route('shoots.index', ['past' => 1]) : null"
                         class="col-span-2 lg:col-span-1">
                {{ $overdueKit > 0 ? 'From shoots that have finished' : 'Everything is back' }}
            </x-stat-card>
        </div>

        <x-card class="p-4">
            <form method="GET" action="{{ route('shoots.index') }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <div class="lg:col-span-2">
                    <x-input-label for="q" value="Search" />
                    <x-text-input id="q" name="q" type="search" class="mt-1" :value="$filters['q']"
                                  placeholder="Title, location or client" />
                </div>

                <div>
                    <x-input-label for="client" value="Client" />
                    <x-select id="client" name="client" class="mt-1">
                        <option value="">All clients</option>
                        @foreach ($clients as $client)
                            <option value="{{ $client->id }}" @selected($filters['client'] == $client->id)>{{ $client->name }}</option>
                        @endforeach
                    </x-select>
                </div>

                <div>
                    <x-input-label for="status" value="Status" />
                    <x-select id="status" name="status" class="mt-1">
                        <option value="">Any status</option>
                        @foreach ($statuses as $value => $label)
                            <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                        @endforeach
                    </x-select>
                </div>

                <div class="sm:col-span-2 lg:col-span-4 flex flex-wrap items-center gap-4">
                    <label for="past" class="inline-flex items-center gap-2 min-h-[44px] text-sm text-gray-700 cursor-pointer">
                        <input id="past" name="past" type="checkbox" value="1" @checked($filters['past'])
                               class="rounded border-gray-300 text-brand-500 focus:ring-brand-400">
                        Show past shoots
                    </label>
                    <x-btn type="submit" size="sm">Apply</x-btn>
                    @if ($hasFilters)
                        <a href="{{ route('shoots.index') }}" class="text-sm font-semibold text-gray-500 hover:text-gray-800">Clear</a>
                    @endif
                </div>
            </form>
        </x-card>

        @if ($shoots->isEmpty())
            <x-empty-state :message="$hasFilters ? 'No shoots match those filters.' : 'Nothing planned yet.'">
                @can('shoots.create')
                    <x-btn :href="route('shoots.create')" size="sm">Plan the first one</x-btn>
                @endcan
            </x-empty-state>
        @else
            <x-card class="divide-y divide-gray-100 overflow-hidden">
                @foreach ($shoots as $shoot)
                    <a href="{{ route('shoots.show', $shoot) }}"
                       class="group flex items-start gap-4 p-4 min-h-[44px] hover:bg-brand-50/40 transition">

                        {{-- The date reads first: this is a diary. --}}
                        <div class="shrink-0 w-12 text-center">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-400">{{ $shoot->starts_at->format('M') }}</p>
                            <p class="text-xl font-bold text-gray-900 leading-none">{{ $shoot->starts_at->format('d') }}</p>
                            <p class="text-[10px] text-gray-400 mt-0.5">{{ $shoot->starts_at->format('D') }}</p>
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="font-semibold text-gray-900 truncate group-hover:text-brand-700 transition">{{ $shoot->title }}</p>
                                <x-badge :status="$shoot->status" />
                                @if ($shoot->hasKitProblems())
                                    <x-badge status="overdue">Kit issue</x-badge>
                                @endif
                                @if ($shoot->isFromNotion())
                                    <span title="Synced from Notion" class="inline-flex items-center gap-1 text-[11px] font-medium text-gray-400">
                                        <x-icon name="globe" class="w-3.5 h-3.5" />
                                    </span>
                                @endif
                            </div>

                            <p class="mt-1 text-xs text-gray-500 truncate">
                                {{ $shoot->starts_at->format('H:i') }}
                                @if ($shoot->clientLabel()) &middot; {{ $shoot->clientLabel() }} @endif
                                @if ($shoot->location) &middot; {{ $shoot->location }} @endif
                            </p>

                            <p class="mt-1 text-[11px] text-gray-400">
                                {{ $shoot->kitSummary() }}
                                @if ($shoot->crew->isNotEmpty())
                                    &middot; {{ $shoot->crew->count() }} {{ Str::plural('crew', $shoot->crew->count()) }}
                                @endif
                            </p>
                        </div>

                        <x-icon name="chevron-right" class="w-4 h-4 shrink-0 mt-1 text-gray-300 group-hover:text-brand-500 transition" />
                    </a>
                @endforeach
            </x-card>

            {{ $shoots->links() }}
        @endif
    </div>
</x-app-layout>
