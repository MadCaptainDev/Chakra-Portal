<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="'Hello, '.Str::before(auth()->user()->name, ' ')">
            <x-slot name="actions">
                <a href="{{ route('my.timesheet') }}"
                   class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-brand-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-brand-500">
                    Log Work
                </a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-6">
        {{-- Announcements first: they're the reason to look at this page. --}}
        @forelse ($announcements as $announcement)
            <div class="bg-brand-50 border border-brand-200 rounded-lg p-4">
                <p class="font-semibold text-brand-900">{{ $announcement->title }}</p>
                <p class="text-sm text-brand-800 mt-1 whitespace-pre-line">{{ $announcement->body }}</p>
                <p class="text-[11px] text-brand-600 mt-2">{{ $announcement->created_at->diffForHumans() }}</p>
            </div>
        @empty
        @endforelse

        {{-- This month --}}
        <div>
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-semibold text-gray-800">{{ $month->format('F Y') }}</h3>
                <a href="{{ route('my.timesheet') }}" class="text-sm font-semibold text-brand-500 hover:text-brand-600">View full timesheet &rarr;</a>
            </div>
            <div class="grid grid-cols-2 lg:grid-cols-3 gap-4">
                <x-stat-card label="Hours Logged" value="{{ \App\Models\TimesheetEntry::formatMinutes($totalMinutes) }}" accent="brand" />
                <x-stat-card label="Days Worked" value="{{ $daysLogged }}" accent="gray" />
                <x-stat-card label="Points" value="{{ $point?->points ?? '—' }}" accent="green">
                    @if ($point?->note)
                        {{ $point->note }}
                    @else
                        Not yet awarded
                    @endif
                </x-stat-card>
            </div>

            @if ($pendingCount > 0)
                <p class="mt-3 text-sm text-amber-700">
                    You have <span class="font-semibold">{{ $pendingCount }}</span>
                    {{ Str::plural('entry', $pendingCount) }} still marked pending.
                    <a href="{{ route('my.timesheet') }}" class="underline">Review</a>
                </p>
            @endif
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {{-- Recent work --}}
            <div>
                <div class="flex items-center justify-between mb-3">
                    <h3 class="font-semibold text-gray-800">Recent Work</h3>
                    <a href="{{ route('my.timesheet') }}" class="text-sm font-semibold text-brand-500 hover:text-brand-600">All entries &rarr;</a>
                </div>

                @if ($recentEntries->isEmpty())
                    <x-empty-state message="Nothing logged yet.">
                        <a href="{{ route('my.timesheet') }}" class="text-brand-500 font-semibold text-sm hover:text-brand-600">Log your first entry &rarr;</a>
                    </x-empty-state>
                @else
                    <x-card class="divide-y divide-gray-200">
                        @foreach ($recentEntries as $entry)
                            <div class="p-3 flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-gray-900 truncate">{{ $entry->task }}</p>
                                    <p class="text-xs text-gray-500 truncate">
                                        {{ $entry->worked_on->format('d M') }}@if ($entry->venture) &middot; {{ $entry->venture }}@endif
                                    </p>
                                </div>
                                <span class="text-xs text-gray-600 shrink-0">{{ $entry->durationLabel() }}</span>
                            </div>
                        @endforeach
                    </x-card>
                @endif
            </div>

            {{-- Points history --}}
            <div>
                <h3 class="font-semibold text-gray-800 mb-3">Points History</h3>

                @if ($recentPoints->isEmpty())
                    <x-empty-state message="No points awarded yet." />
                @else
                    <x-card class="divide-y divide-gray-200">
                        @foreach ($recentPoints as $entry)
                            <div class="p-3">
                                <div class="flex items-center justify-between gap-3">
                                    <p class="text-sm font-medium text-gray-900">{{ $entry->period->format('F Y') }}</p>
                                    <span class="text-sm font-bold text-green-600 shrink-0">{{ $entry->points }}</span>
                                </div>
                                @if ($entry->note)
                                    <p class="text-xs text-gray-600 mt-1">{{ $entry->note }}</p>
                                @endif
                            </div>
                        @endforeach
                    </x-card>
                @endif
            </div>
        </div>

        @if ($employee || auth()->user()->bio || auth()->user()->avatar_path)
            <x-card class="p-4 sm:p-6">
                <div class="flex items-start justify-between gap-4 mb-3">
                    <h3 class="font-semibold text-gray-900">Your Profile</h3>
                    <a href="{{ route('profile.edit') }}" class="text-sm font-semibold text-brand-500 hover:text-brand-600 shrink-0">Edit Profile</a>
                </div>

                <div class="flex items-start gap-4">
                    <x-avatar :name="auth()->user()->name" :src="auth()->user()->avatarUrl()" size="lg" />
                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-gray-900">{{ auth()->user()->name }}</p>
                        @if (auth()->user()->bio)
                            <p class="text-sm text-gray-600 mt-1 whitespace-pre-line">{{ auth()->user()->bio }}</p>
                        @else
                            <p class="text-sm text-gray-400 mt-1">No bio yet. Add one from Edit Profile.</p>
                        @endif
                    </div>
                </div>

                @if ($employee)
                    <dl class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm mt-4 pt-4 border-t border-gray-100">
                        <div>
                            <dt class="text-gray-500">Role</dt>
                            <dd class="text-gray-900">{{ $employee->role ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Joined</dt>
                            <dd class="text-gray-900">{{ $employee->joined_on?->format('d M Y') ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Phone</dt>
                            <dd class="text-gray-900">{{ auth()->user()->phone ?: $employee->phone ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Status</dt>
                            <dd><x-badge :status="$employee->is_active ? 'active' : 'inactive'" /></dd>
                        </div>
                    </dl>
                @endif
            </x-card>
        @endif
    </div>
</x-app-layout>
