<x-app-layout title="Timesheets">
    <x-slot name="header">
        <x-page-header title="Timesheets" eyebrow="Team"
                       subtitle="Hours logged by everyone with a login, month by month." />
    </x-slot>

    <div class="space-y-4">
        <x-month-nav route="timesheets.index" :month="$month"
                     :subtitle="\App\Models\TimesheetEntry::formatMinutes($totalMinutes).' across the team'" />

        {{-- Who to chase. Measured against this week, not the month on screen:
             the question is "who do I need to nudge today", which does not
             change because someone paged back to June. --}}
        @if ($behind->isNotEmpty())
            <x-card class="p-4 sm:p-5 border border-amber-200 bg-amber-50/60">
                <div class="flex items-start gap-3">
                    <span class="shrink-0 inline-flex items-center justify-center w-9 h-9 rounded-lg bg-amber-100 text-amber-700">
                        <x-icon name="alert" class="w-5 h-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-amber-900">
                            {{ $behind->count() }} {{ Str::plural('person', $behind->count()) }} logged nothing this week
                        </p>
                        <p class="text-xs text-amber-800/80 mt-0.5">Since {{ now()->startOfWeek()->format('D d M') }}.</p>

                        <ul class="mt-3 divide-y divide-amber-200/70">
                            @foreach ($behind as $row)
                                <li class="py-2 flex items-center gap-3">
                                    <x-avatar :name="$row['employee']->name" :src="$row['employee']->avatarUrl()" size="sm" />

                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-semibold text-amber-900 truncate">{{ $row['employee']->name }}</p>
                                        <p class="text-[11px] text-amber-800/80">
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
                                           class="shrink-0 inline-flex items-center min-h-[36px] px-3 rounded-md border border-amber-300 bg-white text-[11px] font-semibold uppercase tracking-wider text-amber-900 hover:bg-amber-100 transition">
                                            Nudge
                                        </a>
                                    @endif

                                    <a href="{{ route('timesheets.show', [$row['employee'], 'month' => $month->format('Y-m')]) }}"
                                       class="shrink-0 text-amber-700 hover:text-amber-900">
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

        @if ($queriedCount > 0)
            <x-card class="p-4 border border-brand-200 bg-brand-50/60">
                <p class="text-sm text-brand-900">
                    <span class="font-semibold">{{ $queriedCount }}</span>
                    {{ Str::plural('entry', $queriedCount) }} {{ $queriedCount === 1 ? 'has' : 'have' }}
                    an open question waiting on a reply.
                </p>
            </x-card>
        @endif

        @if ($rows->isEmpty())
            <x-empty-state message="No employee logins yet.">
                <a href="{{ route('users.create') }}" class="text-brand-500 font-semibold text-sm hover:text-brand-600">Create one &rarr;</a>
            </x-empty-state>
        @else
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

            <x-card class="divide-y divide-gray-100 overflow-hidden">
                @foreach ($rows->sortByDesc('minutes') as $index => $row)
                    <a href="{{ route('timesheets.show', [$row['employee'], 'month' => $month->format('Y-m')]) }}"
                       class="group p-3 sm:p-4 flex items-center gap-3 min-h-[44px] hover:bg-brand-50/40 transition">
                        {{-- Rank badge: the list is sorted by hours, so make the
                             order itself readable instead of implied. --}}
                        <span class="shrink-0 w-6 text-center text-xs font-bold tabular-nums
                                     {{ $index === 0 ? 'text-brand-600' : 'text-gray-300' }}">
                            {{ $index + 1 }}
                        </span>

                        <x-avatar :name="$row['employee']->name" :src="$row['employee']->avatarUrl()" />

                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-gray-900 truncate group-hover:text-brand-700 transition">
                                {{ $row['employee']->name }}
                            </p>
                            <p class="text-xs text-gray-500">
                                {{ $row['entries'] }} {{ Str::plural('entry', $row['entries']) }}
                                &middot; {{ $row['days'] }} {{ Str::plural('day', $row['days']) }}
                                @if ($row['pending'] > 0)
                                    <span class="text-amber-600 font-semibold">&middot; {{ $row['pending'] }} pending</span>
                                @endif
                            </p>
                        </div>

                        <div class="text-right shrink-0">
                            <p class="text-sm font-bold text-gray-900 tabular-nums">{{ \App\Models\TimesheetEntry::formatMinutes($row['minutes']) }}</p>
                            <p class="text-[11px] {{ $row['point'] ? 'text-green-600 font-semibold' : 'text-gray-400' }}">
                                {{ $row['point'] ? $row['point']->points.' pts' : 'no points' }}
                            </p>
                        </div>

                        <x-icon name="chevron-right" class="w-4 h-4 shrink-0 text-gray-300 group-hover:text-brand-500 transition" />
                    </a>
                @endforeach
            </x-card>
        @endif
    </div>
</x-app-layout>
