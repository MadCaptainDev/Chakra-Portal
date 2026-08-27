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

        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <x-btn :href="route('routines.checking', ['day' => $day->copy()->subDay()->toDateString()])"
                       variant="secondary" size="sm">&larr;</x-btn>
                <p class="text-sm font-semibold text-white">
                    {{ $day->isToday() ? 'Today' : $day->format('D, j M Y') }}
                </p>
                <x-btn :href="route('routines.checking', ['day' => $day->copy()->addDay()->toDateString()])"
                       variant="secondary" size="sm">&rarr;</x-btn>
            </div>
            <p class="text-xs text-brand-100/60">
                {{ $outstandingCount }} outstanding
            </p>
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
                    @foreach ($group['duties'] as $duty)
                        @php $occurrence = $duty['oldest']; @endphp
                        <div class="py-2 first:pt-0 last:pb-0" x-data="{ skipping: false }">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm text-white">{{ $duty['routine']?->title }}</p>
                                    <p class="text-xs text-brand-100/60 mt-0.5">
                                        @if ($duty['is_overdue'])
                                            <span class="font-semibold text-red-300">
                                                {{ $duty['days_late'] }} {{ Str::plural('day', $duty['days_late']) }} late
                                            </span>
                                            &middot;
                                        @endif
                                        due {{ $occurrence->due_on->format('D, j M') }}
                                        @if ($duty['outstanding'] > 1)
                                            &middot; {{ $duty['outstanding'] }} outstanding
                                        @endif
                                        @if ($duty['subject_label'])
                                            &middot; {{ $duty['subject_label'] }}
                                        @endif
                                        @if ($duty['checkpoint'])
                                            &middot; {{ $duty['checkpoint']->name }}
                                        @endif
                                    </p>
                                </div>

                                @can('routines.manage')
                                    <div class="flex items-center gap-1 shrink-0">
                                        <form method="POST" action="{{ route('routines.checking.complete', $occurrence) }}">
                                            @csrf
                                            <input type="hidden" name="day" value="{{ $day->toDateString() }}">
                                            <button type="submit"
                                                    class="min-h-[44px] px-2 text-xs font-semibold text-brand-300 hover:text-brand-200">
                                                Done
                                            </button>
                                        </form>

                                        @if ($duty['outstanding'] > 1)
                                            <form method="POST" action="{{ route('routines.checking.complete', $occurrence) }}">
                                                @csrf
                                                <input type="hidden" name="day" value="{{ $day->toDateString() }}">
                                                <input type="hidden" name="all" value="1">
                                                <button type="submit"
                                                        class="min-h-[44px] px-2 text-xs font-semibold text-brand-300 hover:text-brand-200">
                                                    All {{ $duty['outstanding'] }}
                                                </button>
                                            </form>
                                        @endif

                                        <button type="button" @click="skipping = ! skipping"
                                                class="min-h-[44px] px-2 text-xs font-semibold text-brand-100/60 hover:text-brand-100/80">
                                            Skip
                                        </button>
                                    </div>
                                @endcan
                            </div>

                            @can('routines.manage')
                                <form x-show="skipping" x-cloak method="POST"
                                      action="{{ route('routines.checking.skip', $occurrence) }}"
                                      class="mt-2 flex items-center gap-2">
                                    @csrf
                                    <input type="hidden" name="day" value="{{ $day->toDateString() }}">
                                    <input type="text" name="note" required placeholder="Why is this being skipped?"
                                           class="flex-1 rounded-md border-white/15 text-sm">
                                    <x-btn type="submit" size="sm" variant="secondary">Skip</x-btn>
                                </form>
                            @endcan
                        </div>
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
