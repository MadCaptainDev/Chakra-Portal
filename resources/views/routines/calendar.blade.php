@php
    use App\Models\RoutineOccurrence;
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Routine calendar" subtitle="Every duty this month. Skip with a reason from here.">
            <x-slot name="actions">
                <x-btn :href="route('routines.checking')" variant="secondary">Check today</x-btn>
                <x-btn :href="route('routines.index')" variant="secondary">Definitions</x-btn>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-4" x-data="{ open: null }">
        @if ($overdueCount > 0)
            <p class="text-sm text-amber-800 bg-amber-50 ring-1 ring-amber-200 rounded-lg px-3 py-2">
                {{ $overdueCount }} overdue open {{ Str::plural('duty', $overdueCount) }} still need a tick or a skip.
            </p>
        @endif

        <x-month-nav route="routines.calendar" :month="$month"
                     :subtitle="$occurrencesByDay->flatten()->count().' occurrence(s)'" />

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
                                        @if ($day['overdueCount'] > 0)
                                            <span class="text-[10px] font-semibold text-red-600">{{ $day['overdueCount'] }} late</span>
                                        @elseif ($day['openCount'] > 0)
                                            <span class="text-[10px] font-semibold text-brand-600">{{ $day['openCount'] }} open</span>
                                        @endif
                                    </div>

                                    @foreach ($day['occurrences']->take(3) as $occurrence)
                                        <button type="button" @click="open = open === {{ $occurrence->id }} ? null : {{ $occurrence->id }}"
                                                class="mb-0.5 w-full text-left px-1 py-0.5 rounded text-[10px] leading-tight truncate
                                                {{ $occurrence->status === RoutineOccurrence::STATUS_DONE ? 'bg-green-50 text-green-800'
                                                    : ($occurrence->status === RoutineOccurrence::STATUS_SKIPPED ? 'bg-gray-100 text-gray-500 line-through'
                                                    : ($occurrence->isOverdue() ? 'bg-red-50 text-red-700' : 'bg-brand-50 text-brand-800')) }}"
                                                title="{{ $occurrence->routine?->title }}">
                                            {{ $occurrence->routine?->title }}
                                            @if ($occurrence->checkpoint) · {{ $occurrence->checkpoint->name }} @endif
                                        </button>
                                        <div x-show="open === {{ $occurrence->id }}" x-cloak class="mb-1 p-1 rounded bg-white ring-1 ring-gray-200 text-[10px]">
                                            @if ($label = $occurrence->subjectLabel())
                                                <p class="truncate">{{ $label }}</p>
                                            @endif
                                            <p class="text-gray-500">{{ RoutineOccurrence::STATUSES[$occurrence->status] ?? $occurrence->status }}</p>
                                            @if ($occurrence->isOpen())
                                                <form method="POST" action="{{ route('routines.occurrences.skip', $occurrence) }}" class="mt-1 space-y-1">
                                                    @csrf
                                                    <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
                                                    <input type="text" name="note" required placeholder="Skip reason"
                                                           class="w-full text-[10px] rounded border-gray-300">
                                                    <button type="submit" class="text-red-600 font-semibold">Skip</button>
                                                </form>
                                            @endif
                                        </div>
                                    @endforeach

                                    @if ($day['occurrences']->count() > 3)
                                        <p class="text-[10px] text-gray-500 px-1">+{{ $day['occurrences']->count() - 3 }} more</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
