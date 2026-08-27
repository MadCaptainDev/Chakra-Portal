@props(['graphs', 'range' => \App\Support\ContributionGraph::DEFAULT_RANGE])

@php
    use App\Models\TimesheetEntry;
    use App\Support\ContributionGraph;

    /*
     * The studio's work, one square per day, over a window you can change.
     *
     * All windows are rendered and Alpine shows one. They come from a single
     * query, so switching instantly beats a page reload that would rebuild the
     * whole dashboard to redraw one card.
     *
     * Default is This month — the year view was too coarse for day-to-day
     * tracking. Hover or click a square for who logged what that day.
     */
    $levels = [
        0 => 'bg-white/[0.06]',
        1 => 'bg-brand-400/25',
        2 => 'bg-brand-400/45',
        3 => 'bg-brand-400/70',
        4 => 'bg-brand-300',
    ];

    // Sunday-first. Only the alternate rows are labelled on the tight windows,
    // where a label per row would be taller than the square it names.
    $weekdays = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
@endphp

<div class="rounded-xl bg-white/5 ring-1 ring-white/10 p-5 sm:p-6"
     x-data="{
        range: @js(ContributionGraph::isKnownRange($range) ? $range : ContributionGraph::DEFAULT_RANGE),
        tip: null,
        pinned: false,
        show(day) { if (!this.pinned) this.tip = day; },
        hide() { if (!this.pinned) this.tip = null; },
        toggle(day) {
            if (this.pinned && this.tip && this.tip.key === day.key) {
                this.pinned = false;
                this.tip = null;
                return;
            }
            this.pinned = true;
            this.tip = day;
        },
        clearPin() { this.pinned = false; this.tip = null; }
     }">

    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-100/70">Work logged</p>

        <label class="sr-only" for="work-heatmap-range">Change the period shown</label>
        <select id="work-heatmap-range" x-model="range" @change="clearPin()"
                class="min-h-[36px] py-1.5 pl-3 pr-8 rounded-md bg-white/10 border-white/15 text-white text-xs
                       focus:border-brand-400 focus:ring-brand-400">
            @foreach (ContributionGraph::RANGES as $value => $label)
                <option value="{{ $value }}" class="text-white">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    @foreach ($graphs as $key => $graph)
        <div x-show="range === @js($key)" x-cloak>
            <div class="mt-1 flex flex-wrap items-baseline gap-x-3 gap-y-0.5">
                <p class="text-lg font-extrabold tabular-nums leading-none">{{ TimesheetEntry::formatMinutes($graph['total']) }}</p>
                <p class="text-xs text-brand-100/60">
                    {{ $graph['caption'] }}
                    @if ($graph['daysWorked'] > 0)
                        &middot; {{ $graph['daysWorked'] }} {{ Str::plural('day', $graph['daysWorked']) }} worked
                    @endif
                </p>
            </div>

            @if ($graph['busiest'])
                <p class="mt-1 text-xs text-brand-100/45">
                    Busiest was {{ $graph['busiest']['date']->format('D d M') }} —
                    {{ TimesheetEntry::formatMinutes($graph['busiest']['minutes']) }}
                </p>
            @else
                <p class="mt-1 text-xs text-brand-100/45">Nothing logged in this period.</p>
            @endif

            <div class="mt-4 -mx-1 px-1 overflow-x-auto">
                <div class="inline-flex gap-2">
                    {{-- Weekday rail. Lines up with the grid because both use
                         the same square height and the same gap, and both open
                         with the same spacer when there is a month header. --}}
                    <div class="shrink-0">
                        @if ($graph['months'])
                            <div class="mb-1.5 h-3"></div>
                        @endif

                        <div class="flex flex-col {{ $graph['gap'] }}">
                            @foreach ($weekdays as $index => $initial)
                                <div class="{{ $graph['cell'] }} flex items-center justify-end text-[9px] leading-none text-brand-100/40">
                                    {{ $graph['unit'] >= 28 || $index % 2 === 1 ? $initial : '' }}
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        @if ($graph['months'])
                            <div class="flex {{ $graph['gap'] }} mb-1.5 h-3">
                                @foreach ($graph['months'] as $month)
                                    <div class="text-[9px] leading-none text-brand-100/45 shrink-0 overflow-hidden"
                                         style="width: {{ $month['span'] * $graph['unit'] }}px">{{ $month['span'] > 1 ? $month['label'] : '' }}</div>
                                @endforeach
                            </div>
                        @endif

                        <div class="flex {{ $graph['gap'] }}">
                            @foreach ($graph['weeks'] as $week)
                                <div class="flex flex-col {{ $graph['gap'] }} shrink-0">
                                    @foreach ($week as $day)
                                        @if ($day === null)
                                            <div class="{{ $graph['cell'] }}"></div>
                                        @else
                                            @php
                                                $payload = [
                                                    'key' => $day['date']->toDateString(),
                                                    'dateLabel' => $day['dateLabel'],
                                                    'hoursLabel' => $day['hoursLabel'],
                                                    'minutes' => $day['minutes'],
                                                    'people' => $day['people'],
                                                ];
                                            @endphp
                                            <button type="button"
                                                    class="{{ $graph['cell'] }} rounded-[2px] {{ $levels[$day['level']] }}
                                                           transition ring-offset-1 ring-offset-brand-900
                                                           hover:ring-2 hover:ring-brand-300/80 focus:outline-none
                                                           focus-visible:ring-2 focus-visible:ring-brand-300
                                                           {{ $day['minutes'] > 0 ? 'cursor-pointer' : 'cursor-default' }}"
                                                    :class="tip && tip.key === @js($payload['key']) ? 'ring-2 ring-brand-300' : ''"
                                                    @mouseenter="show(@js($payload))"
                                                    @mouseleave="hide()"
                                                    @click="toggle(@js($payload))"
                                                    :aria-pressed="pinned && tip && tip.key === @js($payload['key'])"
                                                    aria-label="{{ $day['dateLabel'] }} — {{ $day['hoursLabel'] }}">
                                            </button>
                                        @endif
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endforeach

    {{-- Day detail: hover preview, click to pin. --}}
    <div x-show="tip" x-cloak x-transition.opacity.duration.150ms
         class="mt-4 rounded-lg bg-white/10 ring-1 ring-white/15 p-3 sm:p-4">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-sm font-semibold text-white" x-text="tip?.dateLabel"></p>
                <p class="mt-0.5 text-xs text-brand-100/70 tabular-nums" x-text="tip?.hoursLabel"></p>
            </div>
            <button type="button" x-show="pinned" @click="clearPin()"
                    class="shrink-0 text-[11px] font-semibold uppercase tracking-wider text-brand-200/70 hover:text-white min-h-[32px]">
                Close
            </button>
        </div>

        <template x-if="tip && tip.people && tip.people.length">
            <ul class="mt-3 space-y-1.5 border-t border-white/10 pt-3">
                <template x-for="person in tip.people" :key="person.name + person.minutes">
                    <li class="flex items-center justify-between gap-3 text-xs">
                        <span class="text-brand-50/90 truncate" x-text="person.name"></span>
                        <span class="tabular-nums text-brand-100/70 shrink-0" x-text="person.hoursLabel"></span>
                    </li>
                </template>
            </ul>
        </template>

        <p class="mt-2 text-[11px] text-brand-100/40" x-show="tip && tip.minutes === 0">No timesheet entries this day.</p>
        <p class="mt-2 text-[10px] text-brand-100/35" x-show="!pinned">Click a day to keep this open</p>
    </div>

    <div class="mt-3 flex items-center justify-end gap-1.5">
        <span class="text-[10px] text-brand-100/45">Quiet</span>
        @foreach ($levels as $class)
            <div class="w-2.5 h-2.5 rounded-[2px] {{ $class }}"></div>
        @endforeach
        <span class="text-[10px] text-brand-100/45">Flat out</span>
    </div>
</div>
