@php
    /**
     * One task, several accounts to check under it -- the Teams/Planner
     * shape: a card with a checklist, not one card per account. Collapsed
     * by default unless something in it is already late, since a person
     * with a dozen tasks should not have to open every one just to see
     * whether it needs them.
     */
@endphp

<x-card padding="sm" class="{{ $task['is_overdue'] ? 'ring-1 ring-amber-400/30' : '' }}"
        x-data="{ open: {{ $task['is_overdue'] ? 'true' : 'false' }} }">
    <button type="button" @click="open = ! open" class="w-full flex items-start gap-3 text-left">
        <span class="mt-0.5 shrink-0 text-brand-100/60">
            <x-icon name="chevron-right" class="w-4 h-4 transition-transform duration-150"
                    x-bind:class="open && 'rotate-90'" />
        </span>

        <span class="min-w-0 flex-1">
            <span class="block font-semibold text-white">{{ $task['routine']?->title }}</span>

            <span class="block text-xs text-brand-100/60 mt-0.5">
                @if ($task['late_count'] > 0)
                    <span class="font-semibold text-amber-200">
                        {{ $task['late_count'] }} of {{ $task['total'] }} late
                    </span>
                @else
                    {{ $task['total'] }} {{ Str::plural('account', $task['total']) }} to check
                @endif

                @if ($task['checkpoint'])
                    &middot; {{ $task['checkpoint']->name }}
                @endif

                &middot; {{ $task['routine']?->isShared() ? 'shared' : 'yours' }}
            </span>
        </span>
    </button>

    <div x-show="open" x-cloak class="mt-3 pl-7 space-y-2.5 divide-y divide-white/5">
        @foreach ($task['subtasks'] as $duty)
            <div class="pt-2.5 first:pt-0">
                @include('my._routine-subtask', ['duty' => $duty])
            </div>
        @endforeach
    </div>
</x-card>
