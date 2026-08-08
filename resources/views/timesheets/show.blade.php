@php
    $prev = $month->copy()->subMonthNoOverflow()->format('Y-m');
    $next = $month->copy()->addMonthNoOverflow()->format('Y-m');
    $byDay = $entries->groupBy(fn ($e) => $e->worked_on->toDateString());
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="$employee->name">
            <x-slot name="actions">
                <a href="{{ route('timesheets.index', ['month' => $month->format('Y-m')]) }}"
                   class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50">
                    All Timesheets
                </a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="max-w-4xl space-y-4">
        <div class="flex items-center justify-between gap-2">
            <a href="{{ route('timesheets.show', [$employee, 'month' => $prev]) }}"
               class="inline-flex items-center min-h-[44px] px-3 rounded-md bg-white border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50">&larr; Prev</a>
            <p class="font-semibold text-gray-900">{{ $month->format('F Y') }}</p>
            <a href="{{ route('timesheets.show', [$employee, 'month' => $next]) }}"
               class="inline-flex items-center min-h-[44px] px-3 rounded-md bg-white border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50">Next &rarr;</a>
        </div>

        <div class="grid grid-cols-2 gap-3">
            <x-card class="p-3 sm:p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Hours</p>
                <p class="text-lg sm:text-2xl font-bold text-gray-900">{{ \App\Models\TimesheetEntry::formatMinutes($totalMinutes) }}</p>
            </x-card>
            <x-card class="p-3 sm:p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Entries</p>
                <p class="text-lg sm:text-2xl font-bold text-gray-900">{{ $entries->count() }}</p>
            </x-card>
        </div>

        {{-- Points award --}}
        <x-card class="p-4 sm:p-6">
            <h3 class="font-semibold text-gray-900 mb-1">Points for {{ $month->format('F Y') }}</h3>
            <p class="text-xs text-gray-500 mb-4">Only {{ Str::before($employee->name, ' ') }} sees this, on their dashboard.</p>

            <form method="POST" action="{{ route('timesheets.award', $employee) }}">
                @csrf
                <input type="hidden" name="month" value="{{ $month->format('Y-m-d') }}">

                <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                    <div>
                        <x-input-label for="points" value="Points" />
                        <x-text-input id="points" name="points" type="number" min="0" max="1000" class="mt-1 block w-full"
                                      value="{{ old('points', $point->points ?? '') }}" required />
                        <x-input-error :messages="$errors->get('points')" class="mt-2" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label for="note" value="Remark (optional)" />
                        <x-text-input id="note" name="note" type="text" class="mt-1 block w-full"
                                      value="{{ old('note', $point->note ?? '') }}" placeholder="e.g. Great turnaround this month" />
                        <x-input-error :messages="$errors->get('note')" class="mt-2" />
                    </div>
                    <div class="flex items-end">
                        <x-primary-button>{{ $point ? 'Update' : 'Award' }}</x-primary-button>
                    </div>
                </div>
            </form>
        </x-card>

        {{-- Entries --}}
        @if ($entries->isEmpty())
            <x-empty-state message="Nothing logged for {{ $month->format('F Y') }}." />
        @else
            @foreach ($byDay as $day => $dayEntries)
                @php $date = \Illuminate\Support\Carbon::parse($day); @endphp

                <div>
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="font-semibold text-gray-900">{{ $date->format('D, d M') }}</h3>
                        <span class="text-sm text-gray-500">
                            {{ \App\Models\TimesheetEntry::formatMinutes($dayEntries->where('status', '!=', 'cancelled')->sum('minutes')) }}
                        </span>
                    </div>

                    <x-card class="divide-y divide-gray-200">
                        @foreach ($dayEntries as $entry)
                            <div class="p-3 flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-gray-900 truncate">{{ $entry->task }}</p>
                                    <p class="text-xs text-gray-500 truncate">
                                        @if ($entry->venture){{ $entry->venture }} &middot; @endif
                                        @if ($entry->started_at)
                                            {{ substr($entry->started_at, 0, 5) }}@if ($entry->ended_at)&ndash;{{ substr($entry->ended_at, 0, 5) }}@endif
                                            &middot;
                                        @endif
                                        {{ $entry->durationLabel() }}
                                    </p>
                                    @if ($entry->notes)
                                        <p class="text-xs text-gray-600 mt-1">{{ $entry->notes }}</p>
                                    @endif
                                </div>
                                <x-badge :status="$entry->status" class="shrink-0" />
                            </div>
                        @endforeach
                    </x-card>
                </div>
            @endforeach
        @endif
    </div>
</x-app-layout>
