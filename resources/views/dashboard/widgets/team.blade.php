{{-- ——— Team ——— --}}
<section>
    <div class="flex items-baseline justify-between gap-4 mb-4">
        <x-section-label dark>Team</x-section-label>
        <a href="{{ route('timesheets.index') }}" class="text-xs font-semibold text-brand-300 hover:text-brand-200 transition-colors">All timesheets</a>
    </div>

    {{-- The shape of the year first, then this month's detail. The
         heatmap answers "is this normal for us", which is the question
         the per-person bars underneath cannot. --}}
    <div class="mb-3.5">
        <x-charts.work-heatmap :graphs="$workGraph" />
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3.5">
        <x-card tone="dark" class="p-5 sm:p-6">
            <x-section-label dark>Hours this month</x-section-label>

            @php $hoursMax = max(1, (int) $teamHours->max('minutes')); @endphp

            <div class="mt-5 space-y-3.5">
                @forelse ($teamHours as $row)
                    <a href="{{ route('timesheets.show', $row['employee']) }}" class="block group">
                        <div class="flex items-center gap-2.5 mb-1.5">
                            <x-avatar :name="$row['employee']->name" :src="$row['employee']->avatarUrl()" size="sm" class="shrink-0" />
                            <span class="text-sm flex-1 truncate group-hover:text-brand-200 transition-colors">{{ $row['employee']->name }}</span>
                            @if ($row['pending'] > 0)
                                <span class="text-[10px] text-amber-300">{{ $row['pending'] }} to decide</span>
                            @endif
                            <span class="text-sm tabular-nums text-brand-100/80">{{ \App\Models\TimesheetEntry::formatMinutes($row['minutes']) }}</span>
                        </div>
                        <div class="ml-[38px] h-1.5 rounded-full bg-white/10 overflow-hidden">
                            <div class="h-full rounded-full bg-gradient-to-r from-brand-600 to-brand-400"
                                 style="width: {{ round($row['minutes'] / $hoursMax * 100) }}%"></div>
                        </div>
                    </a>
                @empty
                    <p class="py-6 text-sm text-brand-100/60">No employee logins yet.</p>
                @endforelse
            </div>
        </x-card>

        <div @class([
            'rounded-xl p-5 sm:p-6 ring-1',
            'bg-amber-400/10 ring-amber-400/40' => $teamBehind->isNotEmpty(),
            'bg-white/5 ring-white/10' => $teamBehind->isEmpty(),
        ])>
            <p @class(['text-[10px] font-semibold uppercase tracking-[0.16em]', 'text-amber-100' => $teamBehind->isNotEmpty(), 'text-brand-100/70' => $teamBehind->isEmpty()])>
                Gone quiet this week
            </p>
            <p class="mt-1 text-xs text-brand-100/60">
                {{ $teamBehind->isNotEmpty() ? 'Longest silence first' : 'Nobody to chase' }}
            </p>

            @if ($teamBehind->isEmpty())
                <p class="mt-6 text-sm text-brand-100/70">Everyone has logged something since Monday.</p>
            @else
                <div class="mt-4 divide-y divide-white/10">
                    @foreach ($teamBehind as $row)
                        <div class="flex items-center gap-3 py-2.5">
                            <x-avatar :name="$row['employee']->name" :src="$row['employee']->avatarUrl()" size="sm" class="shrink-0" />
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold truncate">{{ $row['employee']->name }}</p>
                                <p class="text-[11px] text-brand-100/60">
                                    @if ($row['last'])
                                        Last logged {{ $row['last']->format('d M') }}
                                        @if ($row['daysSince'] !== null) &middot; {{ $row['daysSince'] }} {{ Str::plural('day', $row['daysSince']) }} ago @endif
                                    @else
                                        Has never logged an entry
                                    @endif
                                </p>
                            </div>
                            <a href="{{ route('timesheets.show', $row['employee']) }}"
                               class="shrink-0 inline-flex items-center min-h-[36px] px-3 rounded-md border border-white/20
                                      text-[10px] font-semibold uppercase tracking-wider hover:bg-white/10 transition-colors">
                                Open
                            </a>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</section>
