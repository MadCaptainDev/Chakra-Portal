<x-app-layout title="Timesheets">
    <x-slot name="header">
        <x-page-header title="Timesheets" eyebrow="Team"
                       subtitle="Chase, decide, then read the month — hours by everyone who logs work." />
    </x-slot>

    <div class="space-y-4">
        <x-month-nav route="timesheets.index" :month="$month"
                     :subtitle="\App\Models\TimesheetEntry::formatMinutes($totalMinutes).' across the team'" />

        {{-- Who to chase. Measured against this week, not the month on screen:
             the question is "who do I need to nudge today", which does not
             change because someone paged back to June. --}}
        @if ($behind->isNotEmpty())
            <x-card class="p-4 sm:p-5 border border-amber-400/30 bg-amber-400/10">
                <div class="flex items-start gap-3">
                    <span class="shrink-0 inline-flex items-center justify-center w-9 h-9 rounded-lg bg-amber-400/15 text-amber-200">
                        <x-icon name="alert" class="w-5 h-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-amber-200">
                            {{ $behind->count() }} {{ Str::plural('person', $behind->count()) }} logged nothing this week
                        </p>
                        <p class="text-xs text-amber-200/80 mt-0.5">Since {{ now()->startOfWeek()->format('D d M') }}.</p>

                        <ul class="mt-3 divide-y divide-amber-400/20">
                            @foreach ($behind as $row)
                                <li class="py-2 flex items-center gap-3">
                                    <x-avatar :name="$row['employee']->name" :src="$row['employee']->avatarUrl()" size="sm" />

                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-semibold text-amber-200 truncate">{{ $row['employee']->name }}</p>
                                        <p class="text-[11px] text-amber-200/80">
                                            @if ($row['last'])
                                                Last logged {{ $row['last']->format('d M') }}
                                                @if ($row['daysSince'] !== null)
                                                    &middot; {{ $row['daysSince'] }} {{ Str::plural('day', $row['daysSince']) }} ago
                                                @endif
                                            @else
                                                Has never logged an entry
                                            @endif
                                        </p>
                                    </div>

                                    @if ($row['employee']->email)
                                        {{-- Opens the manager's own mail client rather than
                                             sending anything from the server: no queue, no
                                             deliverability question, and they see what goes. --}}
                                        <a href="mailto:{{ $row['employee']->email }}?subject={{ rawurlencode('Timesheet for this week') }}&body={{ rawurlencode('Hi '.Str::before($row['employee']->name, ' ').",\n\nCould you log your hours for this week when you get a moment?\n\nThanks") }}"
                                           class="shrink-0 inline-flex items-center min-h-[36px] px-3 rounded-md border border-amber-400/30 bg-white/5 text-[11px] font-semibold uppercase tracking-wider text-amber-200 hover:bg-amber-400/15 transition">
                                            Nudge
                                        </a>
                                    @endif

                                    <a href="{{ route('timesheets.show', [$row['employee'], 'month' => $month->format('Y-m')]) }}"
                                       class="shrink-0 text-amber-200 hover:text-amber-200">
                                        <x-icon name="chevron-right" class="w-4 h-4" />
                                        <span class="sr-only">Open {{ $row['employee']->name }}'s timesheet</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </x-card>
        @endif

        @if ($rejectedCount > 0)
            <x-card class="p-4 border border-red-400/30 bg-red-400/10">
                <p class="text-sm text-red-200">
                    <span class="font-semibold">{{ $rejectedCount }}</span>
                    {{ Str::plural('day', $rejectedCount) }} {{ $rejectedCount === 1 ? 'was' : 'were' }}
                    sent back this month and {{ $rejectedCount === 1 ? 'has' : 'have' }} not been redone.
                </p>
            </x-card>
        @endif

        @if ($rows->isEmpty())
            <x-empty-state message="No employee logins yet.">
                <a href="{{ route('users.create') }}" class="text-brand-500 font-semibold text-sm hover:text-brand-300">Create one &rarr;</a>
            </x-empty-state>
        @else
            @include('timesheets._decide-queue')

            @if ($totalMinutes > 0)
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                    <x-charts.horizontal-bars
                        :items="$ranking"
                        :max-minutes="max(1, collect($ranking)->max('minutes') ?: 0)"
                        title="Who worked most"
                        :limit="12"
                        empty="No hours logged this month."
                        :linkable="false"
                    />
                    <x-charts.horizontal-bars
                        :items="$teamStats['ventures']"
                        :max-minutes="$teamStats['maxVenture']"
                        title="By client"
                        :limit="10"
                        empty="No client hours yet."
                    />
                </div>

                <x-charts.daily-bars
                    :days="$teamStats['daily']"
                    :max-minutes="$teamStats['maxDaily']"
                    title="Team hours by day"
                />

                @if (collect($teamStats['taskTypes'])->sum('minutes') > 0)
                    <x-charts.horizontal-bars
                        :items="collect($teamStats['taskTypes'])->map(fn ($row) => ['label' => $row['label'], 'minutes' => $row['minutes']])->all()"
                        :max-minutes="$teamStats['maxTaskType']"
                        title="By type"
                        :limit="4"
                        :linkable="false"
                    />
                @endif
            @endif

            <x-card class="divide-y divide-white/10 overflow-hidden">
                @foreach ($rows->sortByDesc('minutes') as $index => $row)
                    <a href="{{ route('timesheets.show', [$row['employee'], 'month' => $month->format('Y-m')]) }}"
                       class="group p-3 sm:p-4 flex items-center gap-3 min-h-[44px] hover:bg-white/10 transition">
                        {{-- Rank badge: the list is sorted by hours, so make the
                             order itself readable instead of implied. --}}
                        <span class="shrink-0 w-6 text-center text-xs font-bold tabular-nums
                                     {{ $index === 0 ? 'text-brand-300' : 'text-brand-100/40' }}">
                            {{ $index + 1 }}
                        </span>

                        <x-avatar :name="$row['employee']->name" :src="$row['employee']->avatarUrl()" />

                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-white truncate group-hover:text-brand-200 transition">
                                {{ $row['employee']->name }}
                            </p>
                            <p class="text-xs text-brand-100/60">
                                {{ $row['entries'] }} {{ Str::plural('entry', $row['entries']) }}
                                &middot; {{ $row['days'] }} {{ Str::plural('day', $row['days']) }}
                                @if ($row['waiting'] > 0)
                                    <span class="text-amber-300 font-semibold">&middot; {{ $row['waiting'] }} to decide</span>
                                @endif
                            </p>
                        </div>

                        <div class="text-right shrink-0">
                            <p class="text-sm font-bold text-white tabular-nums">{{ \App\Models\TimesheetEntry::formatMinutes($row['minutes']) }}</p>
                            <p class="text-[11px] {{ $row['point'] ? 'text-green-300 font-semibold' : 'text-brand-100/50' }}">
                                {{ $row['point'] ? $row['point']->points.' pts' : 'no points' }}
                            </p>
                        </div>

                        <x-icon name="chevron-right" class="w-4 h-4 shrink-0 text-brand-100/40 group-hover:text-brand-500 transition" />
                    </a>
                @endforeach
            </x-card>
        @endif
    </div>
</x-app-layout>
