@php
    $prev = $month->copy()->subMonthNoOverflow()->format('Y-m');
    $next = $month->copy()->addMonthNoOverflow()->format('Y-m');

    $loggedRows = $rows->filter(fn ($row) => $row['entries'] > 0);
    $silentRows = $rows->filter(fn ($row) => $row['entries'] === 0);
    $pendingTotal = $rows->sum('pending');
    $awardedCount = $rows->filter(fn ($row) => $row['point'])->count();
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Timesheets" />
    </x-slot>

    <div class="space-y-4" x-data="{ search: '' }">
        <x-month-nav
            :label="$month->format('F Y')"
            :subtitle="\App\Models\TimesheetEntry::formatMinutes($totalMinutes).' across the team'"
            :prev-url="route('timesheets.index', ['month' => $prev])"
            :next-url="route('timesheets.index', ['month' => $next])"
            :today-url="$month->isSameMonth(now()) ? null : route('timesheets.index')" />

        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <x-stat-card label="Team hours" value="{{ \App\Models\TimesheetEntry::formatMinutes($totalMinutes) }}" accent="brand" />
            <x-stat-card label="Logged this month" value="{{ $loggedRows->count() }}/{{ $rows->count() }}" :accent="$silentRows->isEmpty() ? 'green' : 'amber'">
                {{ $silentRows->isEmpty() ? 'Everyone has filed something' : $silentRows->count().' with nothing logged' }}
            </x-stat-card>
            <x-stat-card label="Pending entries" value="{{ $pendingTotal }}" :accent="$pendingTotal > 0 ? 'amber' : 'gray'">
                {{ $pendingTotal > 0 ? 'Work marked started, not finished' : 'Nothing left open' }}
            </x-stat-card>
            <x-stat-card label="Points awarded" value="{{ $awardedCount }}/{{ $rows->count() }}" :accent="$awardedCount === $rows->count() && $rows->isNotEmpty() ? 'green' : 'gray'" />
        </div>

        @if ($rows->isEmpty())
            <x-empty-state message="No employee logins yet.">
                <a href="{{ route('users.create') }}" class="text-brand-500 font-semibold text-sm hover:text-brand-600">Create one &rarr;</a>
            </x-empty-state>
        @else
            @if ($rows->count() > 5)
                <div>
                    <label for="timesheet-search" class="sr-only">Find someone</label>
                    <input id="timesheet-search" type="search" x-model="search" placeholder="Find someone…"
                           class="w-full sm:max-w-xs rounded-md border-gray-300 shadow-sm focus:border-brand-400 focus:ring-brand-400 min-h-[44px]">
                </div>
            @endif

            <x-card class="divide-y divide-gray-200">
                @foreach ($rows as $row)
                    @php
                        $employee = $row['employee'];
                        $share = $peakMinutes > 0 ? (int) round($row['minutes'] / $peakMinutes * 100) : 0;
                    @endphp

                    <a href="{{ route('timesheets.show', [$employee, 'month' => $month->format('Y-m')]) }}"
                       x-show="! search || @js(Str::lower($employee->name)).includes(search.toLowerCase())"
                       class="block p-3 sm:p-4 hover:bg-gray-50">
                        <div class="flex items-center gap-3">
                            <x-avatar :name="$employee->name" />

                            <div class="min-w-0 flex-1">
                                <div class="flex items-baseline justify-between gap-3">
                                    <p class="font-medium text-gray-900 truncate">{{ $employee->name }}</p>
                                    <p class="text-sm font-bold text-gray-900 shrink-0">
                                        {{ \App\Models\TimesheetEntry::formatMinutes($row['minutes']) }}
                                    </p>
                                </div>

                                {{-- Relative workload. Inline width: the JIT never
                                     sees a class built from a variable. --}}
                                <div class="mt-1.5 h-1.5 rounded-full bg-gray-100 overflow-hidden">
                                    <div class="h-full rounded-full {{ $row['minutes'] > 0 ? 'bg-brand-400' : '' }}"
                                         style="width: {{ min(100, $share) }}%"></div>
                                </div>

                                <div class="mt-1.5 flex items-center justify-between gap-3">
                                    <p class="text-xs text-gray-500 truncate">
                                        @if ($row['entries'] === 0)
                                            <span class="text-amber-600 font-semibold">Nothing logged</span>
                                        @else
                                            {{ $row['entries'] }} {{ Str::plural('entry', $row['entries']) }}
                                            &middot; {{ $row['days'] }} {{ Str::plural('day', $row['days']) }}
                                            @if ($row['pending'] > 0)
                                                <span class="text-amber-600 font-semibold">&middot; {{ $row['pending'] }} pending</span>
                                            @endif
                                        @endif
                                    </p>
                                    <p class="text-[11px] shrink-0 {{ $row['point'] ? 'text-green-600 font-semibold' : 'text-gray-400' }}">
                                        {{ $row['point'] ? $row['point']->points.' pts' : 'no points' }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </a>
                @endforeach
            </x-card>
        @endif
    </div>
</x-app-layout>
