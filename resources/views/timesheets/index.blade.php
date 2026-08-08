<x-app-layout title="Timesheets">
    <x-slot name="header">
        <x-page-header title="Timesheets" eyebrow="Team"
                       subtitle="Hours logged by everyone with a login, month by month." />
    </x-slot>

    <div class="space-y-4">
        <x-month-nav route="timesheets.index" :month="$month"
                     :subtitle="\App\Models\TimesheetEntry::formatMinutes($totalMinutes).' across the team'" />

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

            <x-card class="divide-y divide-gray-100 overflow-hidden">
                @foreach ($rows->sortByDesc('minutes') as $index => $row)
                    <a href="{{ route('timesheets.show', [$row['employee'], 'month' => $month->format('Y-m')]) }}"
                       class="group p-3 sm:p-4 flex items-center gap-3 min-h-[44px] hover:bg-brand-50/40 transition">
                        {{-- Rank badge: the list is sorted by hours, so make the
                             order itself readable instead of implied. --}}
                        <span class="shrink-0 w-6 text-center text-xs font-bold tabular-nums
                                     {{ $index === 0 ? 'text-brand-600' : 'text-gray-300' }}">
                            {{ $index + 1 }}
                        </span>

                        <x-avatar :name="$row['employee']->name" :src="$row['employee']->avatarUrl()" />

                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-gray-900 truncate group-hover:text-brand-700 transition">
                                {{ $row['employee']->name }}
                            </p>
                            <p class="text-xs text-gray-500">
                                {{ $row['entries'] }} {{ Str::plural('entry', $row['entries']) }}
                                &middot; {{ $row['days'] }} {{ Str::plural('day', $row['days']) }}
                                @if ($row['pending'] > 0)
                                    <span class="text-amber-600 font-semibold">&middot; {{ $row['pending'] }} pending</span>
                                @endif
                            </p>
                        </div>

                        <div class="text-right shrink-0">
                            <p class="text-sm font-bold text-gray-900 tabular-nums">{{ \App\Models\TimesheetEntry::formatMinutes($row['minutes']) }}</p>
                            <p class="text-[11px] {{ $row['point'] ? 'text-green-600 font-semibold' : 'text-gray-400' }}">
                                {{ $row['point'] ? $row['point']->points.' pts' : 'no points' }}
                            </p>
                        </div>

                        <x-icon name="chevron-right" class="w-4 h-4 shrink-0 text-gray-300 group-hover:text-brand-500 transition" />
                    </a>
                @endforeach
            </x-card>
        @endif
    </div>
</x-app-layout>
