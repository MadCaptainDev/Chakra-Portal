@php
    $user = auth()->user();
    $hour = (int) now()->format('G');
    $greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
    $hoursLabel = \App\Models\TimesheetEntry::formatMinutes($totalMinutes);
@endphp

{{--
    The staff dashboard, on the dark ground the sidebar already uses.

    Cards are `bg-white/5` with a `white/10` hairline rather than a cast
    shadow -- the same surface the public site uses -- and the numbers are set
    large in tabular figures so a column of them lines up. Section labels are
    the small tracked caps used throughout the brand.
--}}
<x-app-layout title="My dashboard" dark>
    <div class="space-y-8">

        {{-- ——— Greeting and the one action anyone comes here to take ——— --}}
        <div class="animate-rise-in flex flex-wrap items-end justify-between gap-5">
            <div class="flex items-center gap-4 min-w-0">
                <x-avatar :name="$user->name" :src="$user->avatarUrl()" size="lg"
                          class="ring-2 ring-white/10 shrink-0" />
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-300">
                        {{ $greeting }}, {{ Str::before($user->name, ' ') }}
                    </p>
                    <h1 class="mt-2 text-3xl sm:text-4xl font-extrabold tracking-tight truncate">
                        {{ $month->format('F Y') }}
                    </h1>
                </div>
            </div>

            <a href="{{ route('my.timesheet') }}"
               class="inline-flex items-center justify-center gap-2 min-h-[44px] px-5 rounded-md
                      bg-brand-400 text-brand-900 text-xs font-semibold uppercase tracking-widest
                      hover:bg-brand-500 transition-colors">
                <x-icon name="plus" class="w-4 h-4" />
                Log work
            </a>
        </div>

        {{-- ——— Headline numbers ——— --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3.5">
            <div class="rounded-xl bg-white/5 ring-1 ring-white/10 p-5 sm:p-6">
                <p class="text-3xl sm:text-4xl font-extrabold leading-none tabular-nums tracking-tight">{{ $hoursLabel }}</p>
                <p class="mt-2.5 text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-100/70">Hours this month</p>
            </div>

            <div class="rounded-xl bg-white/5 ring-1 ring-white/10 p-5 sm:p-6">
                <p class="text-3xl sm:text-4xl font-extrabold leading-none tabular-nums tracking-tight">{{ $daysLogged }}</p>
                <p class="mt-2.5 text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-100/70">Days with work</p>
            </div>

            <div class="rounded-xl bg-white/5 ring-1 ring-white/10 p-5 sm:p-6">
                <p class="text-3xl sm:text-4xl font-extrabold leading-none tabular-nums tracking-tight">{{ $point?->points ?? '—' }}</p>
                <p class="mt-2.5 text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-100/70">Points</p>
                <p class="mt-1.5 text-xs text-brand-100/60">{{ $point?->note ?: 'Not yet awarded' }}</p>
            </div>

            {{-- Days a manager has not looked at yet. Nothing here is the
                 employee's to do, so the tile stays quiet either way -- it is
                 information, not a task. --}}
            <div class="rounded-xl bg-white/5 ring-1 ring-white/10 p-5 sm:p-6">
                <p class="text-3xl sm:text-4xl font-extrabold leading-none tabular-nums tracking-tight">{{ $pendingCount }}</p>
                <p class="mt-2.5 text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-100/70">Under review</p>
                <p class="mt-1.5 text-xs text-brand-100/60">
                    {{ $pendingCount === 0 ? 'Every day decided' : Str::plural('day', $pendingCount).' with your manager' }}
                </p>
            </div>
        </div>

        {{-- A day sent back is the one thing here that is actually the
             employee's to act on, so it sits above everything except the
             numbers. --}}
        @if ($rejectedCount > 0)
            <a href="{{ route('my.timesheet') }}"
               class="animate-rise-in flex items-start gap-3.5 rounded-xl bg-red-400/15 ring-1 ring-red-400/40 p-4 sm:p-5
                      hover:bg-red-400/20 transition-colors">
                <span class="shrink-0 inline-flex items-center justify-center w-9 h-9 rounded-lg bg-red-400/20 text-red-300">
                    <x-icon name="alert" class="w-5 h-5" />
                </span>
                <div class="min-w-0">
                    <p class="font-semibold text-red-100">
                        {{ $rejectedCount }} {{ Str::plural('day', $rejectedCount) }}
                        {{ $rejectedCount === 1 ? 'was' : 'were' }} sent back
                    </p>
                    <p class="mt-1 text-sm text-red-100/70">Open your timesheet — the reason is on the day.</p>
                </div>
            </a>
        @endif

        {{-- The breakdown charts live on the timesheet, not here. This is the
             link to them -- the dashboard summarises, the timesheet analyses. --}}
        <a href="{{ route('my.timesheet') }}"
           class="group flex items-center justify-between gap-4 rounded-xl bg-white/5 ring-1 ring-white/10
                  px-5 sm:px-6 py-4 hover:bg-white/[0.07] hover:ring-white/20 transition-colors">
            <span class="text-sm font-semibold">View full timesheet</span>
            <span class="flex items-center gap-2 text-xs text-brand-100/70">
                Every entry, with the month&rsquo;s breakdown
                <x-icon name="chevron-right" class="w-4 h-4 text-brand-300 group-hover:translate-x-0.5 transition-transform" />
            </span>
        </a>

        {{-- ——— Announcements ——— --}}
        @if ($announcements->isNotEmpty())
            <section>
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-300 mb-3.5">From the studio</p>
                <div class="space-y-3">
                    @foreach ($announcements as $announcement)
                        <div class="flex items-start gap-3.5 rounded-xl bg-white/5 ring-1 ring-white/10 p-4 sm:p-5">
                            <span class="shrink-0 inline-flex items-center justify-center w-9 h-9 rounded-lg bg-brand-400/15 text-brand-300">
                                <x-icon name="megaphone" class="w-5 h-5" />
                            </span>
                            <div class="min-w-0">
                                <p class="font-semibold">{{ $announcement->title }}</p>
                                <p class="mt-1 text-sm text-brand-100/70 whitespace-pre-line leading-relaxed">{{ $announcement->body }}</p>
                                <p class="mt-2 text-[11px] text-brand-100/50">{{ $announcement->created_at->diffForHumans() }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- ——— Recent work and points ——— --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <section>
                <div class="flex items-baseline justify-between gap-4 mb-3.5">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-300">Recent work</p>
                    <a href="{{ route('my.timesheet') }}" class="text-xs font-semibold text-brand-300 hover:text-brand-200 transition-colors">All entries</a>
                </div>

                @if ($recentEntries->isEmpty())
                    <div class="rounded-xl border border-dashed border-white/15 px-6 py-10 text-center">
                        <p class="text-sm text-brand-100/70">Nothing logged yet.</p>
                        <a href="{{ route('my.timesheet') }}"
                           class="mt-4 inline-flex items-center justify-center min-h-[44px] px-5 rounded-md
                                  bg-brand-400 text-brand-900 text-xs font-semibold uppercase tracking-widest hover:bg-brand-500 transition-colors">
                            Log your first entry
                        </a>
                    </div>
                @else
                    <div class="rounded-xl bg-white/5 ring-1 ring-white/10 overflow-hidden">
                        @foreach ($recentEntries as $entry)
                            <div class="flex items-center justify-between gap-3 p-4 {{ $loop->first ? '' : 'border-t border-white/10' }}">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold truncate">{{ $entry->task }}</p>
                                    <p class="mt-0.5 text-xs text-brand-100/60 truncate">
                                        {{ $entry->worked_on->format('d M') }}@if ($entry->venture) &middot; {{ $entry->venture }}@endif
                                    </p>
                                </div>
                                <span class="shrink-0 text-sm tabular-nums text-brand-100/80">{{ $entry->durationLabel() }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>

            <section>
                <div class="flex items-baseline justify-between gap-4 mb-3.5">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-300">Points history</p>
                    <p class="text-xs text-brand-100/60">Awarded each month</p>
                </div>

                @if ($recentPoints->isEmpty())
                    <div class="rounded-xl border border-dashed border-white/15 px-6 py-10 text-center">
                        <p class="text-sm text-brand-100/70">No points awarded yet.</p>
                    </div>
                @else
                    <div class="rounded-xl bg-white/5 ring-1 ring-white/10 overflow-hidden">
                        @foreach ($recentPoints as $entry)
                            <div class="p-4 {{ $loop->first ? '' : 'border-t border-white/10' }}">
                                <div class="flex items-center justify-between gap-3">
                                    <p class="text-sm font-semibold">{{ $entry->period->format('F Y') }}</p>
                                    <span class="shrink-0 inline-flex items-center justify-center min-w-[2.25rem] px-2.5 py-0.5 rounded-full
                                                 bg-brand-400/15 border border-brand-400/40 text-sm font-bold tabular-nums text-brand-200">
                                        {{ $entry->points }}
                                    </span>
                                </div>
                                @if ($entry->note)
                                    <p class="mt-1 text-xs text-brand-100/70">{{ $entry->note }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
        </div>

        {{-- ——— Profile ——— --}}
        @if ($employee || $user->bio || $user->avatar_path)
            <section>
                <div class="flex items-baseline justify-between gap-4 mb-3.5">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-300">Your profile</p>
                    <a href="{{ route('profile.edit') }}" class="text-xs font-semibold text-brand-300 hover:text-brand-200 transition-colors">Edit</a>
                </div>

                <div class="rounded-xl bg-white/5 ring-1 ring-white/10 p-5 sm:p-6">
                    <div class="flex items-start gap-4">
                        <x-avatar :name="$user->name" :src="$user->avatarUrl()" size="lg" class="ring-2 ring-white/10 shrink-0" />
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold">{{ $user->name }}</p>
                            @if ($user->bio)
                                <p class="mt-1 text-sm text-brand-100/70 whitespace-pre-line leading-relaxed">{{ $user->bio }}</p>
                            @else
                                <p class="mt-1 text-sm text-brand-100/50">No bio yet. Add one from Edit.</p>
                            @endif
                        </div>
                    </div>

                    @if ($employee)
                        <dl class="grid grid-cols-2 sm:grid-cols-4 gap-4 mt-5 pt-5 border-t border-white/10 text-sm">
                            @foreach ([
                                'Role' => $employee->role ?: '—',
                                'Joined' => $employee->joined_on?->format('d M Y') ?: '—',
                                'Phone' => $user->phone ?: $employee->phone ?: '—',
                            ] as $label => $value)
                                <div>
                                    <dt class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-100/70">{{ $label }}</dt>
                                    <dd class="mt-1">{{ $value }}</dd>
                                </div>
                            @endforeach
                            <div>
                                <dt class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-100/70">Status</dt>
                                <dd class="mt-1">
                                    <span @class([
                                        'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold border',
                                        'bg-brand-400/15 border-brand-400/40 text-brand-200' => $employee->is_active,
                                        'bg-white/5 border-white/20 text-brand-100/70' => ! $employee->is_active,
                                    ])>{{ $employee->is_active ? 'Active' : 'Inactive' }}</span>
                                </dd>
                            </div>
                        </dl>
                    @endif
                </div>
            </section>
        @endif
    </div>
</x-app-layout>
