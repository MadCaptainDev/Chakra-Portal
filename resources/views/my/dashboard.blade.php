@php
    $user = auth()->user();
    $hour = (int) now()->format('G');
    $greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
    $hoursLabel = \App\Models\TimesheetEntry::formatMinutes($totalMinutes);
@endphp

<x-app-layout title="My dashboard">
    <div class="space-y-6">

        {{-- ——— Hero. This page has no x-page-header of its own: the greeting,
                 the month at a glance and the one action anybody comes here to
                 take all belong in the same block. --}}
        <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-brand-900 via-brand-800 to-brand-700 p-5 sm:p-7 shadow-lg">
            <div class="pointer-events-none absolute -top-16 -right-16 w-52 h-52 rounded-full bg-brand-400/15 blur-2xl" aria-hidden="true"></div>

            <div class="relative flex flex-col sm:flex-row sm:items-center gap-4 sm:gap-5">
                <x-avatar :name="$user->name" :src="$user->avatarUrl()" size="lg"
                          class="ring-4 ring-white/10 shrink-0" />

                <div class="min-w-0 flex-1">
                    <p class="text-brand-200/80 text-sm font-medium">{{ $greeting }},</p>
                    <h1 class="text-2xl sm:text-3xl font-bold text-white truncate tracking-tight">
                        {{ Str::before($user->name, ' ') }}
                    </h1>
                    <p class="text-brand-100/60 text-sm mt-1">
                        {{ $month->format('F Y') }} · {{ $hoursLabel === '—' ? 'nothing logged yet' : $hoursLabel.' logged' }}
                    </p>
                </div>

                <div class="shrink-0">
                    <x-btn :href="route('my.timesheet')" icon="plus" size="lg"
                           class="w-full sm:w-auto !bg-white !text-brand-900 hover:!bg-brand-50">
                        Log work
                    </x-btn>
                </div>
            </div>

            @if ($pendingCount > 0)
                <div class="relative mt-4 pt-4 border-t border-white/10">
                    <a href="{{ route('my.timesheet') }}"
                       class="inline-flex items-center gap-2 text-sm text-amber-200 hover:text-amber-100 font-medium min-h-[44px]">
                        <x-icon name="alert" class="w-4 h-4 shrink-0" />
                        {{ $pendingCount }} {{ Str::plural('entry', $pendingCount) }} still marked pending — review
                        <x-icon name="chevron-right" class="w-4 h-4" />
                    </a>
                </div>
            @endif
        </div>

        {{-- ——— Announcements ——— --}}
        @if ($announcements->isNotEmpty())
            <section>
                <x-section-heading title="From the studio" />
                <div class="space-y-3">
                    @foreach ($announcements as $announcement)
                        <div class="flex items-start gap-3 rounded-xl bg-brand-50 ring-1 ring-brand-200/70 p-4">
                            <span class="shrink-0 inline-flex items-center justify-center w-9 h-9 rounded-lg bg-brand-100 text-brand-700">
                                <x-icon name="megaphone" class="w-5 h-5" />
                            </span>
                            <div class="min-w-0">
                                <p class="font-semibold text-brand-900">{{ $announcement->title }}</p>
                                <p class="text-sm text-brand-800/90 mt-1 whitespace-pre-line">{{ $announcement->body }}</p>
                                <p class="text-[11px] text-brand-600/80 mt-2">{{ $announcement->created_at->diffForHumans() }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- ——— This month ——— --}}
        <section>
            <x-section-heading :title="$month->format('F Y')"
                               subtitle="Your month so far"
                               :href="route('my.timesheet')"
                               link-label="View full timesheet" />

            <div class="grid grid-cols-2 lg:grid-cols-3 gap-3 sm:gap-4">
                <x-stat-card label="Hours logged" value="{{ $hoursLabel }}" accent="brand" icon="clock" />
                <x-stat-card label="Days worked" value="{{ $daysLogged }}" accent="gray" icon="calendar" />
                <x-stat-card label="Points" value="{{ $point?->points ?? '—' }}" accent="green" icon="sparkles"
                             class="col-span-2 lg:col-span-1">
                    {{ $point?->note ?: 'Not yet awarded' }}
                </x-stat-card>
            </div>
        </section>

        {{-- ——— Recent work + points ——— --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6">
            <section>
                <x-section-heading title="Recent work"
                                   :href="route('my.timesheet')"
                                   link-label="All entries" />

                @if ($recentEntries->isEmpty())
                    <x-empty-state message="Nothing logged yet.">
                        <x-btn :href="route('my.timesheet')" size="sm">Log your first entry</x-btn>
                    </x-empty-state>
                @else
                    <x-card class="divide-y divide-gray-100 overflow-hidden">
                        @foreach ($recentEntries as $entry)
                            <div class="p-3 sm:p-4 flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-gray-900 truncate">{{ $entry->task }}</p>
                                    <p class="text-xs text-gray-500 truncate">
                                        {{ $entry->worked_on->format('d M') }}@if ($entry->venture) &middot; {{ $entry->venture }}@endif
                                    </p>
                                </div>
                                <span class="text-xs font-semibold text-gray-600 shrink-0 tabular-nums">{{ $entry->durationLabel() }}</span>
                            </div>
                        @endforeach
                    </x-card>
                @endif
            </section>

            <section>
                <x-section-heading title="Points history" subtitle="Awarded by the studio each month" />

                @if ($recentPoints->isEmpty())
                    <x-empty-state message="No points awarded yet." />
                @else
                    <x-card class="divide-y divide-gray-100 overflow-hidden">
                        @foreach ($recentPoints as $entry)
                            <div class="p-3 sm:p-4">
                                <div class="flex items-center justify-between gap-3">
                                    <p class="text-sm font-semibold text-gray-900">{{ $entry->period->format('F Y') }}</p>
                                    <span class="inline-flex items-center justify-center min-w-[2.25rem] px-2 py-0.5 rounded-full bg-green-100 text-sm font-bold text-green-700 shrink-0 tabular-nums">
                                        {{ $entry->points }}
                                    </span>
                                </div>
                                @if ($entry->note)
                                    <p class="text-xs text-gray-600 mt-1">{{ $entry->note }}</p>
                                @endif
                            </div>
                        @endforeach
                    </x-card>
                @endif
            </section>
        </div>

        {{-- ——— Profile ——— --}}
        @if ($employee || $user->bio || $user->avatar_path)
            <section>
                <x-section-heading title="Your profile"
                                   :href="route('profile.edit')"
                                   link-label="Edit" />

                <x-card padding="md">
                    <div class="flex items-start gap-4">
                        <x-avatar :name="$user->name" :src="$user->avatarUrl()" size="lg" />
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-gray-900">{{ $user->name }}</p>
                            @if ($user->bio)
                                <p class="text-sm text-gray-600 mt-1 whitespace-pre-line">{{ $user->bio }}</p>
                            @else
                                <p class="text-sm text-gray-400 mt-1">No bio yet. Add one from Edit.</p>
                            @endif
                        </div>
                    </div>

                    @if ($employee)
                        <dl class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm mt-4 pt-4 border-t border-gray-100">
                            <div>
                                <dt class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Role</dt>
                                <dd class="text-gray-900 mt-0.5">{{ $employee->role ?: '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Joined</dt>
                                <dd class="text-gray-900 mt-0.5">{{ $employee->joined_on?->format('d M Y') ?: '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Phone</dt>
                                <dd class="text-gray-900 mt-0.5">{{ $user->phone ?: $employee->phone ?: '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Status</dt>
                                <dd class="mt-0.5"><x-badge :status="$employee->is_active ? 'active' : 'inactive'" /></dd>
                            </div>
                        </dl>
                    @endif
                </x-card>
            </section>
        @endif
    </div>
</x-app-layout>
