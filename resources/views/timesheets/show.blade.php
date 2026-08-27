@php
    $prev = $month->copy()->subMonthNoOverflow()->format('Y-m');
    $next = $month->copy()->addMonthNoOverflow()->format('Y-m');
    $byDay = $entries->groupBy(fn ($e) => $e->worked_on->toDateString());
@endphp

<x-app-layout title="Timesheet">
    <x-slot name="header">
        <x-page-header :title="$employee->name">
            <x-slot name="actions">
                <a href="{{ route('timesheets.index', ['month' => $month->format('Y-m')]) }}"
                   class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-white/5 border border-white/15 rounded-md font-semibold text-xs text-brand-100/80 uppercase tracking-widest hover:bg-white/[0.09]">
                    All Timesheets
                </a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="max-w-4xl space-y-4">
        <div class="flex items-center justify-between gap-2">
            <a href="{{ route('timesheets.show', [$employee, 'month' => $prev]) }}"
               class="inline-flex items-center min-h-[44px] px-3 rounded-md bg-white/5 border border-white/15 text-sm font-semibold text-brand-100/80 hover:bg-white/[0.09]">&larr; Prev</a>
            <p class="font-semibold text-white">{{ $month->format('F Y') }}</p>
            <a href="{{ route('timesheets.show', [$employee, 'month' => $next]) }}"
               class="inline-flex items-center min-h-[44px] px-3 rounded-md bg-white/5 border border-white/15 text-sm font-semibold text-brand-100/80 hover:bg-white/[0.09]">Next &rarr;</a>
        </div>

        <x-card class="p-4 sm:p-5 border border-white/10">
            <p class="text-xs text-brand-300 uppercase tracking-wide font-semibold">Hours</p>
            <p class="text-2xl sm:text-3xl font-bold text-white mt-1">{{ \App\Models\TimesheetEntry::formatMinutes($totalMinutes) }}</p>
            <p class="text-xs text-brand-100/60 mt-1">{{ $entries->count() }} {{ Str::plural('entry', $entries->count()) }} · {{ $stats['daysWorked'] }} {{ Str::plural('day', $stats['daysWorked']) }} worked</p>
        </x-card>

        @if ($entries->isNotEmpty())
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                <x-charts.daily-bars :days="$stats['daily']" :max-minutes="$stats['maxDaily']" title="Hours per day" />
                <x-charts.horizontal-bars
                    :items="$stats['ventures']"
                    :max-minutes="$stats['maxVenture']"
                    title="By client"
                    empty="No client hours yet."
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

        {{-- Points award --}}
        <x-card class="p-4 sm:p-6 border border-white/10">
            <h3 class="font-semibold text-white mb-1">Points for {{ $month->format('F Y') }}</h3>
            <p class="text-xs text-brand-100/60 mb-4">Only {{ Str::before($employee->name, ' ') }} sees this, on their dashboard.</p>

            <form method="POST" action="{{ route('timesheets.award', $employee) }}">
                @csrf
                <input type="hidden" name="month" value="{{ $month->format('Y-m-d') }}">

                <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                    <div>
                        <x-input-label for="points" value="Points" />
                        <x-text-input id="points" name="points" type="number" min="0" max="1000" class="mt-1 block w-full"
                                      value="{{ old('points', $point->points ?? '') }}" required />
                        <x-input-error :messages="$errors->get('points')" class="mt-2" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label for="note" value="Remark (optional)" />
                        <x-text-input id="note" name="note" type="text" class="mt-1 block w-full"
                                      value="{{ old('note', $point->note ?? '') }}" placeholder="e.g. Great turnaround this month" />
                        <x-input-error :messages="$errors->get('note')" class="mt-2" />
                    </div>
                    <div class="flex items-end">
                        <x-primary-button>{{ $point ? 'Update' : 'Award' }}</x-primary-button>
                    </div>
                </div>
            </form>
        </x-card>

        {{-- Entries --}}
        @if ($entries->isEmpty())
            <x-empty-state message="Nothing logged for {{ $month->format('F Y') }}." />
        @else
            @foreach ($byDay as $day => $dayEntries)
                @php
                    $date = \Illuminate\Support\Carbon::parse($day);
                    $decision = $decisions->get($employee->id.'|'.$day);
                @endphp

                <div x-data="{ rejecting: false }">
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                        <div class="flex flex-wrap items-center gap-2 min-w-0">
                            <h3 class="font-semibold text-white">{{ $date->format('D, d M') }}</h3>
                            @if (! $decision)
                                <span class="inline-flex items-center min-h-[22px] px-2 rounded-md bg-amber-400/15 text-amber-200 text-[10px] font-semibold uppercase tracking-wider">
                                    To decide
                                </span>
                            @elseif ($decision->isRejected())
                                <span class="inline-flex items-center min-h-[22px] px-2 rounded-md bg-red-400/15 text-red-200 text-[10px] font-semibold uppercase tracking-wider">
                                    Sent back
                                </span>
                            @endif
                        </div>

                        <div class="flex items-center gap-2">
                            <span class="text-sm text-brand-100/60">
                                {{ \App\Models\TimesheetEntry::formatMinutes($dayEntries->where('status', '!=', 'cancelled')->sum('minutes')) }}
                            </span>

                            {{-- One decision for the whole day. Deciding again replaces it,
                                 so the buttons stay put after a manager has chosen. --}}
                            <form method="POST" action="{{ route('timesheets.day', $employee) }}">
                                @csrf
                                <input type="hidden" name="worked_on" value="{{ $day }}">
                                <input type="hidden" name="review_state" value="{{ \App\Models\TimesheetDay::APPROVED }}">
                                <button type="submit" @class([
                                    'inline-flex items-center justify-center min-h-[36px] px-3 rounded-md text-[11px] font-semibold uppercase tracking-wider transition',
                                    'bg-green-600 text-white hover:bg-green-700' => ! $decision?->isApproved(),
                                    'bg-green-400/15 text-green-200 cursor-default' => $decision?->isApproved(),
                                ])>
                                    {{ $decision?->isApproved() ? 'Accepted' : 'Accept' }}
                                </button>
                            </form>

                            <button type="button" @click="rejecting = ! rejecting" @class([
                                'inline-flex items-center justify-center min-h-[36px] px-3 rounded-md text-[11px] font-semibold uppercase tracking-wider transition',
                                'border border-white/15 text-brand-100/80 hover:bg-white/[0.09]' => ! $decision?->isRejected(),
                                'bg-red-400/15 text-red-200' => $decision?->isRejected(),
                            ])>
                                {{ $decision?->isRejected() ? 'Rejected' : 'Reject' }}
                            </button>
                        </div>
                    </div>

                    @if ($decision)
                        <p @class([
                            'mb-2 text-xs rounded-md px-2.5 py-1.5 border',
                            'text-green-200 bg-green-400/10 border-green-400/30' => $decision->isApproved(),
                            'text-red-200 bg-red-400/10 border-red-400/30' => $decision->isRejected(),
                        ])>
                            <span class="font-semibold">{{ $decision->stateLabel() }}</span>
                            by {{ $decision->reviewer?->name ?? 'the studio' }}
                            {{ $decision->reviewed_at?->diffForHumans() }}@if ($decision->review_note) — {{ $decision->review_note }}@endif
                        </p>
                    @endif

                    <form x-show="rejecting" x-cloak method="POST"
                          x-transition:enter="transition ease-out duration-200"
                          x-transition:enter-start="opacity-0 -translate-y-1"
                          x-transition:enter-end="opacity-100 translate-y-0"
                          action="{{ route('timesheets.day', $employee) }}" class="mb-2">
                        @csrf
                        <input type="hidden" name="worked_on" value="{{ $day }}">
                        <input type="hidden" name="review_state" value="{{ \App\Models\TimesheetDay::REJECTED }}">
                        <label for="reject_{{ $day }}" class="sr-only">Why this day is being sent back</label>
                        <textarea id="reject_{{ $day }}" name="review_note" rows="2" required
                                  placeholder="What needs changing about this day?"
                                  class="block w-full text-sm rounded-md border-white/15 focus:border-brand-400 focus:ring-brand-400"></textarea>
                        <div class="mt-2 flex justify-end gap-2">
                            <button type="button" @click="rejecting = false"
                                    class="inline-flex items-center min-h-[36px] px-3 rounded-md text-xs font-semibold text-brand-100/70 hover:bg-white/[0.12]">Cancel</button>
                            <button type="submit"
                                    class="inline-flex items-center min-h-[36px] px-3 rounded-md bg-red-600 text-white text-[11px] font-semibold uppercase tracking-wider hover:bg-red-700 transition">
                                Reject day
                            </button>
                        </div>
                        <x-input-error :messages="$errors->get('review_note')" class="mt-2" />
                    </form>

                    <x-card class="divide-y divide-white/10 border border-white/10">
                        @foreach ($dayEntries as $entry)
                            <div class="p-3">
                                <p class="text-sm font-medium text-white truncate">{{ $entry->task }}</p>
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

                                @if ($entry->notes)
                                    <p class="text-xs text-brand-100/70 mt-1">{{ $entry->notes }}</p>
                                @endif
                            </div>
                        @endforeach
                    </x-card>
                </div>
            @endforeach
        @endif
    </div>
</x-app-layout>
