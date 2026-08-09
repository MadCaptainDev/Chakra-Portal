<x-app-layout title="Calendar">
    <x-slot name="header">
        <x-page-header title="My Calendar" eyebrow="Your work"
                       subtitle="Every logged day this month at a glance.">
            <x-slot name="actions">
                <x-btn :href="route('my.timesheet', ['month' => $month->format('Y-m')])"
                       variant="secondary" icon="clock">Timesheet</x-btn>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-4" x-data="{ open: null }">
        <x-month-nav route="my.calendar" :month="$month"
                     :subtitle="\App\Models\TimesheetEntry::formatMinutes($totalMinutes).' logged'" />

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

                                <div class="min-h-[92px] rounded-xl p-1.5 text-left transition
                                    {{ $day['inMonth'] ? 'bg-white ring-1 ring-gray-900/5 shadow-sm' : 'bg-gray-50/60 ring-1 ring-gray-900/[0.03]' }}
                                    {{ $day['isToday'] ? 'ring-2 ring-brand-400 shadow-md' : '' }}">

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
                <x-card padding="sm" class="mb-2">
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
