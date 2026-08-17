@php
    use App\Models\TimesheetDay;
    use App\Models\TimesheetEntry;

    /* One person's day: everything they logged, and one decision covering it. */
    $decision = $day['decision'];
@endphp

<div @class([
        'rounded-xl p-4 ring-1',
        'bg-amber-400/10 ring-amber-400/40' => ! $decision,
        'bg-white/5 ring-white/10' => $decision && $decision->isApproved(),
        'bg-red-400/10 ring-red-400/40' => $decision && $decision->isRejected(),
     ])
     x-data="{ rejecting: false }">

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="flex items-center gap-3 min-w-0">
            <x-avatar :name="$day['member']?->name ?? '?'" :src="$day['member']?->avatarUrl()" size="sm" class="shrink-0" />
            <div class="min-w-0">
                <p class="font-semibold truncate">{{ $day['member']?->name }}</p>
                <p class="text-xs text-brand-100/70">
                    {{ $day['date']->format('l d M') }} &middot; {{ TimesheetEntry::formatMinutes($day['minutes']) }}
                    &middot; {{ $day['entries']->count() }} {{ Str::plural('entry', $day['entries']->count()) }}
                </p>
            </div>
        </div>

        @if ($decision)
            <div class="shrink-0 text-right">
                <p @class([
                    'text-[10px] font-semibold uppercase tracking-[0.14em]',
                    'text-brand-200' => $decision->isApproved(),
                    'text-red-200' => $decision->isRejected(),
                ])>{{ $decision->stateLabel() }}</p>
                <p class="text-[11px] text-brand-100/50">
                    {{ $decision->reviewer?->name ? Str::before($decision->reviewer->name, ' ').' · ' : '' }}{{ $decision->reviewed_at?->diffForHumans() }}
                </p>
            </div>
        @else
            <div class="shrink-0 flex flex-wrap items-center gap-2">
                <form method="POST" action="{{ route('timesheets.day', $day['member']) }}">
                    @csrf
                    <input type="hidden" name="worked_on" value="{{ $day['date']->toDateString() }}">
                    <input type="hidden" name="review_state" value="{{ TimesheetDay::APPROVED }}">
                    <button type="submit"
                            class="inline-flex items-center min-h-[40px] px-4 rounded-md bg-brand-400 text-brand-900 text-[11px] font-semibold uppercase tracking-wider hover:bg-brand-500 transition">
                        Accept day
                    </button>
                </form>

                <button type="button" @click="rejecting = ! rejecting"
                        class="inline-flex items-center min-h-[40px] px-4 rounded-md border border-red-400/50 text-red-200 text-[11px] font-semibold uppercase tracking-wider hover:bg-red-400/10 transition">
                    Reject
                </button>
            </div>
        @endif
    </div>

    {{-- What they actually logged. Read before deciding, so it is on the card
         rather than a click away. --}}
    <div class="mt-3 divide-y divide-white/10 rounded-lg bg-black/10">
        @foreach ($day['entries'] as $entry)
            <div class="flex items-start justify-between gap-3 px-3 py-2">
                <div class="min-w-0">
                    <p class="text-sm truncate">{{ $entry->task }}</p>
                    <p class="text-[11px] text-brand-100/60 truncate">
                        {{ $entry->taskTypeLabel() }}
                        @if ($entry->venture) &middot; {{ $entry->ventureLabel() }} @endif
                        @if ($entry->started_at)
                            &middot; {{ substr($entry->started_at, 0, 5) }}@if ($entry->ended_at)&ndash;{{ substr($entry->ended_at, 0, 5) }}@endif
                        @endif
                    </p>
                    @if ($entry->notes)
                        <p class="mt-1 text-[11px] text-brand-100/50">{{ $entry->notes }}</p>
                    @endif
                </div>
                <p class="shrink-0 text-xs tabular-nums text-brand-100/80">{{ $entry->durationLabel() }}</p>
            </div>
        @endforeach
    </div>

    @if ($decision?->review_note)
        <p class="mt-3 px-3 py-2 rounded-md bg-black/20 text-xs {{ $decision->isRejected() ? 'text-red-200' : 'text-brand-100/80' }}">
            <span class="font-semibold">Comment:</span> {{ $decision->review_note }}
        </p>
    @endif

    {{-- Rejecting takes a reason. Accepting does not: agreeing with what is
         already written adds nothing by being made to type. --}}
    @unless ($decision)
        <form x-show="rejecting" x-cloak method="POST" action="{{ route('timesheets.day', $day['member']) }}" class="mt-3">
            @csrf
            <input type="hidden" name="worked_on" value="{{ $day['date']->toDateString() }}">
            <input type="hidden" name="review_state" value="{{ TimesheetDay::REJECTED }}">
            <textarea name="review_note" rows="2" required
                      placeholder="What needs changing about this day?"
                      class="block w-full text-sm rounded-md bg-white/5 border-white/15 text-white placeholder:text-brand-100/35 focus:border-brand-400 focus:ring-brand-400"></textarea>
            <div class="mt-2 flex justify-end gap-2">
                <button type="button" @click="rejecting = false" class="min-h-[40px] px-3 text-xs text-brand-100/70">Cancel</button>
                <button type="submit" class="min-h-[40px] px-4 rounded-md bg-red-500 text-white text-[11px] font-semibold uppercase tracking-wider">Reject day</button>
            </div>
        </form>
    @endunless
</div>
