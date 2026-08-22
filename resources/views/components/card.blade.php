@props([
    'padding' => null,
    'interactive' => false,
    'tone' => 'default',
])

@php
    // The padding decision belongs here, not at every call site. `null` is
    // deliberate back-compat: the ~70 existing callers pass their own p-* and
    // keep working untouched, while anything redesigned names a size instead.
    $pad = [
        'none' => '',
        'sm' => 'p-3 sm:p-4',
        'md' => 'p-4 sm:p-6',
        'lg' => 'p-6 sm:p-8',
    ][$padding] ?? '';

    // A hairline ring reads cleaner than a drop shadow at 420px, where stacked
    // cards otherwise smear into each other.
    //
    // 'dark' matches Dashboard's own local $cardClass exactly (the one dark
    // screen in the app, built before this variant existed) -- a glassy panel
    // over the brand-900 canvas rather than a hairline ring, which would be
    // invisible against that background. No shadow either: shadows read as
    // "lifted off a light surface" and do nothing useful over navy.
    $tones = [
        'default' => 'bg-white ring-1 ring-gray-900/5',
        'brand' => 'bg-brand-50/60 ring-1 ring-brand-200/70',
        'muted' => 'bg-gray-50 ring-1 ring-gray-900/5',
        'dark' => 'bg-white/5 ring-1 ring-white/10',
    ];

    $classes = ($tones[$tone] ?? $tones['default']).' rounded-xl';
    $classes .= $tone === 'dark' ? '' : ' shadow-sm';

    if ($interactive) {
        $classes .= $tone === 'dark'
            ? ' transition duration-150 hover:bg-white/[0.07] hover:ring-white/20'
            : ' transition duration-150 hover:shadow-md hover:ring-gray-900/10';
    }
@endphp

<div {{ $attributes->merge(['class' => trim($classes.' '.$pad)]) }}>
    {{ $slot }}
</div>
