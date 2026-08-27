@php
    use App\Models\Todo;
    use App\Models\TodoUpdate;

    /*
     * One to-do, as it stood on the day being read.
     *
     * Three audiences, one card. $editable is the person whose job it is (or
     * who asked for it); $reviewable is a manager, who can give a verdict on
     * finished work and nothing else; everybody else reads.
     */
    $editable = $editable ?? false;
    $reviewable = $reviewable ?? false;

    // The status it HAD on this day, replayed from the history -- not the one
    // it has now. Rendering today's status against last Tuesday would be an
    // audit screen that lies.
    $shown = $todo->statusOn($day);
    $isPast = ! $day->isToday();
    $drifted = $isPast && $shown !== $todo->status;

    $moved = (int) ($todo->deferrals_count ?? 0);
    $overdue = $todo->isOverdueOn($day);
    $span = $todo->spanDays();
    $waiting = $todo->awaitsReview();

    // One accent for the whole card, so a glance down a column reads as a
    // shape rather than as text to be parsed.
    $edge = match (true) {
        $todo->wasSentBack() => 'before:bg-red-400',
        $todo->isApproved() => 'before:bg-green-400',
        $waiting => 'before:bg-indigo-400',
        $overdue => 'before:bg-amber-400',
        $shown === Todo::STATUS_STARTED => 'before:bg-brand-400',
        $shown === Todo::STATUS_BLOCKED => 'before:bg-red-400',
        default => 'before:bg-white/15',
    };
@endphp

<div x-data="{
        open: false,
        blocking: {{ $errors->any() && (int) old('ref') === $todo->id ? 'true' : 'false' }},
        editing: {{ $errors->any() && (int) old('edit_todo_id') === $todo->id ? 'true' : 'false' }},
        sending: {{ $errors->any() && (int) old('review_ref') === $todo->id ? 'true' : 'false' }},
     }"
     class="relative overflow-hidden rounded-xl bg-white/5 shadow-sm ring-1 ring-white/10
            transition duration-200 hover:shadow-md hover:ring-white/15
            before:absolute before:inset-y-0 before:left-0 before:w-1 {{ $edge }}">
    <div class="p-3 sm:p-4 pl-4 sm:pl-5">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p @class([
                    'font-semibold leading-snug break-words',
                    'text-white' => $todo->isOpen(),
                    'text-brand-100/60 line-through decoration-brand-100/40' => $shown === Todo::STATUS_COMPLETED,
                    'text-brand-100/50 line-through decoration-brand-100/40' => $shown === Todo::STATUS_CANCELLED,
                ])>{{ $todo->title }}</p>

                <div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1.5 text-[11px] text-brand-100/60">
                    <x-badge :status="$shown" class="animate-pop" />

                    @if ($todo->reviewLabel())
                        <span @class([
                            'inline-flex items-center gap-1 px-2 py-0.5 rounded-full font-bold animate-pop',
                            'bg-green-400/15 text-green-200' => $todo->isApproved(),
                            'bg-red-400/15 text-red-200' => $todo->wasSentBack(),
                        ])>
                            <x-icon :name="$todo->isApproved() ? 'check-circle' : 'alert'" class="w-3 h-3" />
                            {{ $todo->reviewLabel() }}
                        </span>
                    @elseif ($waiting)
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-indigo-400/15 text-indigo-200 font-bold">
                            Waiting to be checked
                        </span>
                    @endif

                    @if ($drifted)
                        <span class="text-brand-100/50">now {{ $todo->statusLabel() }}</span>
                    @endif

                    @if ($span > 1)
                        <span class="font-semibold text-brand-100/70">
                            Day {{ min($todo->dayOfSpan($day), $span) }} of {{ $span }}
                        </span>
                    @endif

                    <span @class(['font-semibold text-amber-200' => $overdue])>
                        Due {{ $todo->due_on->format('D j M') }}{{ $overdue ? ' — overdue' : '' }}
                    </span>

                    @if ($todo->venture)
                        <span class="inline-flex items-center px-1.5 py-0.5 rounded-full bg-white/10 text-brand-100/80 font-semibold">
                            {{ $todo->venture }}
                        </span>
                    @endif

                    @unless ($todo->isSelfAssigned())
                        <span class="text-brand-100/60">
                            @if ($todo->user_id === auth()->id())
                                from {{ $todo->assignedBy?->name ?? 'a deleted account' }}
                            @else
                                for {{ $todo->user->name }}
                            @endif
                        </span>
                    @endunless

                    @if ($moved > 0)
                        {{-- Derived from the history rows, never a column. --}}
                        <span @class([
                            'inline-flex items-center px-1.5 py-0.5 rounded-full font-bold',
                            'bg-white/10 text-brand-100/70' => $moved < 3,
                            'bg-amber-400/15 text-amber-200' => $moved >= 3,
                        ])>Moved {{ $moved }}&times;</span>
                    @endif
                </div>

                @if ($todo->notes)
                    <p class="mt-2 text-xs text-brand-100/70 whitespace-pre-line">{{ $todo->notes }}</p>
                @endif
            </div>

            @if ($editable && $todo->isOpen())
                {{-- The one action worth a tap without opening anything. --}}
                <form method="POST" action="{{ route('my.todos.status', $todo) }}" class="shrink-0">
                    @csrf
                    <input type="hidden" name="status"
                           value="{{ $todo->status === Todo::STATUS_STARTED ? Todo::STATUS_COMPLETED : Todo::STATUS_STARTED }}">
                    <x-btn size="sm" :variant="$todo->status === Todo::STATUS_STARTED ? 'primary' : 'secondary'">
                        {{ $todo->status === Todo::STATUS_STARTED
                            ? 'Finish'
                            : ($todo->status === Todo::STATUS_BLOCKED ? 'Resume' : 'Start') }}
                    </x-btn>
                </form>
            @endif
        </div>

        {{-- Why it came back. The one thing on this card somebody has to read. --}}
        @if ($todo->wasSentBack() && $todo->review_note)
            <div class="mt-3 flex items-start gap-2.5 rounded-lg bg-red-400/10 ring-1 ring-red-400/20 px-3 py-2.5 animate-settle">
                <x-icon name="alert" class="w-4 h-4 shrink-0 mt-0.5 text-red-300" />
                <p class="text-xs text-red-200 min-w-0">
                    <span class="font-semibold">{{ $todo->reviewer?->name ?? 'A manager' }} sent this back</span>
                    — {{ $todo->review_note }}
                </p>
            </div>
        @endif

        <div class="mt-3 flex flex-wrap items-center gap-2">
            @if ($editable)
                @if ($todo->isOpen())
                    <form method="POST" action="{{ route('my.todos.defer', $todo) }}">
                        @csrf
                        <x-btn size="sm" variant="secondary" icon="chevron-right">Move to next day</x-btn>
                    </form>

                    <x-btn size="sm" variant="ghost" type="button" @click="blocking = ! blocking">Blocked?</x-btn>
                @else
                    <form method="POST" action="{{ route('my.todos.status', $todo) }}">
                        @csrf
                        <input type="hidden" name="status" value="{{ Todo::STATUS_WAITING }}">
                        <x-btn size="sm" variant="secondary" icon="refresh">Reopen</x-btn>
                    </form>

                    @if ($todo->status === Todo::STATUS_COMPLETED)
                        {{-- Hands the work to the timesheet rather than making
                             somebody type it out a second time. --}}
                        <x-btn size="sm" variant="ghost" icon="clock"
                               :href="route('my.timesheet', [
                                   'month' => $todo->due_on->format('Y-m'),
                                   'task' => $todo->title,
                                   'venture' => $todo->venture,
                               ])">
                            Log the hours
                        </x-btn>
                    @endif
                @endif

                <x-btn size="sm" variant="ghost" type="button" @click="editing = ! editing">Edit</x-btn>

                @if ($todo->isOpen())
                    <form method="POST" action="{{ route('my.todos.status', $todo) }}"
                          onsubmit="return confirm('Drop this to-do? It stays on the record as cancelled.')">
                        @csrf
                        <input type="hidden" name="status" value="{{ Todo::STATUS_CANCELLED }}">
                        <x-btn size="sm" variant="ghost">Drop</x-btn>
                    </form>
                @endif
            @endif

            {{-- A manager's half: a verdict on finished work, and nothing else.
                 Same line the app draws around a timesheet -- decide the day,
                 never edit the entries. --}}
            @if ($reviewable && ! $todo->isOpen())
                <form method="POST" action="{{ route('todos.review', $todo) }}">
                    @csrf
                    <input type="hidden" name="review_state" value="{{ Todo::REVIEW_APPROVED }}">
                    <x-btn size="sm" :variant="$waiting ? 'primary' : 'ghost'" icon="check-circle">
                        {{ $todo->isApproved() ? 'Checked' : 'Looks right' }}
                    </x-btn>
                </form>

                <x-btn size="sm" variant="ghost" type="button" @click="sending = ! sending">Send back</x-btn>
            @endif

            <button type="button" @click="open = ! open"
                    class="ml-auto text-[11px] font-semibold text-brand-300 hover:text-brand-200 transition-colors">
                <span x-show="! open">History ({{ $todo->updates->count() }})</span>
                <span x-show="open" x-cloak>Hide history</span>
            </button>
        </div>

        @if ($editable)
            <div x-show="blocking" x-cloak x-transition.opacity.duration.200ms class="mt-3">
                <form method="POST" action="{{ route('my.todos.status', $todo) }}">
                    @csrf
                    <input type="hidden" name="status" value="{{ Todo::STATUS_BLOCKED }}">
                    <input type="hidden" name="ref" value="{{ $todo->id }}">
                    {{-- Saying something is blocked without saying what by helps
                         nobody, so the reason is required here and on the server. --}}
                    <x-textarea name="note" rows="2" required
                                placeholder="What is holding this up?">{{ (int) old('ref') === $todo->id ? old('note') : '' }}</x-textarea>
                    <x-input-error :messages="(int) old('ref') === $todo->id ? $errors->get('note') : []" class="mt-2" />
                    <div class="mt-2">
                        <x-btn size="sm" variant="danger">Mark blocked</x-btn>
                    </div>
                </form>
            </div>

            <div x-show="editing" x-cloak x-transition.opacity.duration.200ms
                 class="mt-3 pt-3 border-t border-white/10">
                @include('my._todo-form', ['todo' => $todo, 'day' => $day])

                <form method="POST" action="{{ route('my.todos.destroy', $todo) }}" class="mt-3"
                      onsubmit="return confirm('Delete this to-do and its whole history?')">
                    @csrf
                    @method('DELETE')
                    <x-btn size="sm" variant="ghost" icon="trash">Delete</x-btn>
                </form>
            </div>
        @endif

        @if ($reviewable)
            <div x-show="sending" x-cloak x-transition.opacity.duration.200ms class="mt-3">
                <form method="POST" action="{{ route('todos.review', $todo) }}">
                    @csrf
                    <input type="hidden" name="review_state" value="{{ Todo::REVIEW_REJECTED }}">
                    <input type="hidden" name="review_ref" value="{{ $todo->id }}">
                    <x-textarea name="review_note" rows="2" required
                                placeholder="What needs doing before this is right?">{{ (int) old('review_ref') === $todo->id ? old('review_note') : '' }}</x-textarea>
                    <x-input-error :messages="(int) old('review_ref') === $todo->id ? $errors->get('review_note') : []" class="mt-2" />
                    <p class="mt-1 text-[11px] text-brand-100/60">This goes straight back on their board as started.</p>
                    <div class="mt-2">
                        <x-btn size="sm" variant="danger">Send back</x-btn>
                    </div>
                </form>
            </div>
        @endif

        {{-- The whole point of the feature: every change, with the time, grouped
             by the day it happened on. --}}
        <div x-show="open" x-cloak x-transition.opacity.duration.200ms>
            <div class="mt-3 pt-3 border-t border-white/10 space-y-3">
                @forelse ($todo->updates->groupBy(fn ($update) => $update->created_at->toDateString()) as $date => $updates)
                    @php $on = \Illuminate\Support\Carbon::parse($date); @endphp

                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-wider text-brand-100/50">
                            {{ $on->format('D j M Y') }}@if ($on->isToday()) · Today @endif
                        </p>

                        <ul class="mt-1.5 space-y-1.5">
                            @foreach ($updates as $update)
                                <li class="flex items-start gap-2 text-xs text-brand-100/70">
                                    <span class="shrink-0 tabular-nums text-brand-100/50 w-16">{{ $update->timeLabel() }}</span>
                                    <span class="min-w-0">
                                        @if ($update->action === TodoUpdate::MOVED)
                                            Moved from {{ $update->from_on?->format('D j M') }}
                                            to {{ $update->to_on?->format('D j M') }}
                                        @elseif ($update->action === TodoUpdate::REVIEWED)
                                            <span class="font-semibold">{{ $update->to_status ? 'Sent back' : 'Checked off' }}</span>
                                        @elseif ($update->to_status)
                                            {{ Todo::STATUSES[$update->to_status] ?? $update->to_status }}
                                        @else
                                            {{ $update->actionLabel() }}
                                        @endif

                                        @if ($update->note)
                                            <span class="text-brand-100/60">— {{ $update->note }}</span>
                                        @endif

                                        @if ($update->user && $update->user_id !== $todo->user_id)
                                            <span class="text-brand-100/50">by {{ $update->user->name }}</span>
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @empty
                    <p class="text-xs text-brand-100/50">Nothing recorded yet.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
