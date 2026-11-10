{{-- A kit item's picture, or its initial on a tile when it has none yet. --}}
@php
    $size = ($small ?? false) ? 'h-8 w-8 text-xs' : 'h-11 w-11 text-sm';
    $src = $item && method_exists($item, 'imageUrl') ? $item->imageUrl() : null;
@endphp
@if ($src)
    <img src="{{ $src }}" alt="" loading="lazy" class="{{ $size }} shrink-0 rounded-lg bg-white/5 object-cover">
@else
    <span class="{{ $size }} flex shrink-0 items-center justify-center rounded-lg bg-white/10 font-semibold text-white/50">
        {{ Str::upper(Str::substr($item?->name ?? '?', 0, 1)) }}
    </span>
@endif
