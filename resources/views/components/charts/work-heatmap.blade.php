@props(['graphs', 'range' => \App\Support\ContributionGraph::DEFAULT_RANGE])

@php
    use App\Models\TimesheetEntry;
    use App\Support\ContributionGraph;

    /*
     * The studio's work, one square per day, over a window you can change.
     *
     * All four windows are rendered and Alpine shows one. They come from a
     * single query and the biggest is 371 squares, so switching instantly beats
     * a page reload that would rebuild the whole dashboard to redraw one card.
     *
     * The year is 53 columns and will never fit a 420px phone, so the grid
     * scrolls inside its own container and the page does not.
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
     x-data="{ range: @js(ContributionGraph::isKnownRange($range) ? $range : ContributionGraph::DEFAULT_RANGE) }">

    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-100/70">Work logged</p>

        <label class="sr-only" for="work-heatmap-range">Change the period shown</label>
        <select id="work-heatmap-range" x-model="range"
                class="min-h-[36px] py-1.5 pl-3 pr-8 rounded-md bg-white/10 border-white/15 text-white text-xs
                       focus:border-brand-400 focus:ring-brand-400">
            @foreach (ContributionGraph::RANGES as $value => $label)
                <option value="{{ $value }}" class="text-gray-900">{{ $label }}</option>
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
                                            <div class="{{ $graph['cell'] }} rounded-[2px] {{ $levels[$day['level']] }}"
                                                 title="{{ $day['date']->format('D d M Y') }} — {{ $day['minutes'] > 0 ? TimesheetEntry::formatMinutes($day['minutes']) : 'nothing logged' }}"></div>
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

    <div class="mt-3 flex items-center justify-end gap-1.5">
        <span class="text-[10px] text-brand-100/45">Quiet</span>
        @foreach ($levels as $class)
            <div class="w-2.5 h-2.5 rounded-[2px] {{ $class }}"></div>
        @endforeach
        <span class="text-[10px] text-brand-100/45">Flat out</span>
    </div>
</div>
