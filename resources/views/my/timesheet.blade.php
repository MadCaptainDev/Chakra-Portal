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

    <div class="space-y-4" x-data="{ adding: false }">
        {{-- Month navigation --}}
        <div class="flex items-center justify-between gap-2">
            <a href="{{ route('my.timesheet', ['month' => $prev]) }}"
               class="inline-flex items-center min-h-[44px] px-3 rounded-md bg-white border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50">&larr; Prev</a>
            <div class="text-center">
                <p class="font-semibold text-gray-900">{{ $month->format('F Y') }}</p>
                @if (! $month->isSameMonth(now()))
                    <a href="{{ route('my.timesheet') }}" class="text-xs text-brand-500 hover:text-brand-600">Back to this month</a>
                @endif
            </div>
            <a href="{{ route('my.timesheet', ['month' => $next]) }}"
               class="inline-flex items-center min-h-[44px] px-3 rounded-md bg-white border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50">Next &rarr;</a>
        </div>

        <div class="grid grid-cols-2 gap-3">
            <x-card class="p-3 sm:p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Hours Logged</p>
                <p class="text-lg sm:text-2xl font-bold text-gray-900">{{ \App\Models\TimesheetEntry::formatMinutes($totalMinutes) }}</p>
            </x-card>
            <x-card class="p-3 sm:p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Entries</p>
                <p class="text-lg sm:text-2xl font-bold text-gray-900">{{ $entries->count() }}</p>
            </x-card>
        </div>

        <div class="flex justify-end">
            <button type="button" @click="adding = ! adding"
                    class="text-sm font-semibold text-brand-500 hover:text-brand-600 min-h-[44px]">
                <span x-show="! adding">+ Add Entry</span>
                <span x-show="adding" x-cloak>Cancel</span>
            </button>
        </div>

        <div x-show="adding" x-cloak>
            <x-card class="p-4 sm:p-6">
                @include('my._entry-form', ['ventures' => $ventures])
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
                        <h3 class="font-semibold text-gray-900">
                            {{ $date->format('D, d M') }}
                            @if ($date->isToday())
                                <span class="ml-1 text-xs font-semibold text-brand-500">Today</span>
                            @endif
                        </h3>
                        <span class="text-sm text-gray-500">
                            {{ \App\Models\TimesheetEntry::formatMinutes($dayEntries->where('status', '!=', 'cancelled')->sum('minutes')) }}
                        </span>
                    </div>

                    <x-card class="divide-y divide-gray-200">
                        @foreach ($dayEntries as $entry)
                            <div class="p-3 sm:p-4" x-data="{ editing: false }">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0 flex-1">
                                        <p class="font-medium text-gray-900 truncate">{{ $entry->task }}</p>
                                        <p class="text-xs text-gray-500 truncate">
                                            @if ($entry->venture){{ $entry->venture }} &middot; @endif
                                            @if ($entry->started_at)
                                                {{ substr($entry->started_at, 0, 5) }}@if ($entry->ended_at)&ndash;{{ substr($entry->ended_at, 0, 5) }}@endif
                                                &middot;
                                            @endif
                                            {{ $entry->durationLabel() }}
                                        </p>
                                    </div>
                                    <x-badge :status="$entry->status" class="shrink-0" />
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
                                    @include('my._entry-form', ['entry' => $entry, 'ventures' => $ventures])
                                </div>
                            </div>
                        @endforeach
                    </x-card>
                </div>
            @endforeach
        @endif
    </div>
</x-app-layout>
