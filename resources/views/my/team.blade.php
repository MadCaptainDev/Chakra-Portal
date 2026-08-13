@php
    use App\Models\TimesheetEntry;
@endphp

<x-app-layout title="Team timesheet" dark>
    <div class="space-y-6">

        <div class="animate-rise-in">
            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-300">Team</p>
            <h1 class="mt-2 text-3xl sm:text-4xl font-extrabold tracking-tight">{{ $month->format('F Y') }}</h1>
            <p class="mt-2 text-sm text-brand-100/70">
                What your team logged, and anything waiting on your decision.
            </p>
        </div>

        <x-month-nav route="my.team" :month="$month"
                     :subtitle="\App\Models\TimesheetEntry::formatMinutes($totalMinutes).' across the team'" />

        <div class="grid grid-cols-2 lg:grid-cols-3 gap-3.5">
            <div class="rounded-xl bg-white/5 ring-1 ring-white/10 p-5">
                <p class="text-2xl sm:text-3xl font-extrabold leading-none tabular-nums">{{ \App\Models\TimesheetEntry::formatMinutes($totalMinutes) }}</p>
                <p class="mt-2 text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-100/70">Hours logged</p>
            </div>

            @if ($queue->isNotEmpty())
                <a href="#queue" class="rounded-xl p-5 ring-1 ring-amber-400/40 bg-gradient-to-br from-amber-400/20 to-white/5 hover:from-amber-400/30 transition-colors">
                    <p class="text-2xl sm:text-3xl font-extrabold leading-none tabular-nums">{{ $queue->count() }}</p>
                    <p class="mt-2 text-[10px] font-semibold uppercase tracking-[0.16em] text-amber-100">Waiting on you</p>
                    <p class="mt-1.5 text-xs text-amber-100/80">Late or edited entries</p>
                </a>
            @else
                <div class="rounded-xl bg-white/5 ring-1 ring-white/10 p-5">
                    <p class="text-2xl sm:text-3xl font-extrabold leading-none tabular-nums">0</p>
                    <p class="mt-2 text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-100/70">Waiting on you</p>
                    <p class="mt-1.5 text-xs text-brand-100/60">Nothing to decide</p>
                </div>
            @endif

            <div class="rounded-xl bg-white/5 ring-1 ring-white/10 p-5 col-span-2 lg:col-span-1">
                <p class="text-2xl sm:text-3xl font-extrabold leading-none tabular-nums">{{ $totalAbsent }}</p>
                <p class="mt-2 text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-100/70">Days unaccounted for</p>
                <p class="mt-1.5 text-xs text-brand-100/60">Across {{ $workingDays }} working {{ Str::plural('day', $workingDays) }} so far</p>
            </div>
        </div>

        {{-- ——— The queue. The reason this screen exists. ——— --}}
        @if ($queue->isNotEmpty())
            <section id="queue">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-300 mb-3.5">Waiting on your decision</p>

                <div class="space-y-2.5">
                    @foreach ($queue as $entry)
                        <div class="rounded-xl bg-amber-400/10 ring-1 ring-amber-400/40 p-4"
                             x-data="{ asking: false, rejecting: false }">

                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="font-semibold">{{ $entry->task }}</p>
                                        <span class="text-[9px] font-semibold uppercase tracking-[0.14em] text-amber-100/80 border border-amber-300/40 rounded-full px-2 py-0.5">
                                            Filed late
                                        </span>
                                    </div>
                                    <p class="mt-1 text-sm text-brand-100/70">
                                        {{ $entry->user->name }} &middot; {{ $entry->worked_on->format('D d M') }}
                                        &middot; {{ $entry->durationLabel() }}
                                        @if ($entry->venture) &middot; {{ $entry->venture }} @endif
                                    </p>
                                    @if ($entry->notes)
                                        <p class="mt-1.5 text-xs text-brand-100/60">{{ $entry->notes }}</p>
                                    @endif
                                </div>

                                <div class="shrink-0 flex flex-wrap items-center gap-2">
                                    <form method="POST" action="{{ route('timesheets.entry.approve', $entry) }}">
                                        @csrf
                                        <button type="submit"
                                                class="inline-flex items-center min-h-[40px] px-3 rounded-md bg-brand-400 text-brand-900 text-[11px] font-semibold uppercase tracking-wider hover:bg-brand-500 transition">
                                            Accept
                                        </button>
                                    </form>

                                    <button type="button" @click="rejecting = ! rejecting; asking = false"
                                            class="inline-flex items-center min-h-[40px] px-3 rounded-md border border-red-400/50 text-red-200 text-[11px] font-semibold uppercase tracking-wider hover:bg-red-400/10 transition">
                                        Reject
                                    </button>

                                    <button type="button" @click="asking = ! asking; rejecting = false"
                                            class="inline-flex items-center min-h-[40px] px-3 rounded-md border border-white/20 text-[11px] font-semibold uppercase tracking-wider hover:bg-white/10 transition">
                                        Ask
                                    </button>
                                </div>
                            </div>

                            {{-- Rejecting requires a reason. "Rejected" on its own tells
                                 somebody their work was refused and nothing about what to
                                 do next. --}}
                            <form x-show="rejecting" x-cloak method="POST"
                                  action="{{ route('timesheets.entry.reject', $entry) }}" class="mt-3">
                                @csrf
                                <textarea name="review_note" rows="2" required
                                          placeholder="Why is this being rejected?"
                                          class="block w-full text-sm rounded-md bg-white/5 border-white/15 text-white placeholder:text-brand-100/35 focus:border-brand-400 focus:ring-brand-400"></textarea>
                                <div class="mt-2 flex justify-end gap-2">
                                    <button type="button" @click="rejecting = false" class="min-h-[40px] px-3 text-xs text-brand-100/70">Cancel</button>
                                    <button type="submit" class="min-h-[40px] px-3 rounded-md bg-red-500 text-white text-[11px] font-semibold uppercase tracking-wider">Reject entry</button>
                                </div>
                            </form>

                            <form x-show="asking" x-cloak method="POST"
                                  action="{{ route('timesheets.entry.query', $entry) }}" class="mt-3">
                                @csrf
                                <textarea name="review_note" rows="2" required
                                          placeholder="What do you need to know?"
                                          class="block w-full text-sm rounded-md bg-white/5 border-white/15 text-white placeholder:text-brand-100/35 focus:border-brand-400 focus:ring-brand-400"></textarea>
                                <div class="mt-2 flex justify-end gap-2">
                                    <button type="button" @click="asking = false" class="min-h-[40px] px-3 text-xs text-brand-100/70">Cancel</button>
                                    <button type="submit" class="min-h-[40px] px-3 rounded-md bg-brand-400 text-brand-900 text-[11px] font-semibold uppercase tracking-wider">Send question</button>
                                </div>
                            </form>
                        </div>
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
                                <p class="text-sm font-semibold tabular-nums">{{ \App\Models\TimesheetEntry::formatMinutes($row['minutes']) }}</p>
                                @if ($row['awaiting'] > 0)
                                    <p class="text-[11px] text-amber-300">{{ $row['awaiting'] }} to decide</p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</x-app-layout>
