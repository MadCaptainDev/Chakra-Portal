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
    $variants = [
        'primary' => 'bg-brand-500 text-white shadow-sm hover:bg-brand-600 focus-visible:outline-brand-600',
        'secondary' => 'bg-white text-gray-700 ring-1 ring-gray-900/10 shadow-sm hover:bg-gray-50 hover:text-gray-900',
        'danger' => 'bg-red-600 text-white shadow-sm hover:bg-red-700 focus-visible:outline-red-700',
        'ghost' => 'text-brand-600 hover:bg-brand-50 hover:text-brand-700',
        'dark' => 'bg-brand-900 text-white shadow-sm hover:bg-brand-800',
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
        .'focus-visible:ring-offset-2 disabled:opacity-50 disabled:pointer-events-none '
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
