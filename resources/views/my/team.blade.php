@php
    use App\Models\TimesheetDay;
    use App\Models\TimesheetEntry;
@endphp

<x-app-layout title="Team timesheet" dark>
    <div class="space-y-6">

        <div class="animate-rise-in">
            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-300">Team</p>
            <h1 class="mt-2 text-3xl sm:text-4xl font-extrabold tracking-tight">{{ $month->format('F Y') }}</h1>
            <p class="mt-2 text-sm text-brand-100/70">A day at a time — accept it, or send it back with a reason.</p>
        </div>

        <x-month-nav route="my.team" :month="$month"
                     :subtitle="TimesheetEntry::formatMinutes($totalMinutes).' across the team'" />

        <div class="grid grid-cols-2 lg:grid-cols-3 gap-3.5">
            <div class="rounded-xl bg-white/5 ring-1 ring-white/10 p-5">
                <p class="text-2xl sm:text-3xl font-extrabold leading-none tabular-nums">{{ TimesheetEntry::formatMinutes($totalMinutes) }}</p>
                <p class="mt-2 text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-100/70">Hours logged</p>
            </div>

            <div @class([
                'rounded-xl p-5 ring-1',
                'bg-gradient-to-br from-amber-400/20 to-white/5 ring-amber-400/40' => $queue->isNotEmpty(),
                'bg-white/5 ring-white/10' => $queue->isEmpty(),
            ])>
                <p class="text-2xl sm:text-3xl font-extrabold leading-none tabular-nums">{{ $queue->count() }}</p>
                <p @class(['mt-2 text-[10px] font-semibold uppercase tracking-[0.16em]', 'text-amber-100' => $queue->isNotEmpty(), 'text-brand-100/70' => $queue->isEmpty()])>
                    Days to decide
                </p>
                <p class="mt-1.5 text-xs {{ $queue->isNotEmpty() ? 'text-amber-100/80' : 'text-brand-100/60' }}">
                    {{ $queue->isNotEmpty() ? 'Waiting on you' : 'All caught up' }}
                </p>
            </div>

            <div class="rounded-xl bg-white/5 ring-1 ring-white/10 p-5 col-span-2 lg:col-span-1">
                <p class="text-2xl sm:text-3xl font-extrabold leading-none tabular-nums">{{ $totalAbsent }}</p>
                <p class="mt-2 text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-100/70">Days unaccounted for</p>
                <p class="mt-1.5 text-xs text-brand-100/60">Of {{ $workingDays }} working {{ Str::plural('day', $workingDays) }} so far</p>
            </div>
        </div>

        {{-- ——— Days waiting on a decision ——— --}}
        <section>
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-300 mb-3.5">Waiting on you</p>

            @if ($queue->isEmpty())
                <div class="rounded-xl border border-dashed border-white/15 px-6 py-10 text-center">
                    <p class="text-sm text-brand-100/70">Every day this month has been decided.</p>
                </div>
            @else
                <div class="space-y-3">
                    @foreach ($queue as $day)
                        @include('my._team-day', ['day' => $day, 'decided' => false])
                    @endforeach
                </div>
            @endif
        </section>

        {{-- ——— Already decided ——— --}}
        @if ($decided->isNotEmpty())
            <section x-data="{ open: false }">
                <button type="button" @click="open = ! open"
                        class="flex items-center gap-2 min-h-[44px] text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-300">
                    Already decided ({{ $decided->count() }})
                    <x-icon name="chevron-right" class="w-4 h-4 transition-transform" ::class="open && 'rotate-90'" />
                </button>

                <div x-show="open" x-cloak class="space-y-3 mt-3">
                    @foreach ($decided as $day)
                        @include('my._team-day', ['day' => $day, 'decided' => true])
                    @endforeach
                </div>
            </section>
        @endif

        {{-- ——— The team ——— --}}
        <section>
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-300 mb-3.5">Your team</p>

            @if ($rows->isEmpty())
                <div class="rounded-xl border border-dashed border-white/15 px-6 py-10 text-center">
                    <p class="text-sm text-brand-100/70">Nobody reports to you yet.</p>
                    <p class="mt-1 text-xs text-brand-100/50">An admin sets managers on the Users screen.</p>
                </div>
            @else
                <div class="rounded-xl bg-white/5 ring-1 ring-white/10 overflow-hidden">
                    @foreach ($rows as $row)
                        <div class="flex items-center gap-3.5 p-4 {{ $loop->first ? '' : 'border-t border-white/10' }}">
                            <x-avatar :name="$row['member']->name" :src="$row['member']->avatarUrl()" size="sm" class="shrink-0" />

                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold truncate">{{ $row['member']->name }}</p>
                                <p class="mt-0.5 text-xs text-brand-100/60">
                                    {{ $row['days'] }} {{ Str::plural('day', $row['days']) }} logged
                                    @if ($row['absent'] > 0)
                                        &middot; <span class="text-amber-300">{{ $row['absent'] }} unaccounted for</span>
                                    @endif
                                </p>
                            </div>

                            <div class="shrink-0 text-right">
                                <p class="text-sm font-semibold tabular-nums">{{ TimesheetEntry::formatMinutes($row['minutes']) }}</p>
                                @if ($row['waiting'] > 0)
                                    <p class="text-[11px] text-amber-300">{{ $row['waiting'] }} to decide</p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</x-app-layout>
