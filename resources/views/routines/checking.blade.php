@php
    use App\Models\RoutineOccurrence;
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Routine check" subtitle="Who still owes what, today.">
            <x-slot name="actions">
                <x-btn :href="route('routines.calendar')" variant="secondary" icon="calendar">Month</x-btn>
                <x-btn :href="route('routines.index')" variant="secondary">Definitions</x-btn>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="max-w-3xl space-y-4">
        {{-- A routine that has silently stopped generating is the failure this
             screen would otherwise hide: no duties looks identical to all done. --}}
        @foreach ($warnings as $row)
            <p class="text-sm text-amber-200 bg-amber-400/10 ring-1 ring-amber-400/30 rounded-lg px-3 py-2">
                <span class="font-semibold">{{ $row['routine']->title }}</span> —
                {{ $row['warning'] }}
                @can('routines.edit')
                    <a href="{{ route('routines.index') }}" class="font-semibold underline">Fix it</a>
                @endcan
            </p>
        @endforeach

        <x-card padding="sm">
            <x-day-nav route="routines.checking" :day="$day" param="day" />
        </x-card>

        {{-- The three numbers this screen exists to answer at a glance --
             everything else on the page is detail underneath them. --}}
        <div class="grid grid-cols-3 gap-3">
            <x-stat-card label="Outstanding" :value="$outstandingCount" icon="clipboard-list"
                accent="{{ $outstandingCount > 0 ? 'amber' : 'green' }}" />
            <x-stat-card label="Late" :value="$lateCount" icon="alert"
                accent="{{ $lateCount > 0 ? 'red' : 'green' }}" />
            <x-stat-card label="Settled" :value="$settled->count()" icon="check-circle" accent="green"
                :trendLabel="$day->isToday() ? 'today' : $day->format('j M')" trend="neutral" />
        </div>

        @forelse ($groups as $group)
            <x-card class="p-3 sm:p-4">
                <div class="flex items-center justify-between gap-3 mb-2">
                    <p class="font-semibold text-white">{{ $group['name'] }}</p>
                    @if ($group['late'] > 0)
                        <span class="text-xs font-semibold text-red-300">{{ $group['late'] }} late</span>
                    @else
                        <span class="text-xs text-brand-100/60">{{ $group['duties']->count() }} to do</span>
                    @endif
                </div>

                <div class="divide-y divide-white/10">
                    @foreach ($group['tasks'] as $task)
                        @include('routines._checking-task', ['task' => $task, 'day' => $day])
                    @endforeach
                </div>
            </x-card>
        @empty
            <x-empty-state message="Nothing outstanding. Everything due has been done or skipped." />
        @endforelse

        @if ($settled->isNotEmpty())
            <section>
                <h2 class="text-sm font-semibold uppercase tracking-wider text-brand-100/60 mb-2">
                    Settled {{ $day->isToday() ? 'today' : 'that day' }}
                </h2>
                <x-card padding="sm" class="divide-y divide-white/10">
                    @foreach ($settled as $occurrence)
                        <div class="py-2 first:pt-0 last:pb-0 flex items-baseline justify-between gap-3">
                            <p class="text-sm text-brand-100/80 min-w-0 truncate">
                                {{ $occurrence->routine?->title }}
                                @if ($occurrence->checkpoint)
                                    <span class="text-xs text-brand-100/50">· {{ $occurrence->checkpoint->name }}</span>
                                @endif
                            </p>
                            <p class="text-xs shrink-0 {{ $occurrence->status === RoutineOccurrence::STATUS_DONE ? 'text-green-200' : 'text-brand-100/60' }}">
                                {{ RoutineOccurrence::STATUSES[$occurrence->status] ?? $occurrence->status }}
                                @if ($occurrence->completedByUser)
                                    · {{ $occurrence->completedByUser->name }}
                                @endif
                            </p>
                        </div>
                    @endforeach
                </x-card>
            </section>
        @endif
    </div>
</x-app-layout>
