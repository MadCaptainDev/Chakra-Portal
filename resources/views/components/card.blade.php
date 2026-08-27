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

    // The default is now the glass panel the Dashboard established -- a
    // translucent white film over the brand-900 ground with a hairline of
    // white for an edge. It used to be `bg-white/5 ring-white/10` with 'dark'
    // as the opt-in; that flipped when the whole signed-in product moved onto
    // the dark plane. 'light' is kept for anything that must sit on paper or
    // on a white ground, and 'dark' still resolves for the call sites that
    // already ask for it by name.
    //
    // No shadow on the dark tones: a shadow reads as "lifted off a light
    // surface" and does nothing useful over navy. The ring carries the edge.
    $tones = [
        'default' => 'bg-white/5 ring-1 ring-white/10',
        'dark' => 'bg-white/5 ring-1 ring-white/10',
        'brand' => 'bg-brand-400/10 ring-1 ring-brand-400/25',
        'muted' => 'bg-brand-900/40 ring-1 ring-white/10',
        'light' => 'bg-white text-gray-900 ring-1 ring-gray-900/5',
    ];

    $isLight = $tone === 'light';

    $classes = ($tones[$tone] ?? $tones['default']).' rounded-xl';
    $classes .= $isLight ? ' shadow-sm' : '';

    if ($interactive) {
        $classes .= $isLight
            ? ' transition duration-150 hover:shadow-md hover:ring-gray-900/10'
            : ' transition duration-150 hover:bg-white/[0.08] hover:ring-white/20';
    }
@endphp

<div {{ $attributes->merge(['class' => trim($classes.' '.$pad)]) }}>
    {{ $slot }}
</div>
