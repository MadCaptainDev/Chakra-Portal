@props(['active' => false, 'href', 'icon' => null])

@php
    // The active row reads as a raised pill rather than a border-left tick, so
    // it survives the mobile drawer where there is no rail to tick against.
    $base = 'group relative flex items-center gap-3 px-3 py-2.5 min-h-[44px] rounded-lg '
        .'text-sm font-medium transition duration-150';

    $classes = $active
        ? $base.' bg-brand-400/15 text-white shadow-sm ring-1 ring-brand-400/25'
        : $base.' text-brand-100/70 hover:bg-white/5 hover:text-white';
@endphp

<a href="{{ $href }}" @if ($active) aria-current="page" @endif
   {{ $attributes->merge(['class' => $classes]) }}>
    {{-- Teal spine on the active row: the one high-contrast cue that still
         reads at a glance when the sidebar is scrolled. --}}
    @if ($active)
        <span class="absolute left-0 top-1/2 -translate-y-1/2 h-6 w-1 rounded-r-full bg-brand-400"></span>
    @endif

    @if ($icon)
        <x-icon :name="$icon" class="w-5 h-5 shrink-0 {{ $active ? 'text-brand-300' : 'text-brand-200/60 group-hover:text-brand-200' }}" />
    @endif

    {{ $slot }}
</a>
