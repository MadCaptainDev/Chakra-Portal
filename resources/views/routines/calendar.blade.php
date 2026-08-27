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
            <p class="text-sm text-amber-200 bg-amber-400/10 ring-1 ring-amber-400/30 rounded-lg px-3 py-2">
                {{ $overdueCount }} overdue open {{ Str::plural('duty', $overdueCount) }} still need a tick or a skip.
            </p>
        @endif

        <x-month-nav route="routines.calendar" :month="$month"
                     :subtitle="$occurrencesByDay->flatten()->count().' occurrence(s)'" />

        {{-- What each pill's colour means -- nothing else on the grid says
             so, and four colours with no key is a guess every time. --}}
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-[11px] text-brand-100/60">
            <span class="inline-flex items-center gap-1.5">
                <span class="w-2.5 h-2.5 rounded-sm bg-white/5 ring-1 ring-white/15"></span> Open
            </span>
            <span class="inline-flex items-center gap-1.5">
                <span class="w-2.5 h-2.5 rounded-sm bg-red-400/10 ring-1 ring-red-400/30"></span> Overdue
            </span>
            <span class="inline-flex items-center gap-1.5">
                <span class="w-2.5 h-2.5 rounded-sm bg-green-400/10 ring-1 ring-green-400/30"></span> Done
            </span>
            <span class="inline-flex items-center gap-1.5">
                <span class="w-2.5 h-2.5 rounded-sm bg-white/10 ring-1 ring-white/15"></span> Skipped
            </span>
        </div>

        <div class="-mx-4 sm:-mx-6 lg:-mx-8 px-4 sm:px-6 lg:px-8 overflow-x-auto pb-2">
            <div class="min-w-[640px]">
                <div class="grid grid-cols-7 gap-1 mb-1">
                    @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $label)
                        <div class="text-center text-[11px] font-semibold text-brand-100/60 uppercase tracking-wide py-1">{{ $label }}</div>
                    @endforeach
                </div>

                <div class="space-y-1">
                    @foreach ($weeks as $week)
                        <div class="grid grid-cols-7 gap-1">
                            @foreach ($week as $day)
                                @php $key = $day['date']->toDateString(); @endphp
                                <div class="min-h-[92px] rounded-xl p-1.5 text-left transition
                                    {{ $day['inMonth'] ? 'bg-brand-900/40 ring-1 ring-white/10 shadow-sm' : 'bg-brand-900/40 ring-1 ring-white/[0.06]' }}
                                    {{ $day['isToday'] ? 'ring-2 ring-brand-400 shadow-md' : '' }}">
                                    <div class="flex items-center justify-between mb-1">
                                        <span class="text-xs font-semibold {{ $day['inMonth'] ? 'text-brand-100/80' : 'text-brand-100/40' }}">
                                            {{ $day['date']->format('j') }}
                                        </span>
                                        @if ($day['overdueCount'] > 0)
                                            <span class="text-[10px] font-semibold text-red-300">{{ $day['overdueCount'] }} late</span>
                                        @elseif ($day['openCount'] > 0)
                                            <span class="text-[10px] font-semibold text-brand-300">{{ $day['openCount'] }} open</span>
                                        @endif
                                    </div>

                                    @foreach ($day['occurrences']->take(3) as $occurrence)
                                        <button type="button" @click="open = open === {{ $occurrence->id }} ? null : {{ $occurrence->id }}"
                                                class="mb-0.5 w-full text-left px-1 py-0.5 rounded text-[10px] leading-tight truncate
                                                {{ $occurrence->status === RoutineOccurrence::STATUS_DONE ? 'bg-green-400/10 text-green-200'
                                                    : ($occurrence->status === RoutineOccurrence::STATUS_SKIPPED ? 'bg-white/10 text-brand-100/60 line-through'
                                                    : ($occurrence->isOverdue() ? 'bg-red-400/10 text-red-200' : 'bg-white/5 text-brand-200')) }}"
                                                title="{{ $occurrence->routine?->title }}">
                                            {{ $occurrence->routine?->title }}
                                            @if ($occurrence->checkpoint) · {{ $occurrence->checkpoint->name }} @endif
                                        </button>
                                        <div x-show="open === {{ $occurrence->id }}" x-cloak class="mb-1 p-1 rounded bg-white/5 ring-1 ring-white/10 text-[10px]">
                                            @if ($label = $occurrence->subjectLabel())
                                                <p class="truncate">{{ $label }}</p>
                                            @endif
                                            <p class="text-brand-100/60">{{ RoutineOccurrence::STATUSES[$occurrence->status] ?? $occurrence->status }}</p>
                                            @if ($occurrence->isOpen())
                                                <form method="POST" action="{{ route('routines.occurrences.skip', $occurrence) }}" class="mt-1 space-y-1">
                                                    @csrf
                                                    <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
                                                    <input type="text" name="note" required placeholder="Skip reason"
                                                           class="w-full text-[10px] rounded border-white/15">
                                                    <button type="submit" class="text-red-300 font-semibold">Skip</button>
                                                </form>
                                            @endif
                                        </div>
                                    @endforeach

                                    @if ($day['occurrences']->count() > 3)
                                        <p class="text-[10px] text-brand-100/60 px-1">+{{ $day['occurrences']->count() - 3 }} more</p>
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
