@php
    /**
     * One duty, however many days it is behind. The backlog is a line of
     * text, not four identical cards.
     *
     * Used for a task with a single subtask -- a plain, non-account-scoped
     * routine, the overwhelming majority. A task with several subtasks
     * (fifteen venture accounts under one "Checking Venture Messages"
     * routine) renders as a checklist instead -- see _routine-task /
     * _routine-checklist.
     */
    $occurrence = $duty['oldest'];
    $fields = $occurrence->applicableFields();
    $key = $duty['key'];
    $inputId = 'duty-'.md5($key);
@endphp

<x-card padding="sm" class="{{ $duty['is_overdue'] ? 'ring-1 ring-amber-400/30' : '' }}">
    <label for="{{ $inputId }}" class="flex items-start gap-3 cursor-pointer">
        <input type="checkbox" id="{{ $inputId }}" name="duties[]" value="{{ $key }}"
               x-model="ticked"
               class="mt-0.5 h-5 w-5 rounded bg-white/10 border-white/25 text-brand-400 focus:ring-brand-400 shrink-0">

        <span class="min-w-0 flex-1">
            <span class="block font-semibold text-white">{{ $duty['routine']?->title }}</span>

            <span class="block text-xs text-brand-100/60 mt-0.5">
                @if ($duty['is_overdue'])
                    <span class="font-semibold text-amber-200">
                        {{ $duty['days_late'] }} {{ Str::plural('day', $duty['days_late']) }} late
                    </span>
                    &middot; since {{ $occurrence->due_on->format('D, j M') }}
                @else
                    Due today
                @endif

                @if ($duty['outstanding'] > 1)
                    &middot; {{ $duty['outstanding'] }} outstanding
                @endif

                @if ($duty['subject_label'])
                    &middot; {{ $duty['subject_label'] }}
                @endif

                @if ($duty['checkpoint'])
                    &middot; {{ $duty['checkpoint']->name }}
                @endif

                &middot; {{ $duty['routine']?->isShared() ? 'shared' : 'yours' }}
            </span>
        </span>
    </label>

    @if ($fields->isNotEmpty())
        @include('my._routine-fields', ['fields' => $fields, 'key' => $key, 'outstanding' => $duty['outstanding']])
    @endif
</x-card>
