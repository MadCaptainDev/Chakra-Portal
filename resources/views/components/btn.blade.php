@props([
    'href' => null,
    'variant' => 'primary',
    'icon' => null,
    'size' => 'md',
    'type' => 'submit',
])

@php
    // Anchors styled as buttons were pasted at ~30 call sites, each repeating
    // the same uppercase/tracking-widest string. x-primary-button only ever
    // rendered a <button>, so links could not use it.
    //
    // Every variant is drawn for the brand-900 ground the signed-in app now
    // runs on. Primary is the Dashboard's own CTA -- solid brand-400 with
    // navy text, which outreads a white-on-brand-500 button on this plane.
    // Secondary is glass, matching x-card's surface rather than fighting it.
    $variants = [
        'primary' => 'bg-brand-400 text-brand-900 hover:bg-brand-500 focus-visible:outline-brand-300',
        'secondary' => 'bg-white/10 text-white ring-1 ring-white/15 hover:bg-white/[0.16] hover:ring-white/25',
        'danger' => 'bg-red-500 text-white hover:bg-red-400 focus-visible:outline-red-400',
        'ghost' => 'text-brand-200 hover:bg-white/10 hover:text-white',
        // Was "a solid navy button on a light page". On navy that inverts:
        // the emphatic non-brand button is now the white one.
        'dark' => 'bg-white text-brand-900 hover:bg-brand-100',
    ];

    $sizes = [
        // 44px stays the floor on every size -- it is the one rule the whole
        // product already agreed on.
        'sm' => 'min-h-[44px] px-3 py-1.5 text-xs',
        'md' => 'min-h-[44px] px-4 py-2 text-sm',
        'lg' => 'min-h-[48px] px-5 py-2.5 text-sm',
    ];

    $classes = 'inline-flex items-center justify-center gap-1.5 rounded-lg font-semibold '
        .'transition duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 '
        .'focus-visible:ring-offset-2 focus-visible:ring-offset-brand-900 disabled:opacity-50 disabled:pointer-events-none '
        .($sizes[$size] ?? $sizes['md']).' '.($variants[$variant] ?? $variants['primary']);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<x-icon :name="$icon" class="w-4 h-4 shrink-0" />@endif
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<x-icon :name="$icon" class="w-4 h-4 shrink-0" />@endif
        {{ $slot }}
    </button>
@endif
