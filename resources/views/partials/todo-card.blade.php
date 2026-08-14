@php
    /*
     * One to-do, as it stood on the day being read.
     *
     * Shared by the employee's own board and by the tracker. $editable is what
     * separates them: a manager reads this screen and cannot touch what is on
     * it, the same line the app draws around a timesheet entry.
     */
    $editable = $editable ?? false;

    // The status it HAD on this day, replayed from the history -- not the one it
    // has now. Rendering today's status against last Tuesday would be an audit
    // screen that lies.
    $shown = $todo->statusOn($day);
    $isPast = ! $day->isToday();
    $drifted = $isPast && $shown !== $todo->status;

    $moved = (int) ($todo->deferrals_count ?? 0);
    $overdue = $todo->isOverdueOn($day);
    $span = $todo->spanDays();
@endphp

{{-- Both panels are hidden until asked for, so a rejected post has to be able to
     reopen the one it came from -- otherwise the errors render inside a closed
     box. Each form carries its own id back, and only that card reacts. --}}
<x-card padding="sm" class="{{ $overdue ? 'ring-1 ring-red-200' : '' }}"
        x-data="{
            open: false,
            blocking: {{ $errors->any() && (int) old('ref') === $todo->id ? 'true' : 'false' }},
            editing: {{ $errors->any() && (int) old('edit_todo_id') === $todo->id ? 'true' : 'false' }},
        }">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="font-semibold text-gray-900 leading-snug break-words">{{ $todo->title }}</p>

            <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-gray-500">
                <x-badge :status="$shown" />

                @if ($drifted)
                    <span class="text-gray-400">now {{ $todo->statusLabel() }}</span>
                @endif

                @if ($span > 1)
                    <span class="font-semibold text-gray-600">
                        Day {{ min($todo->dayOfSpan($day), $span) }} of {{ $span }}
                    </span>
                @endif

                <span @class(['font-semibold text-red-600' => $overdue])>
                    @if ($overdue)
                        Due {{ $todo->due_on->format('D j M') }} — overdue
                    @else
                        Due {{ $todo->due_on->format('D j M') }}
                    @endif
                </span>

                @if ($moved > 0)
                    {{-- Derived from the history rows, never a column. --}}
                    <span @class([
                        'inline-flex items-center px-1.5 py-0.5 rounded-full font-bold',
                        'bg-gray-100 text-gray-600' => $moved < 3,
                        'bg-amber-100 text-amber-800' => $moved >= 3,
                    ])>Moved {{ $moved }}×</span>
                @endif
            </div>

            @if ($todo->notes)
                <p class="mt-2 text-xs text-gray-600 whitespace-pre-line">{{ $todo->notes }}</p>
            @endif
        </div>

        @if ($editable && $todo->isOpen())
            {{-- The one action worth a tap without opening anything. --}}
            <form method="POST" action="{{ route('my.todos.status', $todo) }}" class="shrink-0">
                @csrf
                <input type="hidden" name="status"
                       value="{{ $todo->status === \App\Models\Todo::STATUS_STARTED
                           ? \App\Models\Todo::STATUS_COMPLETED
                           : \App\Models\Todo::STATUS_STARTED }}">
                <x-btn size="sm" variant="{{ $todo->status === \App\Models\Todo::STATUS_STARTED ? 'primary' : 'secondary' }}">
                    {{ $todo->status === \App\Models\Todo::STATUS_STARTED
                        ? 'Finish'
                        : ($todo->status === \App\Models\Todo::STATUS_BLOCKED ? 'Resume' : 'Start') }}
                </x-btn>
            </form>
        @endif
    </div>

    @if ($editable)
        <div class="mt-3 flex flex-wrap items-center gap-2">
            @if ($todo->isOpen())
                <form method="POST" action="{{ route('my.todos.defer', $todo) }}">
                    @csrf
                    <x-btn size="sm" variant="secondary" icon="chevron-right">Move to next day</x-btn>
                </form>

                <x-btn size="sm" variant="ghost" type="button" @click="blocking = ! blocking">Blocked?</x-btn>
            @else
                <form method="POST" action="{{ route('my.todos.status', $todo) }}">
                    @csrf
                    <input type="hidden" name="status" value="{{ \App\Models\Todo::STATUS_WAITING }}">
                    <x-btn size="sm" variant="secondary" icon="refresh">Reopen</x-btn>
                </form>

                @if ($todo->status === \App\Models\Todo::STATUS_COMPLETED)
                    {{-- Hands the title to the timesheet rather than making
                         somebody type the same work out a second time. --}}
                    <x-btn size="sm" variant="ghost" icon="clock"
                           :href="route('my.timesheet', ['month' => $todo->due_on->format('Y-m'), 'task' => $todo->title])">
                        Log the hours
                    </x-btn>
                @endif
            @endif

            <x-btn size="sm" variant="ghost" type="button" @click="editing = ! editing">Edit</x-btn>

            @if ($todo->isOpen())
                <form method="POST" action="{{ route('my.todos.status', $todo) }}"
                      onsubmit="return confirm('Drop this to-do? It stays on the record as cancelled.')">
                    @csrf
                    <input type="hidden" name="status" value="{{ \App\Models\Todo::STATUS_CANCELLED }}">
                    <x-btn size="sm" variant="ghost">Drop</x-btn>
                </form>
            @endif

            <button type="button" @click="open = ! open"
                    class="ml-auto text-[11px] font-semibold text-brand-600 hover:text-brand-700">
                <span x-show="! open">History ({{ $todo->updates->count() }})</span>
                <span x-show="open" x-cloak>Hide history</span>
            </button>
        </div>

        <div x-show="blocking" x-cloak class="mt-3">
            <form method="POST" action="{{ route('my.todos.status', $todo) }}">
                @csrf
                <input type="hidden" name="status" value="{{ \App\Models\Todo::STATUS_BLOCKED }}">
                <input type="hidden" name="ref" value="{{ $todo->id }}">
                {{-- Saying something is blocked without saying what by helps nobody,
                     so the reason is required here and on the server. --}}
                <x-textarea name="note" rows="2" required
                            placeholder="What is holding this up?">{{ (int) old('ref') === $todo->id ? old('note') : '' }}</x-textarea>
                <x-input-error :messages="(int) old('ref') === $todo->id ? $errors->get('note') : []" class="mt-2" />
                <div class="mt-2">
                    <x-btn size="sm" variant="danger">Mark blocked</x-btn>
                </div>
            </form>
        </div>

        <div x-show="editing" x-cloak class="mt-3 pt-3 border-t border-gray-100">
            @include('my._todo-form', ['todo' => $todo, 'day' => $day])

            <form method="POST" action="{{ route('my.todos.destroy', $todo) }}" class="mt-3"
                  onsubmit="return confirm('Delete this to-do and its whole history?')">
                @csrf
                @method('DELETE')
                <x-btn size="sm" variant="ghost" icon="trash">Delete</x-btn>
            </form>
        </div>
    @else
        <div class="mt-3 flex">
            <button type="button" @click="open = ! open"
                    class="ml-auto text-[11px] font-semibold text-brand-600 hover:text-brand-700">
                <span x-show="! open">History ({{ $todo->updates->count() }})</span>
                <span x-show="open" x-cloak>Hide history</span>
            </button>
        </div>
    @endif

    {{-- The whole point of the feature: every change, with the time, grouped by
         the day it happened on. --}}
    <div x-show="open" x-cloak class="mt-3 pt-3 border-t border-gray-100 space-y-3">
        @forelse ($todo->updates->groupBy(fn ($update) => $update->created_at->toDateString()) as $date => $updates)
            @php $on = \Illuminate\Support\Carbon::parse($date); @endphp

            <div>
                <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400">
                    {{ $on->format('D j M Y') }}@if ($on->isToday()) · Today @endif
                </p>

                <ul class="mt-1 space-y-1">
                    @foreach ($updates as $update)
                        <li class="flex items-start gap-2 text-xs text-gray-600">
                            <span class="shrink-0 tabular-nums text-gray-400 w-16">{{ $update->timeLabel() }}</span>
                            <span class="min-w-0">
                                @if ($update->action === \App\Models\TodoUpdate::MOVED)
                                    Moved from {{ $update->from_on?->format('D j M') }}
                                    to {{ $update->to_on?->format('D j M') }}
                                @elseif ($update->to_status)
                                    {{ \App\Models\Todo::STATUSES[$update->to_status] ?? $update->to_status }}
                                @else
                                    {{ $update->actionLabel() }}
                                @endif

                                @if ($update->note)
                                    <span class="text-gray-500">— {{ $update->note }}</span>
                                @endif

                                @if ($update->user && $update->user_id !== $todo->user_id)
                                    <span class="text-gray-400">by {{ $update->user->name }}</span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @empty
            <p class="text-xs text-gray-400">Nothing recorded yet.</p>
        @endforelse
    </div>
</x-card>
