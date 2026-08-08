@php
    $prev = $month->copy()->subMonthNoOverflow()->format('Y-m');
    $next = $month->copy()->addMonthNoOverflow()->format('Y-m');
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Timesheets" />
    </x-slot>

    <div class="space-y-4">
        <div class="flex items-center justify-between gap-2">
            <a href="{{ route('timesheets.index', ['month' => $prev]) }}"
               class="inline-flex items-center min-h-[44px] px-3 rounded-md bg-white border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50">&larr; Prev</a>
            <div class="text-center">
                <p class="font-semibold text-gray-900">{{ $month->format('F Y') }}</p>
                <p class="text-xs text-gray-500">{{ \App\Models\TimesheetEntry::formatMinutes($totalMinutes) }} across the team</p>
            </div>
            <a href="{{ route('timesheets.index', ['month' => $next]) }}"
               class="inline-flex items-center min-h-[44px] px-3 rounded-md bg-white border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50">Next &rarr;</a>
        </div>

        @if ($rows->isEmpty())
            <x-empty-state message="No employee logins yet.">
                <a href="{{ route('users.create') }}" class="text-brand-500 font-semibold text-sm hover:text-brand-600">Create one &rarr;</a>
            </x-empty-state>
        @else
            <x-card class="divide-y divide-gray-200">
                @foreach ($rows as $row)
                    <a href="{{ route('timesheets.show', [$row['employee'], 'month' => $month->format('Y-m')]) }}"
                       class="p-3 sm:p-4 flex items-center gap-3 hover:bg-gray-50">
                        <x-avatar :name="$row['employee']->name" />

                        <div class="min-w-0 flex-1">
                            <p class="font-medium text-gray-900 truncate">{{ $row['employee']->name }}</p>
                            <p class="text-xs text-gray-500">
                                {{ $row['entries'] }} {{ Str::plural('entry', $row['entries']) }}
                                &middot; {{ $row['days'] }} {{ Str::plural('day', $row['days']) }}
                                @if ($row['pending'] > 0)
                                    <span class="text-amber-600 font-semibold">&middot; {{ $row['pending'] }} pending</span>
                                @endif
                            </p>
                        </div>

                        <div class="text-right shrink-0">
                            <p class="text-sm font-bold text-gray-900">{{ \App\Models\TimesheetEntry::formatMinutes($row['minutes']) }}</p>
                            <p class="text-[11px] {{ $row['point'] ? 'text-green-600 font-semibold' : 'text-gray-400' }}">
                                {{ $row['point'] ? $row['point']->points.' pts' : 'no points' }}
                            </p>
                        </div>
                    </a>
                @endforeach
            </x-card>
        @endif
    </div>
</x-app-layout>
