@php
    $byDay = $entries->groupBy(fn ($e) => $e->worked_on->toDateString());
@endphp

<x-app-layout title="My timesheet">
    <x-slot name="header">
        <x-page-header title="My Timesheet" eyebrow="Your work"
                       subtitle="Log what you did, day by day.">
            <x-slot name="actions">
                <x-btn :href="route('my.calendar', ['month' => $month->format('Y-m')])"
                       variant="secondary" icon="calendar">Calendar</x-btn>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-4" x-data="{ adding: {{ $errors->any() ? 'true' : 'false' }} }">
        <x-month-nav route="my.timesheet" :month="$month" />

        <div class="grid grid-cols-2 gap-3 sm:gap-4">
            <x-stat-card label="Hours this month" accent="brand" icon="clock"
                         value="{{ \App\Models\TimesheetEntry::formatMinutes($totalMinutes) }}" />
            <x-stat-card label="Days with work" accent="gray" icon="calendar"
                         value="{{ $stats['daysWorked'] }}" />
        </div>

        @if ($entries->isNotEmpty())
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                <x-charts.daily-bars :days="$stats['daily']" :max-minutes="$stats['maxDaily']" title="Hours per day" />
                <x-charts.horizontal-bars
                    :items="$stats['ventures']"
                    :max-minutes="$stats['maxVenture']"
                    title="By client"
                    empty="No client hours yet — pick a client on each entry."
                />
            </div>
            @if (collect($stats['taskTypes'])->sum('minutes') > 0)
                <x-charts.horizontal-bars
                    :items="collect($stats['taskTypes'])->map(fn ($row) => ['label' => $row['label'], 'minutes' => $row['minutes']])->all()"
                    :max-minutes="$stats['maxTaskType']"
                    title="By type"
                    :limit="4"
                    :linkable="false"
                />
            @endif
        @endif

        <div class="flex justify-end">
            <x-btn type="button" @click="adding = ! adding">
                <span x-show="! adding" class="inline-flex items-center gap-1.5">
                    <x-icon name="plus" class="w-4 h-4" /> Add entry
                </span>
                <span x-show="adding" x-cloak>Cancel</span>
            </x-btn>
        </div>

        <div x-show="adding" x-cloak>
            <x-card padding="md" tone="brand">
                <h3 class="font-semibold text-brand-900 mb-4">New entry</h3>
                @include('my._entry-form', ['ventureOptions' => $ventureOptions])
            </x-card>
        </div>

        @if ($entries->isEmpty())
            <x-empty-state message="Nothing logged for {{ $month->format('F Y') }} yet.">
                <x-btn type="button" size="sm" @click="adding = true">Add your first entry</x-btn>
            </x-empty-state>
        @else
            @foreach ($byDay as $day => $dayEntries)
                @php $date = \Illuminate\Support\Carbon::parse($day); @endphp

                <div>
                    <div class="flex items-center justify-between mb-2 px-1">
                        <h3 class="font-semibold text-gray-900 flex items-center gap-2">
                            {{ $date->format('D, d M') }}
                            @if ($date->isToday())
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-brand-100 text-[10px] font-bold uppercase tracking-wide text-brand-700">Today</span>
                            @endif
                        </h3>
                        <span class="text-sm font-semibold text-gray-600 tabular-nums">
                            {{ \App\Models\TimesheetEntry::formatMinutes($dayEntries->where('status', '!=', 'cancelled')->sum('minutes')) }}
                        </span>
                    </div>

                    <x-card class="divide-y divide-gray-100 overflow-hidden">
                        @foreach ($dayEntries as $entry)
                            <div class="p-3 sm:p-4" x-data="{ editing: false }">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0 flex-1">
                                        <p class="font-medium text-gray-900 truncate">{{ $entry->task }}</p>
                                        <p class="text-xs text-gray-500 truncate mt-0.5">
                                            @if ($entry->venture){{ $entry->venture }} &middot; @endif
                                            @if ($entry->started_at)
                                                {{ substr($entry->started_at, 0, 5) }}@if ($entry->ended_at)&ndash;{{ substr($entry->ended_at, 0, 5) }}@endif
                                                &middot;
                                            @endif
                                            {{ $entry->durationLabel() }}
                                        </p>
                                        <div class="mt-2 flex flex-wrap gap-1.5">
                                            <x-badge :status="$entry->task_type ?: 'other'" />
                                            <x-badge :status="$entry->status" />
                                        </div>
                                    </div>
                                </div>

                                @if ($entry->notes)
                                    <p class="mt-2 text-xs text-gray-600">{{ $entry->notes }}</p>
                                @endif

                                <div class="mt-2 flex items-center justify-end gap-3">
                                    <button type="button" @click="editing = ! editing"
                                            class="min-h-[44px] px-2 text-xs font-semibold text-brand-500 hover:text-brand-600">
                                        <span x-show="! editing">Edit</span>
                                        <span x-show="editing" x-cloak>Cancel</span>
                                    </button>
                                    <form method="POST" action="{{ route('my.timesheet.destroy', $entry) }}"
                                          onsubmit="return confirm('Delete this entry?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="min-h-[44px] px-2 text-xs font-semibold text-red-600 hover:text-red-800">Delete</button>
                                    </form>
                                </div>

                                <div x-show="editing" x-cloak class="mt-2 pt-3 border-t border-gray-200">
                                    @include('my._entry-form', ['entry' => $entry, 'ventureOptions' => $ventureOptions])
                                </div>
                            </div>
                        @endforeach
                    </x-card>
                </div>
            @endforeach
        @endif
    </div>
</x-app-layout>
