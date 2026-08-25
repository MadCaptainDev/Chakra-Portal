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

        <x-filter-bar>
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
        </x-filter-bar>

        @if ($shoots->isEmpty())
            <x-empty-state :message="$hasFilters ? 'No shoots match those filters.' : 'Nothing planned yet.'">
                @can('shoots.create')
                    <x-btn :href="route('shoots.create')" size="sm">Plan the first one</x-btn>
                @endcan
            </x-empty-state>
        @else
            {{-- Kanban: horizontal scroll on narrow viewports (~420px). Cards
                 open the shoot — update() needs the full form, so status is
                 not drag-and-dropped here. --}}
            <div class="-mx-4 sm:mx-0 overflow-x-auto pb-2">
                <div @class([
                         'flex gap-3 px-4 sm:px-0',
                         'lg:grid lg:grid-cols-4 lg:gap-4' => $columns->count() >= 4,
                     ])
                     @if ($columns->count() < 4)
                         style="min-width: max(100%, {{ $columns->count() * 280 }}px)"
                     @endif>
                    @foreach ($columns as $status => $column)
                        <section @class([
                                     'flex flex-col shrink-0 w-[min(100%,280px)] sm:w-72',
                                     'lg:w-auto lg:min-w-0 lg:shrink' => $columns->count() >= 4,
                                 ])
                                 aria-label="{{ $column['label'] }}">
                            <div class="flex items-center justify-between gap-2 mb-2 px-0.5">
                                <h2 class="text-sm font-semibold text-gray-900 truncate">{{ $column['label'] }}</h2>
                                <span class="shrink-0 inline-flex items-center justify-center min-w-[1.5rem] h-6 px-1.5 rounded-full
                                             text-[11px] font-semibold bg-gray-100 text-gray-600">
                                    {{ $column['shoots']->count() }}
                                </span>
                            </div>

                            <div class="flex-1 space-y-2 rounded-xl bg-gray-100/70 ring-1 ring-gray-900/5 p-2 min-h-[8rem]">
                                @forelse ($column['shoots'] as $shoot)
                                    <a href="{{ route('shoots.show', $shoot) }}"
                                       class="block rounded-lg bg-white ring-1 ring-gray-900/5 shadow-sm p-3
                                              min-h-[44px] hover:ring-brand-300/60 hover:shadow-md transition">
                                        <div class="flex items-start gap-3">
                                            <div class="shrink-0 w-10 text-center">
                                                <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-400">{{ $shoot->starts_at->format('M') }}</p>
                                                <p class="text-lg font-bold text-gray-900 leading-none">{{ $shoot->starts_at->format('d') }}</p>
                                                <p class="text-[10px] text-gray-400 mt-0.5">{{ $shoot->starts_at->format('D') }}</p>
                                            </div>

                                            <div class="min-w-0 flex-1">
                                                <div class="flex flex-wrap items-center gap-1.5">
                                                    <p class="font-semibold text-gray-900 text-sm leading-snug line-clamp-2">{{ $shoot->title }}</p>
                                                    @if ($shoot->hasKitProblems())
                                                        <x-badge status="overdue">Kit issue</x-badge>
                                                    @endif
                                                    @if ($shoot->isFromNotion())
                                                        <span title="Synced from Notion" class="inline-flex text-gray-400">
                                                            <x-icon name="globe" class="w-3.5 h-3.5" />
                                                        </span>
                                                    @endif
                                                </div>

                                                <p class="mt-1 text-xs text-gray-500 truncate">
                                                    {{ $shoot->starts_at->format('H:i') }}
                                                    @if ($shoot->clientLabel()) &middot; {{ $shoot->clientLabel() }} @endif
                                                    @if ($shoot->location) &middot; {{ $shoot->location }} @endif
                                                </p>

                                                <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                                    <x-badge :status="$shoot->status" />
                                                </div>

                                                <p class="mt-1.5 text-[11px] text-gray-400 truncate">
                                                    {{ $shoot->kitSummary() }}
                                                    @if ($shoot->crew->isNotEmpty())
                                                        &middot; {{ $shoot->crew->count() }} {{ Str::plural('crew', $shoot->crew->count()) }}
                                                    @endif
                                                </p>
                                            </div>
                                        </div>
                                    </a>
                                @empty
                                    <p class="px-2 py-6 text-center text-xs text-gray-400">None</p>
                                @endforelse
                            </div>
                        </section>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</x-app-layout>
