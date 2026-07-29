@props(['label', 'value', 'accent' => 'gray'])

@php
    $accents = [
        'brand' => 'text-brand-600',
        'green' => 'text-green-600',
        'red' => 'text-red-600',
        'amber' => 'text-amber-600',
        'gray' => 'text-gray-900',
    ];
@endphp

<div class="bg-white shadow-sm rounded-lg p-5">
    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">{{ $label }}</p>
    <p class="mt-2 text-2xl font-bold {{ $accents[$accent] ?? $accents['gray'] }}">{{ $value }}</p>
    @if (! $slot->isEmpty())
        <div class="mt-1 text-xs text-gray-500">{{ $slot }}</div>
    @endif
</div>
