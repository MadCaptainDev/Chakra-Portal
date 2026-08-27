@php
    use App\Models\Todo;

    $flat = $todos->flatten(1);
    $total = $flat->count();
    $filtering = $onlyUser !== null || $status !== null || $venture !== null;
    $waiting = $flat->filter(fn ($todo) => $todo->awaitsReview());
    $overdue = $flat->filter(fn ($todo) => $todo->isOverdueOn($day));

    /*
     * Everybody on this screen is somebody the viewer manages -- an admin
     * manages everyone, and a manager's team IS their reports -- so the verdict
     * controls are on every card without asking again per row.
     */
@endphp

<x-app-layout title="Team to-dos">
    <x-slot name="header">
        <x-page-header title="Team To-dos" eyebrow="Team"
                       subtitle="What everyone is on, and what is waiting on you to check.">
            <x-slot name="actions">
                <x-btn :href="route('my.team')" variant="secondary" icon="clock">Team timesheet</x-btn>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-4" x-data="{ filters: {{ $filtering ? 'true' : 'false' }} }">
        <div class="sticky top-0 z-20 -mx-4 px-4 py-2 sm:mx-0 sm:px-0 backdrop-blur bg-white/5">
            <x-card padding="sm">
                <x-day-nav route="todos.index" :day="$day"
                           :params="array_filter(['user' => $onlyUser, 'status' => $status, 'venture' => $venture])" />
            </x-card>
        </div>

        {{-- The reason a manager opens this screen at all. --}}
        @if ($waiting->isNotEmpty())
            <div class="animate-settle flex items-start gap-3 rounded-xl bg-indigo-400/10 ring-1 ring-indigo-400/30 p-4">
                <span class="shrink-0 inline-flex items-center justify-center w-9 h-9 rounded-lg bg-indigo-400/15 text-indigo-300">
                    <x-icon name="check-circle" class="w-5 h-5" />
                </span>
                <div class="min-w-0">
                    <p class="font-semibold text-indigo-200">
                        {{ $waiting->count() }} finished {{ Str::plural('to-do', $waiting->count()) }}
                        {{ $waiting->count() === 1 ? 'is' : 'are' }} waiting on you
                    </p>
                    <p class="mt-0.5 text-sm text-indigo-200/80">
                        Marking work done is a claim — checking it is what makes this board worth reading.
                    </p>
                </div>
            </div>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-3">
            @if ($total > 0)
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg bg-white/5 ring-1 ring-white/10 font-semibold text-brand-100/80">
                        {{ $total }} on this day
                    </span>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg bg-white/5 ring-1 ring-white/10 font-semibold text-brand-200">
                        {{ $counts[Todo::STATUS_STARTED] ?? 0 }} started
                    </span>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg bg-white/5 ring-1 ring-white/10 font-semibold text-red-200">
                        {{ $counts[Todo::STATUS_BLOCKED] ?? 0 }} blocked
                    </span>
                    @if ($overdue->isNotEmpty())
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg bg-amber-400/10 ring-1 ring-amber-400/30 font-semibold text-amber-200">
                            {{ $overdue->count() }} overdue
                        </span>
                    @endif
                </div>
            @else
                <span></span>
            @endif

            <x-btn type="button" variant="secondary" size="sm" icon="cog" @click="filters = ! filters">
                <span x-show="! filters">Filter</span>
                <span x-show="filters" x-cloak>Hide filters</span>
            </x-btn>
        </div>

        <div x-show="filters" x-cloak x-transition.opacity.duration.200ms>
            <x-card padding="sm">
                <form method="GET" action="{{ route('todos.index') }}" class="flex flex-wrap items-end gap-3">
                    <input type="hidden" name="date" value="{{ $day->toDateString() }}">

                    <div class="min-w-0 flex-1 sm:flex-none sm:w-52">
                        <x-input-label for="filter_user" value="Person" />
                        <x-select id="filter_user" name="user" class="mt-1" onchange="this.form.submit()">
                            <option value="">Everyone</option>
                            @foreach ($team as $member)
                                <option value="{{ $member->id }}" @selected($onlyUser === $member->id)>{{ $member->name }}</option>
                            @endforeach
                        </x-select>
                    </div>

                    <div class="min-w-0 flex-1 sm:flex-none sm:w-52">
                        <x-input-label for="filter_status" value="Status" />
                        <x-select id="filter_status" name="status" class="mt-1" onchange="this.form.submit()">
                            <option value="">Any status</option>
                            @foreach ($statuses as $value => $label)
                                <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                            @endforeach
                        </x-select>
                    </div>

                    <div class="min-w-0 flex-1 sm:flex-none sm:w-60">
                        <x-input-label for="filter_venture" value="Client / Venture" />
                        <x-select id="filter_venture" name="venture" class="mt-1" onchange="this.form.submit()">
                            <option value="">Every client</option>
                            @foreach ($ventureOptions as $option)
                                <option value="{{ $option['value'] }}" @selected($venture === $option['value'])>{{ $option['label'] }}</option>
                            @endforeach
                        </x-select>
                    </div>

                    <div class="flex items-center gap-2">
                        <x-btn size="sm" variant="secondary">Apply</x-btn>
                        @if ($filtering)
                            <x-btn size="sm" variant="ghost"
                                   :href="route('todos.index', ['date' => $day->toDateString()])">Clear</x-btn>
                        @endif
                    </div>
                </form>
            </x-card>
        </div>

        @if ($team->isEmpty())
            <x-empty-state message="Nobody reports to you yet, so there is no board to read." />
        @elseif ($total === 0)
            <x-empty-state message="Nothing on {{ $day->isToday() ? 'today' : $day->format('D j M') }}{{ $filtering ? ' for this filter' : '' }}." />
        @else
            <div class="space-y-6 stagger">
                @foreach ($team as $i => $member)
                    @php
                        $theirs = $todos->get($member->id, collect());
                        $stuck = $theirs->filter(fn ($todo) => $todo->isOverdueOn($day))->count();
                        $theirWaiting = $theirs->filter(fn ($todo) => $todo->awaitsReview())->count();
                    @endphp

                    <div style="--i:{{ $i }}">
                        <div class="flex items-center gap-3 mb-3 px-1">
                            <x-avatar :name="$member->name" :src="$member->avatarUrl()" size="sm" />
                            <div class="min-w-0">
                                <p class="font-semibold text-white leading-tight truncate">{{ $member->name }}</p>
                                <p class="text-xs text-brand-100/60">
                                    {{ $theirs->count() }} {{ \Illuminate\Support\Str::plural('to-do', $theirs->count()) }}
                                    @if ($theirWaiting > 0)
                                        <span class="font-semibold text-indigo-300">· {{ $theirWaiting }} to check</span>
                                    @endif
                                    @if ($stuck > 0)
                                        <span class="font-semibold text-amber-200">· {{ $stuck }} overdue</span>
                                    @endif
                                </p>
                            </div>
                        </div>

                        @if ($theirs->isEmpty())
                            <x-card padding="sm" tone="muted">
                                <p class="text-xs text-brand-100/60">Nothing written down for this day.</p>
                            </x-card>
                        @else
                            <div class="space-y-3">
                                @foreach ($theirs as $todo)
                                    {{-- Reviewable, not editable: a manager gives a
                                         verdict on finished work and never edits it,
                                         the same line the app draws around somebody
                                         else's timesheet entries. --}}
                                    @include('partials.todo-card', [
                                        'todo' => $todo,
                                        'day' => $day,
                                        'editable' => false,
                                        'reviewable' => true,
                                    ])
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-app-layout>
