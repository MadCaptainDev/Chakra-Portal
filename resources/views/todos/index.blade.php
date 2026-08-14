@php
    $total = $todos->flatten(1)->count();
    $filtering = $onlyUser !== null || $status !== null;
@endphp

<x-app-layout title="Team to-dos">
    <x-slot name="header">
        <x-page-header title="Team To-dos" eyebrow="Team"
                       subtitle="What everyone is on, and how far each thing has got.">
            <x-slot name="actions">
                <x-btn :href="route('my.team')" variant="secondary" icon="clock">Team timesheet</x-btn>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-4">
        <x-card padding="sm">
            {{-- The filters ride along with the day, so stepping back a day keeps
                 whoever you were looking at. --}}
            <x-day-nav route="todos.index" :day="$day"
                       :params="array_filter(['user' => $onlyUser, 'status' => $status])" />
        </x-card>

        <x-card padding="sm">
            <form method="GET" action="{{ route('todos.index') }}"
                  class="flex flex-wrap items-end gap-3">
                <input type="hidden" name="date" value="{{ $day->toDateString() }}">

                <div class="min-w-0 flex-1 sm:flex-none sm:w-56">
                    <x-input-label for="filter_user" value="Person" />
                    <x-select id="filter_user" name="user" class="mt-1" onchange="this.form.submit()">
                        <option value="">Everyone</option>
                        @foreach ($team as $member)
                            <option value="{{ $member->id }}" @selected($onlyUser === $member->id)>{{ $member->name }}</option>
                        @endforeach
                    </x-select>
                </div>

                <div class="min-w-0 flex-1 sm:flex-none sm:w-56">
                    <x-input-label for="filter_status" value="Status" />
                    <x-select id="filter_status" name="status" class="mt-1" onchange="this.form.submit()">
                        <option value="">Any status</option>
                        @foreach ($statuses as $value => $label)
                            <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
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

        @if ($total > 0)
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <x-stat-card label="On this day" accent="brand" icon="check-circle" :value="$total" />
                <x-stat-card label="Started" accent="brand" icon="clock"
                             :value="$counts[\App\Models\Todo::STATUS_STARTED] ?? 0" />
                <x-stat-card label="Blocked" accent="red" icon="alert"
                             :value="$counts[\App\Models\Todo::STATUS_BLOCKED] ?? 0" />
                <x-stat-card label="Completed" accent="green" icon="check-circle"
                             :value="$counts[\App\Models\Todo::STATUS_COMPLETED] ?? 0" />
            </div>
        @endif

        @if ($team->isEmpty())
            <x-empty-state message="Nobody reports to you yet, so there is no board to read." />
        @elseif ($total === 0)
            <x-empty-state message="Nothing on {{ $day->isToday() ? 'today' : $day->format('D j M') }}{{ $filtering ? ' for this filter' : '' }}." />
        @else
            @foreach ($team as $member)
                @php $theirs = $todos->get($member->id, collect()); @endphp

                <div>
                    <div class="flex items-center gap-3 mb-3 px-1">
                        <x-avatar :name="$member->name" :src="$member->avatarUrl()" size="sm" />
                        <div class="min-w-0">
                            <p class="font-semibold text-gray-900 leading-tight truncate">{{ $member->name }}</p>
                            <p class="text-xs text-gray-500">
                                {{ $theirs->count() }} {{ \Illuminate\Support\Str::plural('to-do', $theirs->count()) }}
                                @php $stuck = $theirs->filter(fn ($todo) => $todo->isOverdueOn($day))->count(); @endphp
                                @if ($stuck > 0)
                                    <span class="font-semibold text-red-600">· {{ $stuck }} overdue</span>
                                @endif
                            </p>
                        </div>
                    </div>

                    @if ($theirs->isEmpty())
                        <x-card padding="sm" tone="muted">
                            <p class="text-xs text-gray-500">Nothing written down for this day.</p>
                        </x-card>
                    @else
                        <div class="space-y-3">
                            @foreach ($theirs as $todo)
                                {{-- Read-only: a manager reads this board and does not
                                     write on it, the same line the app draws around
                                     somebody else's timesheet entries. --}}
                                @include('partials.todo-card', ['todo' => $todo, 'day' => $day, 'editable' => false])
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        @endif
    </div>
</x-app-layout>
