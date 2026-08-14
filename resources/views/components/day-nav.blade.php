@props([
    'route',
    'day',
    'subtitle' => null,
    'params' => [],
])

@php
    // The day-grained twin of x-month-nav, down to the same arrow styling, so
    // the to-do board and the timesheet do not feel like two different apps.
    // The extra piece is the date field: a month is three taps away at most, but
    // a day three weeks back is twenty, and typing it is the only sane route.
    $prev = $day->copy()->subDay()->toDateString();
    $next = $day->copy()->addDay()->toDateString();
    $isToday = $day->isToday();

    $link = fn (string $d) => route($route, array_merge($params, ['date' => $d]));

    $arrow = 'inline-flex items-center justify-center w-11 h-11 shrink-0 rounded-lg bg-white '
        .'ring-1 ring-gray-900/10 text-gray-600 shadow-sm transition '
        .'hover:bg-gray-50 hover:text-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400';
@endphp

<div {{ $attributes->merge(['class' => 'flex items-center justify-between gap-3']) }}>
    <a href="{{ $link($prev) }}" class="{{ $arrow }}" aria-label="Previous day" rel="prev">
        <x-icon name="chevron-left" class="w-5 h-5" />
    </a>

    <div class="min-w-0 text-center">
        <p class="font-bold text-gray-900 leading-tight truncate">
            {{ $day->format('D j M Y') }}@if ($isToday) <span class="font-medium text-brand-600">· Today</span>@endif
        </p>

        @if ($subtitle)
            <p class="text-xs text-gray-500 truncate">{{ $subtitle }}</p>
        @endif

        <form method="GET" action="{{ route($route) }}" class="mt-1 flex items-center justify-center gap-2">
            @foreach ($params as $key => $value)
                @if (filled($value))
                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                @endif
            @endforeach

            <input type="date" name="date" value="{{ $day->toDateString() }}"
                   onchange="this.form.submit()"
                   aria-label="Jump to a day"
                   class="border-gray-300 focus:border-brand-400 focus:ring-brand-400 rounded-md shadow-sm text-xs py-1">

            {{-- Works without JavaScript too; the onchange just saves a tap. --}}
            <noscript><button type="submit" class="text-xs font-semibold text-brand-600">Go</button></noscript>
        </form>

        @unless ($isToday)
            <a href="{{ $link(today()->toDateString()) }}"
               class="text-xs font-semibold text-brand-600 hover:text-brand-700">Back to today</a>
        @endunless
    </div>

    <a href="{{ $link($next) }}" class="{{ $arrow }}" aria-label="Next day" rel="next">
        <x-icon name="chevron-right" class="w-5 h-5" />
    </a>
</div>
