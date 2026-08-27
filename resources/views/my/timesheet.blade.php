@php
    $byDay = $entries->groupBy(fn ($e) => $e->worked_on->toDateString());
@endphp

<x-app-layout title="My timesheet">
    <x-slot name="header">
        <x-page-header title="My Timesheet" eyebrow="Your work"
                       subtitle="Log what you did, day by day.">
            <x-slot name="actions">
                <x-btn :href="route('my.calendar', ['month' => $month->format('Y-m')])"
                       variant="secondary" icon="calendar">Calendar</x-btn>
            </x-slot>
        </x-page-header>
    </x-slot>

    {{-- Opens straight into the form when a finished to-do sent its title over. --}}
    <div class="space-y-4" x-data="{ adding: {{ $errors->any() || request('task') ? 'true' : 'false' }} }">
        {{-- Sticky, like the to-do board's day nav: which month you are reading
             is the one thing you need at every scroll position. --}}
        <div class="sticky top-0 z-20 -mx-4 px-4 py-2 sm:mx-0 sm:px-0 backdrop-blur bg-brand-900/85">
            <x-card padding="sm">
                <x-month-nav route="my.timesheet" :month="$month" />
            </x-card>
        </div>

        {{-- ——— Entries that need a second look ———
             Shown to the person who wrote them, because they are the only one
             who knows what actually happened that day. The tone is deliberate:
             almost every one of these is a job's length typed where hours were
             asked for, which is a misread form and not a false claim. --}}
        @if ($flags->isNotEmpty())
            <x-card class="p-4 sm:p-5 border border-amber-400/30 bg-amber-400/10">
                <div class="flex items-start gap-3.5">
                    <span class="shrink-0 inline-flex items-center justify-center w-9 h-9 rounded-lg bg-amber-400/15 text-amber-200">
                        <x-icon name="alert" class="w-5 h-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <h3 class="font-semibold text-amber-200">
                            {{ $flags->count() }} {{ Str::plural('entry', $flags->count()) }} here
                            {{ $flags->count() === 1 ? 'needs' : 'need' }} a second look
                        </h3>
                        <p class="mt-1 text-sm text-amber-200/80">
                            These do not add up, so your hours for this month are being read as wrong.
                            Only you know what actually happened — please correct them.
                        </p>

                        <div class="mt-4 space-y-2">
                            @foreach ($flags as $flag)
                                <div class="rounded-lg bg-white/5 ring-1 ring-amber-400/30 p-3">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <p class="text-sm font-semibold text-white">{{ $flag['title'] }}</p>
                                            <p class="mt-0.5 text-xs text-brand-100/70">{{ $flag['detail'] }}</p>
                                            <p class="mt-1 text-xs text-brand-100/60">
                                                @if ($flag['date'])
                                                    {{ \Illuminate\Support\Carbon::parse($flag['date'])->format('D j M') }}
                                                @endif
                                                @if (! empty($flag['task'])) &middot; &ldquo;{{ $flag['task'] }}&rdquo; @endif
                                            </p>
                                        </div>

                                        @if ($flag['entry_id'])
                                            <a href="#entry-{{ $flag['entry_id'] }}"
                                               class="shrink-0 inline-flex items-center min-h-[36px] px-3 rounded-md
                                                      bg-amber-500 text-white text-[11px] font-semibold uppercase
                                                      tracking-wider hover:bg-amber-600 transition-colors">
                                                Fix this
                                            </a>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </x-card>
        @endif

        @if ($olderFlagCount > 0)
            <x-card class="p-4 border border-white/10">
                <p class="text-sm text-brand-100/70">
                    <span class="font-semibold text-white">{{ $olderFlagCount }}</span>
                    {{ Str::plural('entry', $olderFlagCount) }} in earlier months still
                    {{ $olderFlagCount === 1 ? 'needs' : 'need' }} a second look.
                    Use the month arrows above to go back and correct them.
                </p>
            </x-card>
        @endif

        <div class="grid grid-cols-2 gap-3 sm:gap-4">
            <x-stat-card label="Hours this month" accent="brand" icon="clock"
                         value="{{ \App\Models\TimesheetEntry::formatMinutes($totalMinutes) }}" />
            <x-stat-card label="Days with work" accent="gray" icon="calendar"
                         value="{{ $stats['daysWorked'] }}" />
        </div>

        @if ($entries->isNotEmpty())
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                <x-charts.daily-bars :days="$stats['daily']" :max-minutes="$stats['maxDaily']" title="Hours per day" />
                <x-charts.horizontal-bars
                    :items="$stats['ventures']"
                    :max-minutes="$stats['maxVenture']"
                    title="By client"
                    empty="No client hours yet — pick a client on each entry."
                />
            </div>
            @if (collect($stats['taskTypes'])->sum('minutes') > 0)
                <x-charts.horizontal-bars
                    :items="collect($stats['taskTypes'])->map(fn ($row) => ['label' => $row['label'], 'minutes' => $row['minutes']])->all()"
                    :max-minutes="$stats['maxTaskType']"
                    title="By type"
                    :limit="4"
                    :linkable="false"
                />
            @endif
        @endif

        <div class="flex justify-end">
            <x-btn type="button" @click="adding = ! adding">
                <span x-show="! adding" class="inline-flex items-center gap-1.5">
                    <x-icon name="plus" class="w-4 h-4" /> Add entry
                </span>
                <span x-show="adding" x-cloak>Cancel</span>
            </x-btn>
        </div>

        <div x-show="adding" x-cloak x-transition.opacity.duration.200ms>
            <x-card padding="md" tone="brand">
                <h3 class="font-semibold text-white mb-4">New entry</h3>
                @include('my._entry-form', ['ventureOptions' => $ventureOptions])
            </x-card>
        </div>

        @if ($entries->isEmpty())
            <x-empty-state message="Nothing logged for {{ $month->format('F Y') }} yet.">
                <x-btn type="button" size="sm" @click="adding = true">Add your first entry</x-btn>
            </x-empty-state>
        @else
            @foreach ($byDay as $day => $dayEntries)
                @php
                    $date = \Illuminate\Support\Carbon::parse($day);
                    $i = $loop->index;
                @endphp

                <div class="animate-settle" style="animation-delay: {{ min($i, 8) * 45 }}ms">
                    <div class="flex items-center justify-between mb-2 px-1">
                        <h3 class="font-semibold text-white flex items-center gap-2">
                            {{ $date->format('D, d M') }}
                            @if ($date->isToday())
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-brand-400/20 text-[10px] font-bold uppercase tracking-wide text-brand-200">Today</span>
                            @endif
                        </h3>
                        <div class="flex items-center gap-2">
                            @php $decision = $decisions->get($day); @endphp
                            @if ($decision)
                                <span @class([
                                    'inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide',
                                    'bg-green-400/15 text-green-200' => $decision->isApproved(),
                                    'bg-red-400/15 text-red-200' => $decision->isRejected(),
                                ])>{{ $decision->stateLabel() }}</span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-white/10 text-[10px] font-bold uppercase tracking-wide text-brand-100/60">Under review</span>
                            @endif
                            <span class="text-sm font-semibold text-brand-100/70 tabular-nums">
                                {{ \App\Models\TimesheetEntry::formatMinutes($dayEntries->where('status', '!=', 'cancelled')->sum('minutes')) }}
                            </span>
                        </div>
                    </div>

                    {{-- A rejection is the one thing that needs acting on, so it sits
                         above the day rather than inside one of its entries. --}}
                    @if ($decision?->isRejected())
                        <div class="mb-2 flex items-start gap-2 rounded-md bg-red-400/10 border border-red-400/30 px-3 py-2">
                            <x-icon name="alert" class="w-4 h-4 shrink-0 mt-0.5 text-red-300" />
                            <div class="min-w-0">
                                <p class="text-xs font-semibold text-red-200">
                                    {{ $decision->reviewer?->name ? Str::before($decision->reviewer->name, ' ').' sent this day back' : 'This day was sent back' }}
                                </p>
                                <p class="text-xs text-red-200 mt-0.5">{{ $decision->review_note }}</p>
                                <p class="text-[11px] text-red-200/80 mt-1">Fix the entries below — editing puts the day back under review.</p>
                            </div>
                        </div>
                    @endif

                    <x-card class="divide-y divide-white/10 overflow-hidden transition duration-200 hover:shadow-md">
                        @foreach ($dayEntries as $entry)
                            {{-- The id and the hash check are what let "Fix this"
                                 in the panel above scroll here AND open the
                                 editor, with no JavaScript beyond Alpine. --}}
                            <div id="entry-{{ $entry->id }}"
                                 class="p-3 sm:p-4 transition-colors hover:bg-white/[0.09] target:bg-amber-400/10"
                                 x-data="{ editing: false }"
                                 x-init="if (window.location.hash === '#entry-{{ $entry->id }}') editing = true">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0 flex-1">
                                        <p class="font-medium text-white truncate">{{ $entry->task }}</p>
                                        <p class="text-xs text-brand-100/60 truncate mt-0.5">
                                            @if ($entry->venture){{ $entry->ventureLabel() }} &middot; @endif
                                            @if ($entry->started_at)
                                                {{ substr($entry->started_at, 0, 5) }}@if ($entry->ended_at)&ndash;{{ substr($entry->ended_at, 0, 5) }}@endif
                                                &middot;
                                            @endif
                                            {{ $entry->durationLabel() }}
                                        </p>
                                        <div class="mt-2 flex flex-wrap gap-1.5">
                                            <x-badge :status="$entry->task_type ?: 'other'" />

                                            @if ($entry->was_backdated)
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-amber-400/15 text-amber-200 text-[11px] font-semibold">
                                                    <x-icon name="alert" class="w-3 h-3" />
                                                    Filed late
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                </div>

                                @if ($entry->notes)
                                    <p class="mt-2 text-xs text-brand-100/70">{{ $entry->notes }}</p>
                                @endif

                                <div class="mt-2 flex items-center justify-end gap-3">
                                    <button type="button" @click="editing = ! editing"
                                            class="min-h-[44px] px-2 text-xs font-semibold text-brand-500 hover:text-brand-300">
                                        <span x-show="! editing">Edit</span>
                                        <span x-show="editing" x-cloak>Cancel</span>
                                    </button>
                                    <form method="POST" action="{{ route('my.timesheet.destroy', $entry) }}"
                                          onsubmit="return confirm('Delete this entry?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="min-h-[44px] px-2 text-xs font-semibold text-red-300 hover:text-red-200">Delete</button>
                                    </form>
                                </div>

                                <div x-show="editing" x-cloak x-transition.opacity.duration.200ms
                                     class="mt-2 pt-3 border-t border-white/10">
                                    @include('my._entry-form', ['entry' => $entry, 'ventureOptions' => $ventureOptions])
                                </div>
                            </div>
                        @endforeach
                    </x-card>
                </div>
            @endforeach
        @endif
    </div>
</x-app-layout>
