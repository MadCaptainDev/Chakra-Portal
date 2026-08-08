@props([
    'days' => [],
    'maxMinutes' => 1,
    'title' => 'Hours per day',
])

@php
    $maxMinutes = max(1, (int) $maxMinutes);
    $dayCollection = collect($days);
    $hasData = $dayCollection->sum('minutes') > 0;
    $peak = (int) ($dayCollection->max('minutes') ?: 0);
    $count = $dayCollection->count();
    $midLabel = $count > 2 ? (int) ceil($count / 2) : null;
@endphp

<div {{ $attributes->merge(['class' => 'bg-white shadow-sm rounded-lg border border-brand-100/60 p-4 sm:p-5']) }}>
    <h3 class="text-sm font-semibold text-brand-900 mb-3">{{ $title }}</h3>

    @if (! $hasData)
        <p class="text-sm text-gray-500 py-8 text-center">No hours logged yet.</p>
    @else
        {{-- Absolute fill avoids flex align-items:end percentage-height collapse. --}}
        <div class="relative h-32 sm:h-40" role="img" aria-label="{{ $title }}">
            <div class="absolute inset-0 flex items-end gap-px sm:gap-0.5">
                @foreach ($days as $day)
                    @php
                        $minutes = (int) ($day['minutes'] ?? 0);
                        $pct = $minutes > 0
                            ? max(4, (int) round(($minutes / $maxMinutes) * 100))
                            : 0;
                        $isPeak = $minutes > 0 && $minutes === $peak;
                        $tip = \Illuminate\Support\Carbon::parse($day['date'])->format('D j M')
                            .': '.\App\Models\TimesheetEntry::formatMinutes($minutes);
                        if (! empty($day['entries'])) {
                            $tip .= ' · '.$day['entries'].' '.Str::plural('entry', $day['entries']);
                        }
                    @endphp
                    <div class="flex-1 min-w-[4px] sm:min-w-[6px] h-full flex flex-col justify-end"
                         title="{{ $tip }}">
                        @if ($minutes > 0)
                            <div class="w-full rounded-t transition-colors {{ $isPeak ? 'bg-brand-700 hover:bg-brand-800' : 'bg-brand-400 hover:bg-brand-500' }}"
                                 style="height: {{ $pct }}%"></div>
                        @else
                            <div class="w-full h-px bg-brand-100/80"></div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
        <div class="flex justify-between mt-2 text-[10px] sm:text-xs text-gray-400 tabular-nums">
            <span>1</span>
            @if ($midLabel)
                <span>{{ $midLabel }}</span>
            @endif
            <span>{{ $count }}</span>
        </div>
    @endif
</div>
