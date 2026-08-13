@props(['graph'])

@php
    use App\Models\TimesheetEntry;

    /*
     * A year of the studio's work, one square per day.
     *
     * 53 columns will never fit a 420px phone, so the grid scrolls inside its
     * own container and the page does not. The month header is the same column
     * width as the grid and scrolls with it, which is the only way the labels
     * can stay over the right weeks.
     */
    $levels = [
        0 => 'bg-white/[0.06]',
        1 => 'bg-brand-400/25',
        2 => 'bg-brand-400/45',
        3 => 'bg-brand-400/70',
        4 => 'bg-brand-300',
    ];
@endphp

<div class="rounded-xl bg-white/5 ring-1 ring-white/10 p-5 sm:p-6">
    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-100/70">A year of work</p>
        <p class="text-xs text-brand-100/60">
            {{ TimesheetEntry::formatMinutes($graph['total']) }} across
            {{ $graph['daysWorked'] }} {{ Str::plural('day', $graph['daysWorked']) }}
        </p>
    </div>

    @if ($graph['busiest'])
        <p class="mt-1 text-xs text-brand-100/50">
            Busiest was {{ $graph['busiest']['date']->format('D d M Y') }} —
            {{ TimesheetEntry::formatMinutes($graph['busiest']['minutes']) }}
        </p>
    @endif

    <div class="mt-4 -mx-1 px-1 overflow-x-auto">
        <div class="inline-block min-w-full">
            {{-- Month labels. One per month, spanning however many columns that
                 month started. --}}
            <div class="flex gap-[3px] mb-1.5">
                @foreach ($graph['months'] as $month)
                    <div class="text-[9px] text-brand-100/45 shrink-0 overflow-hidden"
                         style="width: {{ $month['span'] * 13 - 3 }}px">{{ $month['span'] > 1 ? $month['label'] : '' }}</div>
                @endforeach
            </div>

            <div class="flex gap-[3px]">
                @foreach ($graph['weeks'] as $week)
                    <div class="flex flex-col gap-[3px] shrink-0">
                        @foreach ($week as $day)
                            @if ($day === null)
                                <div class="w-2.5 h-2.5"></div>
                            @else
                                <div class="w-2.5 h-2.5 rounded-[2px] {{ $levels[$day['level']] }}"
                                     title="{{ $day['date']->format('D d M Y') }} — {{ $day['minutes'] > 0 ? TimesheetEntry::formatMinutes($day['minutes']) : 'nothing logged' }}"></div>
                            @endif
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="mt-3 flex items-center justify-end gap-1.5">
        <span class="text-[10px] text-brand-100/45">Quiet</span>
        @foreach ($levels as $class)
            <div class="w-2.5 h-2.5 rounded-[2px] {{ $class }}"></div>
        @endforeach
        <span class="text-[10px] text-brand-100/45">Flat out</span>
    </div>
</div>
