{{-- Pending day decisions for the signed-in manager/admin. --}}
<x-card class="p-4 sm:p-5 border border-white/10 overflow-hidden">
    <div class="flex items-start justify-between gap-3 mb-3">
        <div class="min-w-0">
            <h2 class="font-semibold text-white">Days to decide</h2>
            <p class="text-xs text-brand-100/60 mt-0.5">
                Accept or send back from here. Open the full timesheet when something needs a closer look.
            </p>
        </div>
        @if ($pendingDays->isNotEmpty())
            <span class="shrink-0 inline-flex items-center min-h-[28px] px-2.5 rounded-md bg-amber-400/15 text-amber-200 text-[11px] font-semibold tabular-nums">
                {{ $pendingDays->count() }}
            </span>
        @endif
    </div>

    @if ($pendingDays->isEmpty())
        <p class="text-sm text-brand-100/60 py-2">You're caught up — no days waiting on a decision.</p>
    @else
        <ul class="divide-y divide-white/10 -mx-4 sm:-mx-5">
            @foreach ($pendingDays as $item)
                @php
                    $day = \Illuminate\Support\Carbon::parse($item['worked_on']);
                    $rowKey = $item['employee']->id.'-'.$item['worked_on'];
                @endphp
                <li class="px-4 sm:px-5 py-3" x-data="{ open: false, rejecting: false }">
                    <div class="flex flex-wrap items-center gap-3">
                        <button type="button"
                                @click="open = ! open"
                                class="shrink-0 inline-flex items-center justify-center w-8 h-8 rounded-md text-brand-100/50 hover:bg-white/10 hover:text-brand-200 transition"
                                :aria-expanded="open.toString()"
                                aria-controls="decide-entries-{{ $rowKey }}">
                            <span class="inline-block transition-transform" :class="open && 'rotate-90'">
                                <x-icon name="chevron-right" class="w-4 h-4" />
                            </span>
                            <span class="sr-only">Show entries</span>
                        </button>

                        <x-avatar :name="$item['employee']->name" :src="$item['employee']->avatarUrl()" size="sm" />

                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-white truncate">{{ $item['employee']->name }}</p>
                            <p class="text-[11px] text-brand-100/60">
                                {{ $day->format('D j M') }}
                                &middot; {{ \App\Models\TimesheetEntry::formatMinutes($item['minutes']) }}
                                &middot; {{ $item['entry_count'] }} {{ Str::plural('entry', $item['entry_count']) }}
                                @if ($item['flagged'])
                                    <span class="text-amber-200 font-semibold">&middot; worth a look</span>
                                @endif
                            </p>
                        </div>

                        <div class="flex items-center gap-2 shrink-0 w-full sm:w-auto justify-end">
                            <form method="POST" action="{{ route('timesheets.day', $item['employee']) }}">
                                @csrf
                                <input type="hidden" name="worked_on" value="{{ $item['worked_on'] }}">
                                <input type="hidden" name="review_state" value="{{ \App\Models\TimesheetDay::APPROVED }}">
                                <button type="submit"
                                        class="inline-flex items-center justify-center min-h-[36px] px-3 rounded-md bg-green-600 text-white text-[11px] font-semibold uppercase tracking-wider hover:bg-green-700 transition">
                                    Accept
                                </button>
                            </form>

                            <button type="button" @click="rejecting = ! rejecting; open = true"
                                    class="inline-flex items-center justify-center min-h-[36px] px-3 rounded-md border border-white/15 text-brand-100/80 text-[11px] font-semibold uppercase tracking-wider hover:bg-white/[0.09] transition">
                                Reject
                            </button>
                        </div>
                    </div>

                    <div id="decide-entries-{{ $rowKey }}" x-show="open" x-cloak class="mt-3 ml-11 space-y-2">
                        @foreach ($item['entries'] as $entry)
                            <div class="rounded-md bg-white/5 ring-1 ring-white/10 px-3 py-2">
                                <p class="text-sm font-medium text-white truncate">{{ $entry['task'] }}</p>
                                <p class="text-xs text-brand-100/60 mt-0.5">
                                    {{ \App\Models\TimesheetEntry::formatMinutes($entry['minutes']) }}
                                    @if ($entry['venture_label'] !== '')
                                        &middot; {{ $entry['venture_label'] }}
                                    @endif
                                </p>
                            </div>
                        @endforeach

                        <a href="{{ route('timesheets.show', [$item['employee'], 'month' => $month->format('Y-m')]) }}"
                           class="inline-flex items-center text-xs font-semibold text-brand-300 hover:text-brand-200">
                            Open full timesheet &rarr;
                        </a>

                        <form x-show="rejecting" method="POST" action="{{ route('timesheets.day', $item['employee']) }}"
                              class="pt-1 space-y-2">
                            @csrf
                            <input type="hidden" name="worked_on" value="{{ $item['worked_on'] }}">
                            <input type="hidden" name="review_state" value="{{ \App\Models\TimesheetDay::REJECTED }}">
                            <label for="reject_note_{{ $rowKey }}" class="sr-only">Why this day is being sent back</label>
                            <textarea id="reject_note_{{ $rowKey }}" name="review_note" rows="2" required
                                      placeholder="What needs changing about this day?"
                                      class="block w-full text-sm rounded-md border-white/15 focus:border-brand-400 focus:ring-brand-400"></textarea>
                            <div class="flex justify-end gap-2">
                                <button type="button" @click="rejecting = false"
                                        class="inline-flex items-center min-h-[36px] px-3 rounded-md text-xs font-semibold text-brand-100/70 hover:bg-white/[0.12]">
                                    Cancel
                                </button>
                                <button type="submit"
                                        class="inline-flex items-center min-h-[36px] px-3 rounded-md bg-red-600 text-white text-[11px] font-semibold uppercase tracking-wider hover:bg-red-700 transition">
                                    Reject day
                                </button>
                            </div>
                        </form>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</x-card>
