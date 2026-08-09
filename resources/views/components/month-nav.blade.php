@props([
    'route',
    'month',
    'suffix' => null,
    'subtitle' => null,
    'params' => [],
])

@php
    // The identical prev / label / next block was pasted into expenses,
    // salaries, bills, emi, other and timesheets -- six copies that had already
    // drifted in wording and spacing.
    $prev = $month->copy()->subMonthNoOverflow()->format('Y-m');
    $next = $month->copy()->addMonthNoOverflow()->format('Y-m');
    $isCurrent = $month->isSameMonth(now());

    $link = fn (string $m) => route($route, array_merge($params, ['month' => $m]));

    $arrow = 'inline-flex items-center justify-center w-11 h-11 shrink-0 rounded-lg bg-white '
        .'ring-1 ring-gray-900/10 text-gray-600 shadow-sm transition '
        .'hover:bg-gray-50 hover:text-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400';
@endphp

<div {{ $attributes->merge(['class' => 'flex items-center justify-between gap-3']) }}>
    <a href="{{ $link($prev) }}" class="{{ $arrow }}" aria-label="Previous month" rel="prev">
        <x-icon name="chevron-left" class="w-5 h-5" />
    </a>

    <div class="min-w-0 text-center">
        <p class="font-bold text-gray-900 leading-tight truncate">
            {{ $month->format('F Y') }}@if ($suffix) <span class="font-medium text-gray-500">{{ $suffix }}</span>@endif
        </p>
        @if ($subtitle)
            <p class="text-xs text-gray-500 truncate">{{ $subtitle }}</p>
        @elseif (! $isCurrent)
            {{-- Three taps back into last quarter, one tap home again. --}}
            <a href="{{ $link(now()->format('Y-m')) }}"
               class="text-xs font-semibold text-brand-600 hover:text-brand-700">Back to this month</a>
        @endif
    </div>

    <a href="{{ $link($next) }}" class="{{ $arrow }}" aria-label="Next month" rel="next">
        <x-icon name="chevron-right" class="w-5 h-5" />
    </a>
</div>
