@php
    $prev = $month->copy()->subMonthNoOverflow()->format('Y-m');
    $next = $month->copy()->addMonthNoOverflow()->format('Y-m');
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-page-header title="My Calendar">
            <x-slot name="actions">
                <a href="{{ route('my.timesheet', ['month' => $month->format('Y-m')]) }}"
                   class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50">
                    Timesheet
                </a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-4" x-data="{ open: null }">
        {{-- Month navigation --}}
        <div class="flex items-center justify-between gap-2">
            <a href="{{ route('my.calendar', ['month' => $prev]) }}"
               class="inline-flex items-center min-h-[44px] px-3 rounded-md bg-white border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50">&larr; Prev</a>
            <div class="text-center">
                <p class="font-semibold text-gray-900">{{ $month->format('F Y') }}</p>
                <p class="text-xs text-gray-500">{{ \App\Models\TimesheetEntry::formatMinutes($totalMinutes) }} logged</p>
            </div>
            <a href="{{ route('my.calendar', ['month' => $next]) }}"
               class="inline-flex items-center min-h-[44px] px-3 rounded-md bg-white border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50">Next &rarr;</a>
        </div>

        {{-- Month grid. Scrolls sideways on a narrow phone rather than
             squeezing seven columns into 420px and becoming unreadable. --}}
        <div class="-mx-4 sm:-mx-6 lg:-mx-8 px-4 sm:px-6 lg:px-8 overflow-x-auto pb-2">
            <div class="min-w-[640px]">
                <div class="grid grid-cols-7 gap-1 mb-1">
                    @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $label)
                        <div class="text-center text-[11px] font-semibold text-gray-500 uppercase tracking-wide py-1">{{ $label }}</div>
                    @endforeach
                </div>

                <div class="space-y-1">
                    @foreach ($weeks as $week)
                        <div class="grid grid-cols-7 gap-1">
                            @foreach ($week as $day)
                                @php $key = $day['date']->toDateString(); @endphp

                                <div class="min-h-[92px] rounded-lg border p-1.5 text-left
                                    {{ $day['inMonth'] ? 'bg-white border-gray-200' : 'bg-gray-50 border-gray-100' }}
                                    {{ $day['isToday'] ? 'ring-2 ring-brand-400' : '' }}">

                                    <div class="flex items-center justify-between mb-1">
                                        <span class="text-xs font-semibold {{ $day['inMonth'] ? 'text-gray-700' : 'text-gray-300' }}">
                                            {{ $day['date']->format('j') }}
                                        </span>
                                        @if ($day['minutes'] > 0)
                                            <span class="text-[10px] font-semibold text-brand-600">
                                                {{ intdiv($day['minutes'], 60) }}h{{ $day['minutes'] % 60 ? ($day['minutes'] % 60) : '' }}
                                            </span>
                                        @endif
                                    </div>

                                    @foreach ($day['entries']->take(3) as $entry)
                                        <div class="mb-0.5 px-1 py-0.5 rounded text-[10px] leading-tight truncate
                                            {{ $entry->status === 'cancelled' ? 'bg-red-50 text-red-700 line-through' : ($entry->status === 'pending' ? 'bg-amber-50 text-amber-800' : 'bg-brand-50 text-brand-800') }}"
                                             title="{{ $entry->task }}{{ $entry->venture ? ' — '.$entry->venture : '' }} ({{ $entry->durationLabel() }})">
                                            {{ $entry->task }}
                                        </div>
                                    @endforeach

                                    @if ($day['entries']->count() > 3)
                                        <p class="text-[10px] text-gray-500 px-1">+{{ $day['entries']->count() - 3 }} more</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- A phone-friendly list of the same month, since a 7-column grid is
             awkward to read on a small screen even when it scrolls. --}}
        <div class="sm:hidden">
            <h3 class="font-semibold text-gray-900 mb-2">Days with entries</h3>

            @forelse ($entriesByDay as $day => $dayEntries)
                @php $date = \Illuminate\Support\Carbon::parse($day); @endphp
                <x-card class="p-3 mb-2">
                    <div class="flex items-center justify-between mb-1">
                        <p class="font-medium text-gray-900">{{ $date->format('D, d M') }}</p>
                        <span class="text-xs text-gray-500">
                            {{ \App\Models\TimesheetEntry::formatMinutes($dayEntries->where('status', '!=', 'cancelled')->sum('minutes')) }}
                        </span>
                    </div>
                    @foreach ($dayEntries as $entry)
                        <p class="text-xs text-gray-600 truncate">
                            {{ $entry->task }}@if ($entry->venture) &middot; {{ $entry->venture }}@endif
                        </p>
                    @endforeach
                </x-card>
            @empty
                <x-empty-state message="Nothing logged this month." />
            @endforelse
        </div>
    </div>
</x-app-layout>
