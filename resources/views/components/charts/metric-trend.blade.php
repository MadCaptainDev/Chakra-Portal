@props([
    'days' => [],
    'title' => 'Trend',
    'empty' => 'No data yet for this range.',
])

@php
    /*
     * The numeric sibling of charts.daily-bars, which is minutes-shaped and
     * formats through TimesheetEntry::formatMinutes -- wrong unit for reach
     * and follower counts. Same absolute-fill bar technique, generic values.
     *
     * $days: list<{date: string, value: int}>
     */
    $dayCollection = collect($days);
    $hasData = $dayCollection->sum('value') > 0;
    $max = max(1, (int) ($dayCollection->max('value') ?: 0));
    $peak = (int) ($dayCollection->max('value') ?: 0);
    $count = $dayCollection->count();
    $midLabel = $count > 2 ? (int) ceil($count / 2) : null;
@endphp

<div {{ $attributes->merge(['class' => 'bg-white/5 shadow-sm rounded-lg border border-white/10 p-4 sm:p-5']) }}>
    <h3 class="text-sm font-semibold text-white mb-3">{{ $title }}</h3>

    @if (! $hasData)
        <p class="text-sm text-brand-100/60 py-8 text-center">{{ $empty }}</p>
    @else
        <div class="relative h-32 sm:h-40" role="img" aria-label="{{ $title }}">
            <div class="absolute inset-0 flex items-end gap-px sm:gap-0.5">
                @foreach ($days as $day)
                    @php
                        $value = (int) ($day['value'] ?? 0);
                        $pct = $value > 0 ? max(4, (int) round(($value / $max) * 100)) : 0;
                        $isPeak = $value > 0 && $value === $peak;
                        $tip = \Illuminate\Support\Carbon::parse($day['date'])->format('D j M').': '.number_format($value);
                    @endphp
                    <div class="flex-1 min-w-[4px] sm:min-w-[6px] h-full flex flex-col justify-end" title="{{ $tip }}">
                        @if ($value > 0)
                            <div class="w-full rounded-t transition-colors {{ $isPeak ? 'bg-brand-300 hover:bg-brand-200' : 'bg-brand-400 hover:bg-brand-300' }}"
                                 style="height: {{ $pct }}%"></div>
                        @else
                            <div class="w-full h-px bg-white/15"></div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
        <div class="flex justify-between mt-2 text-[10px] sm:text-xs text-brand-100/50 tabular-nums">
            <span>{{ \Illuminate\Support\Carbon::parse($days[0]['date'])->format('j M') }}</span>
            @if ($midLabel)
                <span>{{ \Illuminate\Support\Carbon::parse($days[$midLabel - 1]['date'])->format('j M') }}</span>
            @endif
            <span>{{ \Illuminate\Support\Carbon::parse(end($days)['date'])->format('j M') }}</span>
        </div>
    @endif
</div>
