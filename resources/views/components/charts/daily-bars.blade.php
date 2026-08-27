@props([
    'days' => [],
    'maxMinutes' => 1,
    'title' => 'Hours per day',
])

@php
    /*
     * Month strip of daily minutes. Full calendar window (zeros included) so
     * mid-month activity does not look like a broken chart ending at day 31.
     * CSS tooltips only — hover + focus/tap, no Chart.js.
     *
     * $days: list<{date, label?, weekday?, minutes, entries?}>
     */
    $maxMinutes = max(1, (int) $maxMinutes);
    $dayCollection = collect($days);
    $hasData = $dayCollection->sum('minutes') > 0;
    $peak = (int) ($dayCollection->max('minutes') ?: 0);
    $count = $dayCollection->count();
    $midIndex = $count > 2 ? (int) floor(($count - 1) / 2) : null;
    $midY = (int) round($maxMinutes / 2);

    $fmt = static fn (int $minutes): string => \App\Models\TimesheetEntry::formatMinutes($minutes);
    // Compact axis ticks only — full "44 hrs 39 mins" overflows the narrow
    // y-column (w-10) and stacks over the plot. Peak summary still uses $fmt.
    $axisHours = static function (int $minutes): string {
        if ($minutes <= 0) {
            return '0';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        if ($hours > 0 && $rest === 0) {
            return $hours.'h';
        }

        if ($hours > 0) {
            return $hours.'h '.$rest.'m';
        }

        return $rest.'m';
    };

    $peakDay = $hasData
        ? $dayCollection->first(fn ($d) => (int) ($d['minutes'] ?? 0) === $peak)
        : null;
@endphp

<div {{ $attributes->merge(['class' => 'bg-white/5 shadow-sm rounded-lg border border-white/10 p-3 sm:p-4']) }}>
    <div class="flex items-start justify-between gap-2 mb-2.5">
        <h3 class="text-sm font-semibold text-white leading-tight">{{ $title }}</h3>
        @if ($peakDay)
            <p class="text-[10px] sm:text-[11px] text-brand-100/60 tabular-nums shrink-0 text-right leading-tight">
                Peak {{ \Illuminate\Support\Carbon::parse($peakDay['date'])->format('j M') }}
                <span class="text-brand-200 font-medium">{{ $fmt($peak) }}</span>
            </p>
        @endif
    </div>

    @if (! $hasData)
        <p class="text-sm text-brand-100/60 py-6 text-center">No hours logged yet.</p>
    @else
        <div class="flex gap-1.5 sm:gap-2" role="img" aria-label="{{ $title }}">
            {{-- Y-axis: readable hour scale against the shared max. --}}
            <div class="flex flex-col justify-between shrink-0 w-10 sm:w-12 h-[7.5rem] py-0.5 text-right text-[9px] sm:text-[10px] leading-none text-brand-100/50 tabular-nums select-none whitespace-nowrap"
                 aria-hidden="true">
                <span>{{ $axisHours($maxMinutes) }}</span>
                @if ($midY > 0 && $midY < $maxMinutes)
                    <span>{{ $axisHours($midY) }}</span>
                @else
                    <span></span>
                @endif
                <span>0</span>
            </div>

            <div class="flex-1 min-w-0">
                {{-- Absolute fill avoids flex align-items:end percentage-height collapse. --}}
                <div class="relative h-[7.5rem] rounded-sm bg-white/5 ring-1 ring-inset ring-white/10">
                    <div class="pointer-events-none absolute inset-x-0 top-0 border-t border-white/10" aria-hidden="true"></div>
                    <div class="pointer-events-none absolute inset-x-0 top-1/2 border-t border-dashed border-white/10" aria-hidden="true"></div>
                    <div class="pointer-events-none absolute inset-x-0 bottom-0 border-t border-white/10" aria-hidden="true"></div>

                    <div class="absolute inset-0 flex items-end gap-px px-px pb-px">
                        @foreach ($days as $index => $day)
                            @php
                                $minutes = (int) ($day['minutes'] ?? 0);
                                $pct = $minutes > 0
                                    ? max(6, (int) round(($minutes / $maxMinutes) * 100))
                                    : 0;
                                $isPeak = $minutes > 0 && $minutes === $peak;
                                $date = \Illuminate\Support\Carbon::parse($day['date']);
                                $hoursLabel = $fmt($minutes);
                                $tipParts = [$date->format('D j M'), $hoursLabel];
                                if (! empty($day['entries'])) {
                                    $tipParts[] = $day['entries'].' '.Str::plural('entry', $day['entries']);
                                }
                                $tip = implode(' · ', $tipParts);
                                // Edge tooltips stay on-screen at ~420px.
                                $tipAlign = $index < ($count * 0.2)
                                    ? 'left-0 translate-x-0'
                                    : ($index > ($count * 0.8)
                                        ? 'right-0 translate-x-0'
                                        : 'left-1/2 -translate-x-1/2');
                            @endphp
                            <button type="button"
                                    class="group relative flex-1 min-w-[3px] sm:min-w-[5px] h-full flex flex-col justify-end
                                           focus:outline-none focus-visible:z-20"
                                    title="{{ $tip }}"
                                    aria-label="{{ $tip }}">
                                @if ($minutes > 0)
                                    <span class="w-full rounded-t transition-colors
                                                 {{ $isPeak ? 'bg-brand-300 group-hover:bg-brand-200 group-focus-visible:bg-brand-200' : 'bg-brand-400 group-hover:bg-brand-300 group-focus-visible:bg-brand-300' }}"
                                          style="height: {{ $pct }}%"></span>
                                @else
                                    {{-- Visible zero stubs keep the month continuum readable. --}}
                                    <span class="w-full h-0.5 rounded-sm bg-brand-400/60 group-hover:bg-brand-300 group-focus-visible:bg-brand-300"></span>
                                @endif

                                <span class="pointer-events-none absolute bottom-full z-30 mb-1.5 whitespace-nowrap
                                             rounded-md bg-brand-900 px-2 py-1 text-[10px] font-medium text-white shadow-md
                                             opacity-0 invisible scale-95
                                             group-hover:opacity-100 group-hover:visible group-hover:scale-100
                                             group-focus:opacity-100 group-focus:visible group-focus:scale-100
                                             group-focus-visible:opacity-100 group-focus-visible:visible group-focus-visible:scale-100
                                             transition duration-100 {{ $tipAlign }}">
                                    {{ $tip }}
                                </span>
                            </button>
                        @endforeach
                    </div>
                </div>

                <div class="flex justify-between mt-1.5 text-[9px] sm:text-[10px] text-brand-100/50 tabular-nums leading-none">
                    <span>{{ \Illuminate\Support\Carbon::parse($days[0]['date'])->format('j M') }}</span>
                    @if ($midIndex !== null)
                        <span>{{ \Illuminate\Support\Carbon::parse($days[$midIndex]['date'])->format('j M') }}</span>
                    @endif
                    <span>{{ \Illuminate\Support\Carbon::parse($days[$count - 1]['date'])->format('j M') }}</span>
                </div>
            </div>
        </div>
    @endif
</div>
