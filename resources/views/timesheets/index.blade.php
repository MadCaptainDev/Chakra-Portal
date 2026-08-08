@php
    $prev = $month->copy()->subMonthNoOverflow()->format('Y-m');
    $next = $month->copy()->addMonthNoOverflow()->format('Y-m');
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Timesheets" />
    </x-slot>

    <div class="space-y-4">
        <div class="flex items-center justify-between gap-2">
            <a href="{{ route('timesheets.index', ['month' => $prev]) }}"
               class="inline-flex items-center min-h-[44px] px-3 rounded-md bg-white border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50">&larr; Prev</a>
            <div class="text-center">
                <p class="font-semibold text-brand-900">{{ $month->format('F Y') }}</p>
                <p class="text-xs text-brand-600">{{ \App\Models\TimesheetEntry::formatMinutes($totalMinutes) }} across the team</p>
            </div>
            <a href="{{ route('timesheets.index', ['month' => $next]) }}"
               class="inline-flex items-center min-h-[44px] px-3 rounded-md bg-white border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50">Next &rarr;</a>
        </div>

        @if ($rows->isEmpty())
            <x-empty-state message="No employee logins yet.">
                <a href="{{ route('users.create') }}" class="text-brand-500 font-semibold text-sm hover:text-brand-600">Create one &rarr;</a>
            </x-empty-state>
        @else
            @if ($totalMinutes > 0)
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                    <x-charts.horizontal-bars
                        :items="$ranking"
                        :max-minutes="max(1, collect($ranking)->max('minutes') ?: 0)"
                        title="Who worked most"
                        :limit="12"
                        empty="No hours logged this month."
                        :linkable="false"
                    />
                    <x-charts.horizontal-bars
                        :items="$teamStats['ventures']"
                        :max-minutes="$teamStats['maxVenture']"
                        title="By client"
                        :limit="10"
                        empty="No client hours yet."
                    />
                </div>

                <x-charts.daily-bars
                    :days="$teamStats['daily']"
                    :max-minutes="$teamStats['maxDaily']"
                    title="Team hours by day"
                />

                @if (collect($teamStats['taskTypes'])->sum('minutes') > 0)
                    <x-charts.horizontal-bars
                        :items="collect($teamStats['taskTypes'])->map(fn ($row) => ['label' => $row['label'], 'minutes' => $row['minutes']])->all()"
                        :max-minutes="$teamStats['maxTaskType']"
                        title="By type"
                        :limit="4"
                        :linkable="false"
                    />
                @endif
            @endif

            <x-card class="divide-y divide-gray-100 border border-brand-100/40">
                @foreach ($rows->sortByDesc('minutes') as $row)
                    <a href="{{ route('timesheets.show', [$row['employee'], 'month' => $month->format('Y-m')]) }}"
                       class="p-3 sm:p-4 flex items-center gap-3 hover:bg-brand-50/50">
                        <x-avatar :name="$row['employee']->name" :src="$row['employee']->avatarUrl()" />

                        <div class="min-w-0 flex-1">
                            <p class="font-medium text-gray-900 truncate">{{ $row['employee']->name }}</p>
                            <p class="text-xs text-gray-500">
                                {{ $row['entries'] }} {{ Str::plural('entry', $row['entries']) }}
                                &middot; {{ $row['days'] }} {{ Str::plural('day', $row['days']) }}
                                @if ($row['pending'] > 0)
                                    <span class="text-amber-600 font-semibold">&middot; {{ $row['pending'] }} pending</span>
                                @endif
                            </p>
                        </div>

                        <div class="text-right shrink-0">
                            <p class="text-sm font-bold text-brand-900">{{ \App\Models\TimesheetEntry::formatMinutes($row['minutes']) }}</p>
                            <p class="text-[11px] {{ $row['point'] ? 'text-green-600 font-semibold' : 'text-gray-400' }}">
                                {{ $row['point'] ? $row['point']->points.' pts' : 'no points' }}
                            </p>
                        </div>
                    </a>
                @endforeach
            </x-card>
        @endif
    </div>
</x-app-layout>
