@php
    /**
     * One subject-level duty on the admin board.
     *
     * $nested distinguishes a plain duty shown on its own (routine title as
     * the headline, subject_label folded into the meta line -- unchanged
     * from before checklists existed) from a row inside a task's checklist
     * (the account is the headline; the routine title is already the
     * checklist's own header, so repeating it here would be noise).
     */
    $occurrence = $duty['oldest'];
    $headline = $nested ? ($duty['subject_label'] ?? $duty['routine']?->title) : $duty['routine']?->title;
@endphp

<div class="py-2 first:pt-0 last:pb-0" x-data="{ skipping: false }">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-sm text-white">{{ $headline }}</p>
            <p class="text-xs text-brand-100/60 mt-0.5">
                @if ($duty['is_overdue'])
                    <span class="font-semibold text-red-300">
                        {{ $duty['days_late'] }} {{ Str::plural('day', $duty['days_late']) }} late
                    </span>
                    &middot;
                @endif
                due {{ $occurrence->due_on->format('D, j M') }}
                @if ($duty['outstanding'] > 1)
                    &middot; {{ $duty['outstanding'] }} outstanding
                @endif
                @if (! $nested && $duty['subject_label'])
                    &middot; {{ $duty['subject_label'] }}
                @endif
                @if ($duty['checkpoint'])
                    &middot; {{ $duty['checkpoint']->name }}
                @endif
            </p>
        </div>

        @can('routines.manage')
            <div class="flex items-center gap-1 shrink-0">
                <form method="POST" action="{{ route('routines.checking.complete', $occurrence) }}">
                    @csrf
                    <input type="hidden" name="day" value="{{ $day->toDateString() }}">
                    <button type="submit"
                            class="min-h-[44px] px-2 text-xs font-semibold text-brand-300 hover:text-brand-200">
                        Done
                    </button>
                </form>

                @if ($duty['outstanding'] > 1)
                    <form method="POST" action="{{ route('routines.checking.complete', $occurrence) }}">
                        @csrf
                        <input type="hidden" name="day" value="{{ $day->toDateString() }}">
                        <input type="hidden" name="all" value="1">
                        <button type="submit"
                                class="min-h-[44px] px-2 text-xs font-semibold text-brand-300 hover:text-brand-200">
                            All {{ $duty['outstanding'] }}
                        </button>
                    </form>
                @endif

                <button type="button" @click="skipping = ! skipping"
                        class="min-h-[44px] px-2 text-xs font-semibold text-brand-100/60 hover:text-brand-100/80">
                    Skip
                </button>
            </div>
        @endcan
    </div>

    @can('routines.manage')
        <form x-show="skipping" x-cloak method="POST"
              action="{{ route('routines.checking.skip', $occurrence) }}"
              class="mt-2 flex items-center gap-2">
            @csrf
            <input type="hidden" name="day" value="{{ $day->toDateString() }}">
            <input type="text" name="note" required placeholder="Why is this being skipped?"
                   class="flex-1 rounded-md border-white/15 text-sm">
            <x-btn type="submit" size="sm" variant="secondary">Skip</x-btn>
        </form>
    @endcan
</div>
