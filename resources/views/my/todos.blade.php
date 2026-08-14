@php
    $counts = $todos->countBy(fn ($todo) => $todo->statusOn($day));
    $open = $todos->filter(fn ($todo) => $todo->isOpen());
    $done = $todos->reject(fn ($todo) => $todo->isOpen());
    $overdue = $open->filter(fn ($todo) => $todo->isOverdueOn($day));
@endphp

<x-app-layout title="My to-dos">
    <x-slot name="header">
        <x-page-header title="My To-dos" eyebrow="Your work"
                       subtitle="What you mean to do, and where each thing got to.">
            <x-slot name="actions">
                <x-btn :href="route('my.timesheet')" variant="secondary" icon="clock">Timesheet</x-btn>
            </x-slot>
        </x-page-header>
    </x-slot>

    {{-- The add form reopens itself after a rejected post, unless the rejection
         came from one of the edit forms further down the page. --}}
    <div class="space-y-4"
         x-data="{ adding: {{ $errors->any() && ! old('edit_todo_id') && ! old('ref') ? 'true' : 'false' }} }">
        <x-card padding="sm">
            <x-day-nav route="my.todos" :day="$day" />
        </x-card>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <x-stat-card label="On this day" accent="brand" icon="check-circle" :value="$todos->count()" />
            <x-stat-card label="Started" accent="brand" icon="clock"
                         :value="$counts[\App\Models\Todo::STATUS_STARTED] ?? 0" />
            <x-stat-card label="Blocked" accent="red" icon="alert"
                         :value="$counts[\App\Models\Todo::STATUS_BLOCKED] ?? 0" />
            <x-stat-card label="Overdue" :accent="$overdue->count() ? 'red' : 'gray'" icon="calendar"
                         :value="$overdue->count()" />
        </div>

        <div class="flex justify-end">
            <x-btn type="button" @click="adding = ! adding">
                <span x-show="! adding" class="inline-flex items-center gap-1.5">
                    <x-icon name="plus" class="w-4 h-4" /> Add to-do
                </span>
                <span x-show="adding" x-cloak>Cancel</span>
            </x-btn>
        </div>

        <div x-show="adding" x-cloak>
            <x-card padding="md" tone="brand">
                <h3 class="font-semibold text-brand-900 mb-4">New to-do</h3>
                <p class="text-xs text-brand-800/70 mb-4">
                    Give it a last day further out and it stays on the board every day until it is
                    finished. Put somebody else&rsquo;s name on it to hand the job over.
                </p>
                @include('my._todo-form', ['day' => $day])
            </x-card>
        </div>

        @if ($todos->isEmpty())
            <x-empty-state message="Nothing on {{ $day->isToday() ? 'today' : $day->format('D j M') }}.">
                <x-btn type="button" size="sm" @click="adding = true">Add your first to-do</x-btn>
            </x-empty-state>
        @else
            @if ($open->isNotEmpty())
                <div>
                    <x-section-heading title="Still to do" :subtitle="$open->count().' open'" />
                    <div class="space-y-3">
                        @foreach ($open as $todo)
                            @include('partials.todo-card', ['todo' => $todo, 'day' => $day, 'editable' => true])
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($done->isNotEmpty())
                <div>
                    <x-section-heading title="Settled" subtitle="Finished or dropped on this day" />
                    <div class="space-y-3">
                        @foreach ($done as $todo)
                            @include('partials.todo-card', ['todo' => $todo, 'day' => $day, 'editable' => true])
                        @endforeach
                    </div>
                </div>
            @endif
        @endif

        {{-- What this person has handed to other people. Anybody can give
             anybody a job, but only managers can reach the tracker -- without
             this, assigning work is something you do and then never see. --}}
        @if ($assigned->isNotEmpty())
            <div>
                <x-section-heading title="You asked for"
                                   :subtitle="$assigned->count().' with other people on this day'" />
                <div class="space-y-3">
                    @foreach ($assigned as $todo)
                        @include('partials.todo-card', ['todo' => $todo, 'day' => $day, 'editable' => true])
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Work already written down for later days. A board that shows only
             today hides the two-day job somebody lined up for Thursday. --}}
        @if ($later->isNotEmpty())
            <div>
                <x-section-heading title="Coming up"
                                   :subtitle="'Starts after '.$day->format('D j M')" />
                <div class="space-y-3">
                    @foreach ($later as $todo)
                        @include('partials.todo-card', ['todo' => $todo, 'day' => $todo->starts_on, 'editable' => true])
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</x-app-layout>
