@props([
    'statuses' => [],
    'title' => 'By status',
])

@php
    $colors = [
        'completed' => 'bg-green-100 text-green-800',
        'pending' => 'bg-amber-100 text-amber-800',
        'cancelled' => 'bg-gray-100 text-gray-600',
    ];
    $total = collect($statuses)->sum('count');
@endphp

<div {{ $attributes->merge(['class' => 'bg-white shadow-sm rounded-lg p-4 sm:p-5']) }}>
    <h3 class="text-sm font-semibold text-gray-900 mb-3">{{ $title }}</h3>

    @if ($total === 0)
        <p class="text-sm text-gray-500 py-4 text-center">No entries.</p>
    @else
        <div class="flex h-3 rounded-full overflow-hidden bg-gray-100 mb-3">
            @foreach ($statuses as $status)
                @if ($status['count'] > 0)
                    @php $pct = round(($status['count'] / $total) * 100, 1); @endphp
                    <div class="{{ match ($status['key']) {
                        'completed' => 'bg-green-500',
                        'pending' => 'bg-amber-400',
                        default => 'bg-gray-400',
                    } }}" style="width: {{ $pct }}%" title="{{ $status['label'] }}: {{ $status['count'] }}"></div>
                @endif
            @endforeach
        </div>
        <ul class="flex flex-wrap gap-2">
            @foreach ($statuses as $status)
                <li class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium {{ $colors[$status['key']] ?? 'bg-gray-100 text-gray-700' }}">
                    {{ $status['label'] }}
                    <span class="tabular-nums opacity-80">{{ $status['count'] }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
