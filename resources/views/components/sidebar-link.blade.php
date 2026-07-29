@props(['active' => false, 'href'])

@php
$classes = ($active ?? false)
    ? 'flex items-center gap-3 px-4 py-3 rounded-md text-sm font-medium bg-brand-800/60 text-white border-l-4 border-brand-400 -ml-1 pl-3'
    : 'flex items-center gap-3 px-4 py-3 rounded-md text-sm font-medium text-brand-100/80 hover:bg-brand-800/40 hover:text-white border-l-4 border-transparent -ml-1 pl-3 transition';
@endphp

<a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
