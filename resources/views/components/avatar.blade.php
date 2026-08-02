@props(['name' => '', 'size' => 'md'])

@php
    // Deterministic colour so the same person always looks the same everywhere.
    $palette = ['bg-rose-500', 'bg-amber-500', 'bg-emerald-500', 'bg-sky-500', 'bg-violet-500', 'bg-teal-500'];
    $colour = $palette[crc32(mb_strtolower(trim($name))) % count($palette)];

    $parts = preg_split('/[\s\-\.]+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $initials = '';
    if ($parts !== []) {
        $initials = mb_strtoupper(mb_substr($parts[0], 0, 1));
        if (count($parts) > 1) {
            $initials .= mb_strtoupper(mb_substr(end($parts), 0, 1));
        }
    }

    $sizes = [
        'sm' => 'w-7 h-7 text-[10px]',
        'md' => 'w-10 h-10 text-sm',
        'lg' => 'w-16 h-16 text-xl',
    ];
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center justify-center rounded-full text-white font-bold shrink-0 {$colour} {$sizes[$size]}"]) }}
      title="{{ $name }}">
    {{ $initials ?: '?' }}
</span>
