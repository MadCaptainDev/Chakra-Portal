@php
    $prev = $month->copy()->subMonthNoOverflow()->format('Y-m');
    $next = $month->copy()->addMonthNoOverflow()->format('Y-m');
    $byDay = $entries->groupBy(fn ($e) => $e->worked_on->toDateString());

    $counted = $entries->where('status', '!=', \App\Models\TimesheetEntry::STATUS_CANCELLED);
    $pendingCount = $entries->where('status', \App\Models\TimesheetEntry::STATUS_PENDING)->count();
    $daysLogged = $counted->pluck('worked_on')->map->toDateString()->unique()->count();

    // Minutes per day across the whole month, for the strip under the totals.
    $dailyMinutes = [];
    for ($day = $month->copy()->startOfMonth(); $day->lte($month->copy()->endOfMonth()); $day->addDay()) {
        $key = $day->toDateString();
        $dailyMinutes[$key] = (int) $counted->filter(fn ($e) => $e->worked_on->toDateString() === $key)->sum('minutes');
    }

    // Scale against a full day, so one short day does not read as a full one.
    $peakMinutes = max(480, ...array_values($dailyMinutes ?: [0]));
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

    <div class="space-y-4" x-data="{ adding: {{ $entries->isEmpty() ? 'true' : 'false' }}, filter: 'all' }">
        <x-month-nav
            :label="$month->format('F Y')"
            :prev-url="route('my.timesheet', ['month' => $prev])"
            :next-url="route('my.timesheet', ['month' => $next])"
            :today-url="$month->isSameMonth(now()) ? null : route('my.timesheet')" />

        {{-- Totals --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <x-stat-card label="Hours logged" value="{{ \App\Models\TimesheetEntry::formatMinutes($totalMinutes) }}" accent="brand" />
            <x-stat-card label="Entries" value="{{ $entries->count() }}" accent="gray" />
            <x-stat-card label="Days worked" value="{{ $daysLogged }}" accent="gray" />
            <x-stat-card label="Pending" value="{{ $pendingCount }}" :accent="$pendingCount > 0 ? 'amber' : 'gray'">
                {{ $pendingCount > 0 ? 'Waiting to be finished off' : 'Nothing outstanding' }}
            </x-stat-card>
        </div>

        {{-- Month at a glance. One bar per day, so a gap is obvious. --}}
        @if ($entries->isNotEmpty())
            <x-card class="p-4">
                <div class="flex items-baseline justify-between gap-3 mb-3">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Every day this month</p>
                    <p class="text-[11px] text-gray-400">Tallest bar = {{ \App\Models\TimesheetEntry::formatMinutes($peakMinutes) }}</p>
                </div>

                <div class="flex items-end gap-px h-20" role="img"
                     aria-label="Hours logged on each day of {{ $month->format('F Y') }}">
                    @foreach ($dailyMinutes as $date => $minutes)
                        @php
                            $day = \Illuminate\Support\Carbon::parse($date);
                            $height = $minutes > 0 ? max(6, (int) round($minutes / $peakMinutes * 100)) : 0;
                        @endphp
                        <div class="flex-1 h-full flex items-end"
                             title="{{ $day->format('D d M') }} — {{ \App\Models\TimesheetEntry::formatMinutes($minutes) }}">
                            @if ($height > 0)
                                {{-- Inline height: a Tailwind class built from a
                                     variable is never seen by the JIT. --}}
                                <div class="w-full rounded-sm {{ $day->isToday() ? 'bg-brand-600' : 'bg-brand-300' }}"
                                     style="height: {{ $height }}%"></div>
                            @else
                                <div class="w-full rounded-sm bg-gray-100 {{ $day->isWeekend() ? 'opacity-60' : '' }}"
                                     style="height: 4px"></div>
                            @endif
                        </div>
                    @endforeach
                </div>

                <div class="flex justify-between mt-1.5 text-[10px] text-gray-400">
                    <span>1</span>
                    <span>{{ (int) round($month->daysInMonth / 2) }}</span>
                    <span>{{ $month->daysInMonth }}</span>
                </div>
            </x-card>
        @endif

        {{-- Log work. The primary action on this screen, so it is a full-width
             button rather than a link tucked to one side. --}}
        <button type="button" @click="adding = ! adding"
                class="w-full inline-flex items-center justify-center gap-2 min-h-[52px] px-4 rounded-lg border-2 border-dashed transition-colors"
                :class="adding
                    ? 'border-gray-300 text-gray-500 hover:bg-gray-50'
                    : 'border-brand-300 bg-brand-50/50 text-brand-700 hover:bg-brand-50'">
            {{-- x-show, not <template x-if>: a template renders nothing until
                 Alpine boots, which flashes an empty button. --}}
            <span x-show="! adding" @if ($entries->isEmpty()) style="display: none" @endif
                  class="inline-flex items-center gap-2 font-semibold">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                </svg>
                Log work
            </span>
            <span x-show="adding" x-cloak class="font-semibold">Cancel</span>
        </button>

        <div x-show="adding" x-cloak>
            <x-card class="p-4 sm:p-6">
                @include('my._entry-form', ['ventures' => $ventures])
            </x-card>
        </div>

        @if ($entries->isEmpty())
            <x-empty-state message="Nothing logged for {{ $month->format('F Y') }} yet.">
                <button type="button" @click="adding = true" class="text-brand-500 font-semibold text-sm hover:text-brand-600">
                    Add your first entry &rarr;
                </button>
            </x-empty-state>
        @else
            {{-- Filter. Client-side, so a mis-tap costs nothing. --}}
            <div class="-mx-4 sm:mx-0 px-4 sm:px-0 overflow-x-auto">
                <div class="flex gap-2 min-w-max sm:min-w-0">
                    @php
                        $filters = [
                            'all' => 'All '.$entries->count(),
                            'pending' => 'Pending '.$pendingCount,
                            'completed' => 'Completed '.$entries->where('status', 'completed')->count(),
                            'cancelled' => 'Cancelled '.$entries->where('status', 'cancelled')->count(),
                        ];
                    @endphp
                    @foreach ($filters as $key => $label)
                        <button type="button" @click="filter = @js($key)"
                                class="shrink-0 inline-flex items-center min-h-[40px] px-4 rounded-full border text-xs font-semibold transition-colors"
                                :class="filter === @js($key)
                                    ? 'bg-brand-400 border-brand-400 text-white'
                                    : 'bg-white border-gray-300 text-gray-600 hover:border-brand-300 hover:text-brand-600'">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>

            @foreach ($byDay as $day => $dayEntries)
                @php
                    $date = \Illuminate\Support\Carbon::parse($day);
                    $dayStatuses = $dayEntries->pluck('status')->unique()->values();
                @endphp

                {{-- The whole day hides when its every entry is filtered out. --}}
                <div x-show="filter === 'all' || @js($dayStatuses).includes(filter)" x-cloak>
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="font-semibold text-gray-900">
                            {{ $date->format('D, d M') }}
                            @if ($date->isToday())
                                <span class="ml-1 text-xs font-semibold text-brand-500">Today</span>
                            @elseif ($date->isYesterday())
                                <span class="ml-1 text-xs font-semibold text-gray-400">Yesterday</span>
                            @endif
                        </h3>
                        <span class="text-sm font-semibold text-gray-700">
                            {{ \App\Models\TimesheetEntry::formatMinutes($dayEntries->where('status', '!=', 'cancelled')->sum('minutes')) }}
                        </span>
                    </div>

                    <x-card class="divide-y divide-gray-200">
                        @foreach ($dayEntries as $entry)
                            <div class="p-3 sm:p-4" x-data="{ editing: false }"
                                 x-show="filter === 'all' || filter === @js($entry->status)">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0 flex-1">
                                        <p class="font-medium text-gray-900">{{ $entry->task }}</p>
                                        <p class="text-xs text-gray-500 mt-0.5">
                                            @if ($entry->venture)
                                                <span class="font-medium text-gray-600">{{ $entry->venture }}</span> &middot;
                                            @endif
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
