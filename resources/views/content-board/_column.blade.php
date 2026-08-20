@php
    /*
     * Shared column shell for both boards. $card is the dotted view name of
     * the card partial to render for each item -- 'content-board._reel-card'
     * or 'content-board._shoot-card' -- so this file stays generic to both.
     *
     * `color` never gets interpolated into a Tailwind class string
     * (bg-{{ $color }}-500 would never be emitted by Tailwind's scanner,
     * since it only sees literal class names) -- mapped through a fixed
     * table instead.
     */
    $dotClasses = [
        'brown' => 'bg-amber-800',
        'yellow' => 'bg-yellow-400',
        'orange' => 'bg-orange-400',
        'purple' => 'bg-purple-400',
        'pink' => 'bg-pink-400',
        'gray' => 'bg-gray-400',
        'green' => 'bg-green-500',
        'red' => 'bg-red-500',
    ];
    $dot = $dotClasses[$column['color']] ?? 'bg-gray-400';
@endphp

<div class="w-72 shrink-0">
    <div class="flex items-center gap-2 mb-2.5 px-1">
        <span class="w-2 h-2 rounded-full shrink-0 {{ $dot }}"></span>
        <p class="text-xs font-semibold uppercase tracking-wider text-gray-600 truncate">{{ $column['label'] }}</p>
        <span class="ml-auto shrink-0 text-[11px] font-bold tabular-nums text-gray-400">{{ $column['items']->count() }}</span>
    </div>

    <div class="space-y-2.5">
        @forelse ($column['items'] as $item)
            @include($card, ['item' => $item])
        @empty
            <p class="text-xs text-gray-300 text-center py-4">—</p>
        @endforelse
    </div>
</div>
