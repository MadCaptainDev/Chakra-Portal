@php
    /**
     * One row from RoutineDutyList::nest() on the admin board. A single
     * subtask is a plain duty (unchanged); several render as one routine
     * with its accounts collapsed into a checklist underneath, the same
     * shape My Routines uses.
     */
@endphp

@if ($task['subtasks']->count() === 1)
    @include('routines._checking-row', ['duty' => $task['subtasks']->first(), 'day' => $day, 'nested' => false])
@else
    <div class="py-2 first:pt-0 last:pb-0" x-data="{ open: {{ $task['is_overdue'] ? 'true' : 'false' }} }">
        <button type="button" @click="open = ! open" class="w-full flex items-center justify-between gap-3 text-left">
            <span class="min-w-0">
                <span class="block text-sm text-white">{{ $task['routine']?->title }}</span>
                <span class="block text-xs text-brand-100/60 mt-0.5">
                    @if ($task['late_count'] > 0)
                        <span class="font-semibold text-red-300">{{ $task['late_count'] }} of {{ $task['total'] }} late</span>
                    @else
                        {{ $task['total'] }} {{ Str::plural('account', $task['total']) }}
                    @endif
                    @if ($task['checkpoint'])
                        &middot; {{ $task['checkpoint']->name }}
                    @endif
                </span>
            </span>
            <x-icon name="chevron-right" class="w-4 h-4 shrink-0 text-brand-100/50 transition-transform duration-150"
                    x-bind:class="open && 'rotate-90'" />
        </button>

        <div x-show="open" x-cloak class="mt-2 pl-3 space-y-1 divide-y divide-white/5 border-l border-white/10">
            @foreach ($task['subtasks'] as $duty)
                <div class="pl-3 pt-2 first:pt-0">
                    @include('routines._checking-row', ['duty' => $duty, 'day' => $day, 'nested' => true])
                </div>
            @endforeach
        </div>
    </div>
@endif
