@php
    $prev = $month->copy()->subMonthNoOverflow()->format('Y-m');
    $next = $month->copy()->addMonthNoOverflow()->format('Y-m');
    $byDay = $entries->groupBy(fn ($e) => $e->worked_on->toDateString());
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-page-header title="My Timesheet">
            <x-slot name="actions">
                <a href="{{ route('my.calendar', ['month' => $month->format('Y-m')]) }}"
                   class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50">
                    Calendar
                </a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-4" x-data="{ adding: {{ $errors->any() ? 'true' : 'false' }} }">
        {{-- Month navigation --}}
        <div class="flex items-center justify-between gap-2">
            <a href="{{ route('my.timesheet', ['month' => $prev]) }}"
               class="inline-flex items-center min-h-[44px] px-3 rounded-md bg-white border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50">&larr; Prev</a>
            <div class="text-center">
                <p class="font-semibold text-brand-900">{{ $month->format('F Y') }}</p>
                @if (! $month->isSameMonth(now()))
                    <a href="{{ route('my.timesheet') }}" class="text-xs text-brand-500 hover:text-brand-600">Back to this month</a>
                @endif
            </div>
            <a href="{{ route('my.timesheet', ['month' => $next]) }}"
               class="inline-flex items-center min-h-[44px] px-3 rounded-md bg-white border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50">Next &rarr;</a>
        </div>

        <x-card class="p-4 sm:p-5 border border-brand-100/60">
            <p class="text-xs text-brand-600 uppercase tracking-wide font-semibold">Hours this month</p>
            <p class="text-2xl sm:text-3xl font-bold text-brand-900 mt-1">{{ \App\Models\TimesheetEntry::formatMinutes($totalMinutes) }}</p>
            <p class="text-xs text-gray-500 mt-1">{{ $stats['daysWorked'] }} {{ Str::plural('day', $stats['daysWorked']) }} with logged work</p>
        </x-card>

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
            <button type="button" @click="adding = ! adding"
                    class="inline-flex items-center min-h-[44px] px-4 rounded-md bg-brand-500 text-white text-sm font-semibold hover:bg-brand-600 shadow-sm">
                <span x-show="! adding">+ Add Entry</span>
                <span x-show="adding" x-cloak>Cancel</span>
            </button>
        </div>

        <div x-show="adding" x-cloak>
            <x-card class="p-4 sm:p-6 border border-brand-200/80">
                <h3 class="font-semibold text-brand-900 mb-4">New entry</h3>
                @include('my._entry-form', ['ventureOptions' => $ventureOptions])
            </x-card>
        </div>

        @if ($entries->isEmpty())
            <x-empty-state message="Nothing logged for {{ $month->format('F Y') }} yet.">
                <button type="button" @click="adding = true" class="text-brand-500 font-semibold text-sm hover:text-brand-600">Add your first entry &rarr;</button>
            </x-empty-state>
        @else
            @foreach ($byDay as $day => $dayEntries)
                @php $date = \Illuminate\Support\Carbon::parse($day); @endphp

                <div>
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="font-semibold text-brand-900">
                            {{ $date->format('D, d M') }}
                            @if ($date->isToday())
                                <span class="ml-1 text-xs font-semibold text-brand-500">Today</span>
                            @endif
                        </h3>
                        <span class="text-sm text-gray-500">
                            {{ \App\Models\TimesheetEntry::formatMinutes($dayEntries->where('status', '!=', 'cancelled')->sum('minutes')) }}
                        </span>
                    </div>

                    <x-card class="divide-y divide-gray-100 border border-brand-100/40">
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
