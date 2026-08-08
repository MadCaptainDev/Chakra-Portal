@props([
    'days' => [],
    'maxMinutes' => 1,
    'title' => 'Busiest days',
    'empty' => 'No production days yet.',
])

@php
    $maxMinutes = max(1, (int) $maxMinutes);
    $rows = collect($days)->take(7);
@endphp

<div {{ $attributes->merge(['class' => 'bg-white shadow-sm rounded-lg p-4 sm:p-5']) }}>
    <h3 class="text-sm font-semibold text-gray-900 mb-3">{{ $title }}</h3>

    @if ($rows->isEmpty() || $rows->sum('minutes') <= 0)
        <p class="text-sm text-gray-500 py-6 text-center">{{ $empty }}</p>
    @else
        <ol class="space-y-2.5">
            @foreach ($rows as $index => $day)
                @php
                    $minutes = (int) ($day['minutes'] ?? 0);
                    $pct = $minutes > 0 ? max(2, (int) round(($minutes / $maxMinutes) * 100)) : 0;
                @endphp
                <li>
                    <div class="flex items-center justify-between gap-2 mb-1">
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="inline-flex items-center justify-center w-5 h-5 rounded bg-brand-100 text-[10px] font-semibold text-brand-800 shrink-0">
                                {{ $index + 1 }}
                            </span>
                            <span class="text-xs sm:text-sm text-gray-800 truncate">{{ $day['label'] }}</span>
                            @if (! empty($day['entries']))
                                <span class="text-[10px] text-gray-400 shrink-0 hidden sm:inline">
                                    {{ $day['entries'] }} {{ Str::plural('entry', $day['entries']) }}
                                </span>
                            @endif
                        </div>
                        <span class="text-xs text-gray-500 shrink-0 tabular-nums">
                            {{ \App\Models\TimesheetEntry::formatMinutes($minutes) }}
                        </span>
                    </div>
                    <div class="h-2 rounded-full bg-brand-100 overflow-hidden">
                        <div class="h-full rounded-full {{ $index === 0 ? 'bg-brand-700' : 'bg-brand-500' }}"
                             style="width: {{ $pct }}%"></div>
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</div>
