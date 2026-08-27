@php
    use App\Models\Todo;

    $counts = $todos->countBy(fn ($todo) => $todo->statusOn($day));
    $open = $todos->filter(fn ($todo) => $todo->isOpen());
    $done = $todos->reject(fn ($todo) => $todo->isOpen());
    $overdue = $open->filter(fn ($todo) => $todo->isOverdueOn($day));
    $sentBack = $todos->filter(fn ($todo) => $todo->wasSentBack());

    /*
     * Three lists, three tabs, rather than one column you scroll to the end of.
     * "You asked for" used to sit below everything, which is the one place work
     * you handed somebody else is guaranteed not to be looked at.
     */
    $tabs = [
        'mine' => ['label' => 'My day', 'count' => $todos->count()],
        'asked' => ['label' => 'You asked for', 'count' => $assigned->count()],
        'later' => ['label' => 'Coming up', 'count' => $later->count()],
    ];
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

    <div class="space-y-4"
         x-data="{
             tab: '{{ $assigned->isNotEmpty() && $todos->isEmpty() ? 'asked' : 'mine' }}',
             adding: {{ $errors->any() && ! old('edit_todo_id') && ! old('ref') ? 'true' : 'false' }},
         }">
        {{-- Sticky, because the day you are looking at is the one thing you
             need to know at every scroll position on this screen. --}}
        <div class="sticky top-0 z-20 -mx-4 px-4 py-2 sm:mx-0 sm:px-0 backdrop-blur bg-brand-900/85">
            <x-card padding="sm">
                <x-day-nav route="my.todos" :day="$day" />
            </x-card>
        </div>

        {{-- Work sent back is the only thing here somebody else is waiting on,
             so it sits above the numbers. --}}
        @if ($sentBack->isNotEmpty())
            <div class="animate-settle flex items-start gap-3 rounded-xl bg-red-400/10 ring-1 ring-red-400/30 p-4">
                <span class="shrink-0 inline-flex items-center justify-center w-9 h-9 rounded-lg bg-red-400/15 text-red-300">
                    <x-icon name="alert" class="w-5 h-5" />
                </span>
                <div class="min-w-0">
                    <p class="font-semibold text-red-200">
                        {{ $sentBack->count() }} {{ Str::plural('to-do', $sentBack->count()) }}
                        {{ $sentBack->count() === 1 ? 'was' : 'were' }} sent back
                    </p>
                    <p class="mt-0.5 text-sm text-red-200/80">The reason is on the card — it is back on your board as started.</p>
                </div>
            </div>
        @endif

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 stagger">
            <div style="--i:0"><x-stat-card label="On this day" accent="brand" icon="check-circle" :value="$todos->count()" /></div>
            <div style="--i:1"><x-stat-card label="Started" accent="brand" icon="clock" :value="$counts[Todo::STATUS_STARTED] ?? 0" /></div>
            <div style="--i:2"><x-stat-card label="Blocked" accent="red" icon="alert" :value="$counts[Todo::STATUS_BLOCKED] ?? 0" /></div>
            <div style="--i:3"><x-stat-card label="Overdue" :accent="$overdue->count() ? 'red' : 'gray'" icon="calendar" :value="$overdue->count()" /></div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <x-tab-nav :tabs="$tabs" />

            <x-btn type="button" @click="adding = ! adding">
                <span x-show="! adding" class="inline-flex items-center gap-1.5">
                    <x-icon name="plus" class="w-4 h-4" /> Add to-do
                </span>
                <span x-show="adding" x-cloak>Cancel</span>
            </x-btn>
        </div>

        <div x-show="adding" x-cloak x-transition.opacity.duration.200ms>
            <x-card padding="md" tone="brand">
                <h3 class="font-semibold text-white mb-1">New to-do</h3>
                <p class="text-xs text-brand-200/70 mb-4">
                    Give it a last day further out and it stays on the board every day until it is
                    finished. Put somebody else&rsquo;s name on it to hand the job over.
                </p>
                @include('my._todo-form', ['day' => $day])
            </x-card>
        </div>

        {{-- ——— My day ——— --}}
        <div x-show="tab === 'mine'" x-cloak x-transition.opacity.duration.200ms class="space-y-4">
            @if ($todos->isEmpty())
                <x-empty-state message="Nothing on {{ $day->isToday() ? 'today' : $day->format('D j M') }}.">
                    <x-btn type="button" size="sm" @click="adding = true">Add your first to-do</x-btn>
                </x-empty-state>
            @else
                @if ($open->isNotEmpty())
                    <div>
                        <x-section-heading title="Still to do" :subtitle="$open->count().' open'" />
                        <div class="space-y-3 stagger">
                            @foreach ($open as $i => $todo)
                                <div style="--i:{{ $i }}">
                                    @include('partials.todo-card', ['todo' => $todo, 'day' => $day, 'editable' => true])
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($done->isNotEmpty())
                    <div>
                        <x-section-heading title="Settled" subtitle="Finished or dropped on this day" />
                        <div class="space-y-3 stagger">
                            @foreach ($done as $i => $todo)
                                <div style="--i:{{ $i }}">
                                    @include('partials.todo-card', ['todo' => $todo, 'day' => $day, 'editable' => true])
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endif
        </div>

        {{-- ——— You asked for ——— --}}
        <div x-show="tab === 'asked'" x-cloak x-transition.opacity.duration.200ms class="space-y-3">
            @forelse ($assigned as $i => $todo)
                <div style="--i:{{ $i }}" class="stagger">
                    @include('partials.todo-card', ['todo' => $todo, 'day' => $day, 'editable' => true])
                </div>
            @empty
                <x-empty-state message="You have not given anybody a job for this day." />
            @endforelse
        </div>

        {{-- ——— Coming up ——— --}}
        <div x-show="tab === 'later'" x-cloak x-transition.opacity.duration.200ms class="space-y-3">
            @forelse ($later as $i => $todo)
                <div style="--i:{{ $i }}" class="stagger">
                    @include('partials.todo-card', ['todo' => $todo, 'day' => $todo->starts_on, 'editable' => true])
                </div>
            @empty
                <x-empty-state message="Nothing lined up after {{ $day->format('D j M') }}." />
            @endforelse
        </div>
    </div>
</x-app-layout>
