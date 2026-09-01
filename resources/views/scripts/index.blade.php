@php
    $hasFilters = $filters['q'] !== '' || $filters['client'] !== ''
        || $filters['status'] !== '' || $filters['writer'] !== '' || $filters['mine'];
@endphp

<x-app-layout title="Scripts">
    <x-slot name="header">
        <x-page-header title="Scripts" eyebrow="Production"
                       subtitle="Everything being written, and who is writing it.">
            <x-slot name="actions">
                @can('scripts.create')
                    <x-btn :href="route('scripts.import-keep.create')" variant="secondary" icon="document">Import from Google Keep</x-btn>
                    <x-btn :href="route('scripts.create')" icon="plus">New script</x-btn>
                @endcan
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-4">

        {{-- Two counts that answer the only questions worth asking on arrival:
             how much is open, and how much of it is mine. Both describe the
             whole board, never the filtered view. --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
            <x-stat-card label="Open" :value="$openTotal" accent="brand" icon="document" />
            <x-stat-card label="Assigned to me" :value="$mineTotal" accent="green" icon="user" />
            <x-stat-card label="In review" :value="$counts[\App\Models\Script::STATUS_INTERNAL_REVIEW] ?? 0" accent="amber" icon="clock" />
            <x-stat-card label="Ready" :value="$counts[\App\Models\Script::STATUS_READY] ?? 0" accent="gray" icon="check-circle" />
        </div>

        {{-- Filters. A plain GET form, like every other list in the app, so a
             filtered view is a URL somebody can send to somebody else. --}}
        <x-card class="p-4">
            <form method="GET" action="{{ route('scripts.index') }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                <div class="lg:col-span-2">
                    <x-input-label for="q" value="Search" />
                    <x-text-input id="q" name="q" type="search" class="mt-1" :value="$filters['q']"
                                  placeholder="Title, campaign or client" />
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
                            <option value="{{ $value }}" @selected($filters['status'] === $value)>
                                {{ $label }}@if (($counts[$value] ?? 0) > 0) ({{ $counts[$value] }})@endif
                            </option>
                        @endforeach
                    </x-select>
                </div>

                <div>
                    <x-input-label for="writer" value="Writer" />
                    <x-select id="writer" name="writer" class="mt-1">
                        <option value="">Anyone</option>
                        @foreach ($writers as $writer)
                            <option value="{{ $writer->id }}" @selected($filters['writer'] == $writer->id)>{{ $writer->name }}</option>
                        @endforeach
                    </x-select>
                </div>

                <div class="sm:col-span-2 lg:col-span-5 flex flex-wrap items-center gap-4">
                    <label for="mine" class="inline-flex items-center gap-2 min-h-[44px] text-sm text-brand-100/80 cursor-pointer">
                        <input id="mine" name="mine" type="checkbox" value="1" @checked($filters['mine'])
                               class="rounded bg-white/10 border-white/25 text-brand-400 focus:ring-brand-400">
                        Only mine
                    </label>

                    <x-btn type="submit" size="sm">Apply</x-btn>

                    @if ($hasFilters)
                        <a href="{{ route('scripts.index') }}" class="text-sm font-semibold text-brand-100/60 hover:text-white">Clear</a>
                    @endif
                </div>
            </form>
        </x-card>

        @if ($scripts->isEmpty())
            <x-empty-state :message="$hasFilters ? 'No scripts match those filters.' : 'No scripts yet.'">
                @can('scripts.create')
                    <x-btn :href="route('scripts.create')" size="sm">Write the first one</x-btn>
                @endcan
            </x-empty-state>
        @else
            <x-card class="divide-y divide-white/10 overflow-hidden">
                @foreach ($scripts as $script)
                    <a href="{{ route(auth()->user()->can('scripts.edit') ? 'scripts.edit' : 'scripts.show', $script) }}"
                       class="group flex items-start gap-4 p-4 min-h-[44px] hover:bg-white/10 transition">

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="font-semibold text-white truncate group-hover:text-brand-200 transition">{{ $script->title }}</p>
                                <x-badge :status="$script->status" />
                                @if ($script->priority === \App\Models\Script::PRIORITY_HIGH)
                                    <x-badge status="overdue">High priority</x-badge>
                                @endif
                            </div>

                            <p class="mt-1 text-xs text-brand-100/60 truncate">
                                {{ $script->clientLabel() ?: 'No client' }}
                                @if ($script->writer) &middot; {{ $script->writer->name }} @endif
                                @if ($script->platformTerm) &middot; {{ $script->platformTerm->name }} @endif
                                @if ($script->durationLabel()) &middot; {{ $script->durationLabel() }} @endif
                            </p>

                            <p class="mt-1 text-[11px] text-brand-100/50">
                                @if ($script->lastEditedBy)
                                    Last edited by {{ Str::before($script->lastEditedBy->name, ' ') }}
                                    {{ $script->last_edited_at?->diffForHumans() }}
                                @else
                                    Created {{ $script->created_at->diffForHumans() }}
                                @endif
                            </p>
                        </div>

                        <div class="shrink-0 text-right">
                            @if ($script->due_on)
                                <p @class([
                                    'text-sm font-semibold tabular-nums',
                                    'text-red-300' => $script->isOverdue(),
                                    'text-white' => ! $script->isOverdue(),
                                ])>{{ $script->due_on->format('d M') }}</p>
                                <p class="text-[11px] {{ $script->isOverdue() ? 'text-red-300' : 'text-brand-100/50' }}">
                                    {{ $script->isOverdue() ? 'overdue' : 'due' }}
                                </p>
                            @else
                                <p class="text-[11px] text-brand-100/50">No deadline</p>
                            @endif
                        </div>

                        <x-icon name="chevron-right" class="w-4 h-4 shrink-0 mt-1 text-brand-100/40 group-hover:text-brand-500 transition" />
                    </a>
                @endforeach
            </x-card>

            {{ $scripts->links() }}
        @endif
    </div>
</x-app-layout>
