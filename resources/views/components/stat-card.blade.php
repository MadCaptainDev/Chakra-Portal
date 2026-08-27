@props([
    'label',
    'value',
    'accent' => 'gray',
    'icon' => null,
    'href' => null,
    'trend' => null,
    'trendLabel' => null,
    // The tile is drawn for the brand-900 ground the whole signed-in app now
    // runs on. The prop survives because ~30 call sites pass it by name from
    // when the Dashboard was the only dark screen; passing false gets the old
    // light tile back, for a white ground that no longer exists in-product.
    'dark' => true,
])

@php
    // One tile for the whole product. Every expense module used to hand-roll
    // this out of x-card, which is why the dashboard and the month pages never
    // quite matched.
    // Values a step lighter than the hue's mid, chips a translucent wash of it
    // rather than a solid fill (a solid bg-*-100 chip reads as a light-mode
    // leftover sitting on navy), and an edge bar bright enough to register
    // against the glass tile rather than the page behind it.
    //
    // There used to be a second, light-plane copy of this map selected by
    // $dark. Nothing selects it any more -- the whole signed-in app is on the
    // dark plane -- so there is one map and the prop is inert.
    $accents = [
        'brand' => ['value' => 'text-brand-200', 'chip' => 'bg-brand-400/15 text-brand-200', 'edge' => 'before:bg-brand-400'],
        'green' => ['value' => 'text-green-300', 'chip' => 'bg-green-400/15 text-green-300', 'edge' => 'before:bg-green-400'],
        'red' => ['value' => 'text-red-300', 'chip' => 'bg-red-400/15 text-red-300', 'edge' => 'before:bg-red-400'],
        'amber' => ['value' => 'text-amber-300', 'chip' => 'bg-amber-400/15 text-amber-300', 'edge' => 'before:bg-amber-400'],
        'gray' => ['value' => 'text-white', 'chip' => 'bg-white/10 text-brand-100/70', 'edge' => 'before:bg-white/30'],
    ];

    $a = $accents[$accent] ?? $accents['gray'];

    // The colour bleeds up the left edge rather than tinting the whole tile, so
    // a row of four stays readable instead of turning into a colour swatch.
    $shell = 'group relative overflow-hidden bg-white/5 rounded-xl ring-1 ring-white/10 p-4 sm:p-5 ';
    $shell .= 'before:absolute before:inset-y-0 before:left-0 before:w-1 before:content-[""] '.$a['edge'];

    if ($href) {
        $shell .= ' transition duration-150 hover:bg-white/[0.08] hover:ring-white/20';
    }

    $trendUp = $trend === 'up';
    $trendDown = $trend === 'down';

    $labelClass = 'text-brand-100/70';
    $slotClass = 'text-brand-100/60';
    $trendNeutral = 'text-brand-100/70';
    $trendUpClass = 'text-green-300';
    $trendDownClass = 'text-red-300';
@endphp

<div {{ $attributes->merge(['class' => $shell]) }}>
    <div class="flex items-start justify-between gap-3">
        <p class="text-[11px] font-semibold {{ $labelClass }} uppercase tracking-wider leading-tight">{{ $label }}</p>
        @if ($icon)
            <span class="shrink-0 inline-flex items-center justify-center w-8 h-8 rounded-lg {{ $a['chip'] }}">
                <x-icon :name="$icon" class="w-4 h-4" />
            </span>
        @endif
    </div>

    <p class="mt-2 text-xl sm:text-2xl font-bold tabular-nums tracking-tight {{ $a['value'] }}">{{ $value }}</p>

    @if ($trendLabel)
        <p class="mt-1 inline-flex items-center gap-1 text-xs font-semibold
                  {{ $trendUp ? $trendUpClass : ($trendDown ? $trendDownClass : $trendNeutral) }}">
            @if ($trendUp || $trendDown)
                <x-icon :name="$trendUp ? 'trending-up' : 'trending-down'" class="w-3.5 h-3.5" />
            @endif
            {{ $trendLabel }}
        </p>
    @endif

    @if (! $slot->isEmpty())
        <div class="relative z-10 mt-1 text-xs {{ $slotClass }} leading-snug">{{ $slot }}</div>
    @endif

    {{-- Stretched link rather than wrapping the tile in an <a>: keeps the
         markup valid when the slot itself contains a link, and makes the whole
         tile a single target comfortably past 44px. --}}
    @if ($href)
        <a href="{{ $href }}" class="absolute inset-0" aria-label="{{ $label }}"><span class="sr-only">{{ $label }}</span></a>
    @endif
</div>
