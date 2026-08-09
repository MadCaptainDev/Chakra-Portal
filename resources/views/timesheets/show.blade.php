@php
    $prev = $month->copy()->subMonthNoOverflow()->format('Y-m');
    $next = $month->copy()->addMonthNoOverflow()->format('Y-m');
    $byDay = $entries->groupBy(fn ($e) => $e->worked_on->toDateString());

    $counted = $entries->where('status', '!=', \App\Models\TimesheetEntry::STATUS_CANCELLED);
    $pendingCount = $entries->where('status', \App\Models\TimesheetEntry::STATUS_PENDING)->count();
    $daysLogged = $counted->pluck('worked_on')->map->toDateString()->unique()->count();

    // Where the month actually went. The single most useful thing to see when
    // checking someone's sheet, so it sits above the day-by-day list.
    $byVenture = $counted
        ->groupBy(fn ($e) => $e->venture ?: 'Unassigned')
        ->map(fn ($group, $venture) => ['venture' => $venture, 'minutes' => (int) $group->sum('minutes')])
        ->sortByDesc('minutes')
        ->values();

    $venturePeak = (int) ($byVenture->max('minutes') ?: 1);
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
        <x-month-nav
            :label="$month->format('F Y')"
            :prev-url="route('timesheets.show', [$employee, 'month' => $prev])"
            :next-url="route('timesheets.show', [$employee, 'month' => $next])"
            :today-url="$month->isSameMonth(now()) ? null : route('timesheets.show', $employee)" />

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <x-stat-card label="Hours" value="{{ \App\Models\TimesheetEntry::formatMinutes($totalMinutes) }}" accent="brand" />
            <x-stat-card label="Entries" value="{{ $entries->count() }}" accent="gray" />
            <x-stat-card label="Days worked" value="{{ $daysLogged }}" accent="gray" />
            <x-stat-card label="Pending" value="{{ $pendingCount }}" :accent="$pendingCount > 0 ? 'amber' : 'gray'" />
        </div>

        {{-- Where the time went --}}
        @if ($byVenture->isNotEmpty())
            <x-card class="p-4 sm:p-6">
                <h3 class="font-semibold text-gray-900">Where the time went</h3>
                <p class="text-xs text-gray-500 mt-0.5 mb-4">Counted hours by venture. Cancelled work is excluded.</p>

                <div class="space-y-3">
                    @foreach ($byVenture as $line)
                        <div>
                            <div class="flex items-baseline justify-between gap-3 mb-1">
                                <p class="text-sm font-medium text-gray-800 truncate">{{ $line['venture'] }}</p>
                                <p class="text-sm text-gray-600 shrink-0">
                                    {{ \App\Models\TimesheetEntry::formatMinutes($line['minutes']) }}
                                    <span class="text-xs text-gray-400">
                                        ({{ $totalMinutes > 0 ? round($line['minutes'] / $totalMinutes * 100) : 0 }}%)
                                    </span>
                                </p>
                            </div>
                            <div class="h-2 rounded-full bg-gray-100 overflow-hidden">
                                <div class="h-full rounded-full bg-brand-400"
                                     style="width: {{ max(2, (int) round($line['minutes'] / $venturePeak * 100)) }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-card>
        @endif

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
                        <x-text-input id="points" name="points" type="number" min="0" max="1000" class="mt-1"
                                      value="{{ old('points', $point->points ?? '') }}" required />
                        <x-input-error :messages="$errors->get('points')" class="mt-2" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label for="note" value="Remark (optional)" />
                        <x-text-input id="note" name="note" type="text" class="mt-1"
                                      value="{{ old('note', $point->note ?? '') }}" placeholder="e.g. Great turnaround this month" />
                        <x-input-error :messages="$errors->get('note')" class="mt-2" />
                    </div>
                    <div class="flex items-end">
                        <x-primary-button class="w-full sm:w-auto">{{ $point ? 'Update' : 'Award' }}</x-primary-button>
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
                        <h3 class="font-semibold text-gray-900">
                            {{ $date->format('D, d M') }}
                            @if ($date->isToday())
                                <span class="ml-1 text-xs font-semibold text-brand-500">Today</span>
                            @endif
                        </h3>
                        <span class="text-sm font-semibold text-gray-700">
                            {{ \App\Models\TimesheetEntry::formatMinutes($dayEntries->where('status', '!=', 'cancelled')->sum('minutes')) }}
                        </span>
                    </div>

                    <x-card class="divide-y divide-gray-200">
                        @foreach ($dayEntries as $entry)
                            <div class="p-3 flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-gray-900">{{ $entry->task }}</p>
                                    <p class="text-xs text-gray-500">
                                        @if ($entry->venture)
                                            <span class="font-medium text-gray-600">{{ $entry->venture }}</span> &middot;
                                        @endif
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
