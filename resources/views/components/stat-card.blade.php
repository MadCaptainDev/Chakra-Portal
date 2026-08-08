@props([
    'label',
    'value',
    'accent' => 'gray',
    'icon' => null,
    'href' => null,
    'trend' => null,
    'trendLabel' => null,
])

@php
    // One tile for the whole product. Every expense module used to hand-roll
    // this out of x-card, which is why the dashboard and the month pages never
    // quite matched.
    $accents = [
        'brand' => ['value' => 'text-brand-700', 'chip' => 'bg-brand-100 text-brand-700', 'edge' => 'before:bg-brand-400'],
        'green' => ['value' => 'text-green-700', 'chip' => 'bg-green-100 text-green-700', 'edge' => 'before:bg-green-500'],
        'red' => ['value' => 'text-red-700', 'chip' => 'bg-red-100 text-red-700', 'edge' => 'before:bg-red-500'],
        'amber' => ['value' => 'text-amber-700', 'chip' => 'bg-amber-100 text-amber-700', 'edge' => 'before:bg-amber-500'],
        'gray' => ['value' => 'text-gray-900', 'chip' => 'bg-gray-100 text-gray-600', 'edge' => 'before:bg-gray-300'],
    ];

    $a = $accents[$accent] ?? $accents['gray'];

    // The colour bleeds up the left edge rather than tinting the whole tile, so
    // a row of four stays readable instead of turning into a colour swatch.
    $shell = 'group relative overflow-hidden bg-white rounded-xl ring-1 ring-gray-900/5 shadow-sm p-4 sm:p-5 '
        .'before:absolute before:inset-y-0 before:left-0 before:w-1 before:content-[""] '.$a['edge'];

    if ($href) {
        $shell .= ' transition duration-150 hover:shadow-md hover:ring-gray-900/10';
    }

    $trendUp = $trend === 'up';
    $trendDown = $trend === 'down';
@endphp

<div {{ $attributes->merge(['class' => $shell]) }}>
    <div class="flex items-start justify-between gap-3">
        <p class="text-[11px] font-semibold text-gray-500 uppercase tracking-wider leading-tight">{{ $label }}</p>
        @if ($icon)
            <span class="shrink-0 inline-flex items-center justify-center w-8 h-8 rounded-lg {{ $a['chip'] }}">
                <x-icon :name="$icon" class="w-4 h-4" />
            </span>
        @endif
    </div>

    <p class="mt-2 text-xl sm:text-2xl font-bold tabular-nums tracking-tight {{ $a['value'] }}">{{ $value }}</p>

    @if ($trendLabel)
        <p class="mt-1 inline-flex items-center gap-1 text-xs font-semibold
                  {{ $trendUp ? 'text-green-600' : ($trendDown ? 'text-red-600' : 'text-gray-500') }}">
            @if ($trendUp || $trendDown)
                <x-icon :name="$trendUp ? 'trending-up' : 'trending-down'" class="w-3.5 h-3.5" />
            @endif
            {{ $trendLabel }}
        </p>
    @endif

    @if (! $slot->isEmpty())
        <div class="relative z-10 mt-1 text-xs text-gray-500 leading-snug">{{ $slot }}</div>
    @endif

    {{-- Stretched link rather than wrapping the tile in an <a>: keeps the
         markup valid when the slot itself contains a link, and makes the whole
         tile a single target comfortably past 44px. --}}
    @if ($href)
        <a href="{{ $href }}" class="absolute inset-0" aria-label="{{ $label }}"><span class="sr-only">{{ $label }}</span></a>
    @endif
</div>
