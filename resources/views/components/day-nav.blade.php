@props([
    'route',
    'day',
    'subtitle' => null,
    'params' => [],
    // The query string key the day travels under. 'date' everywhere this
    // already existed (todos); Routine Check calls its own 'day' -- rather
    // than rename that route's param to match, this just asks which name to
    // use, so both callers share one component instead of Routine Check
    // hand-rolling its own copy of the same prev/next/jump-to-date markup.
    'param' => 'date',
])

@php
    // The day-grained twin of x-month-nav, down to the same arrow styling, so
    // the to-do board and the timesheet do not feel like two different apps.
    // The extra piece is the date field: a month is three taps away at most, but
    // a day three weeks back is twenty, and typing it is the only sane route.
    $prev = $day->copy()->subDay()->toDateString();
    $next = $day->copy()->addDay()->toDateString();
    $isToday = $day->isToday();

    $link = fn (string $d) => route($route, array_merge($params, [$param => $d]));

    $arrow = 'inline-flex items-center justify-center w-11 h-11 shrink-0 rounded-lg bg-white/10 '
        .'ring-1 ring-white/15 text-brand-100/80 transition '
        .'hover:bg-white/[0.16] hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400';
@endphp

<div {{ $attributes->merge(['class' => 'flex items-center justify-between gap-3']) }}>
    <a href="{{ $link($prev) }}" class="{{ $arrow }}" aria-label="Previous day" rel="prev">
        <x-icon name="chevron-left" class="w-5 h-5" />
    </a>

    <div class="min-w-0 text-center">
        <p class="font-bold text-white leading-tight truncate">
            {{ $day->format('D j M Y') }}@if ($isToday) <span class="font-medium text-brand-300">· Today</span>@endif
        </p>

        @if ($subtitle)
            <p class="text-xs text-brand-100/60 truncate">{{ $subtitle }}</p>
        @endif

        <form method="GET" action="{{ route($route) }}" class="mt-1 flex items-center justify-center gap-2">
            @foreach ($params as $key => $value)
                @if (filled($value))
                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                @endif
            @endforeach

            <input type="date" name="{{ $param }}" value="{{ $day->toDateString() }}"
                   onchange="this.form.submit()"
                   aria-label="Jump to a day"
                   class="bg-white/5 border-white/15 text-white focus:border-brand-400 focus:ring-brand-400 rounded-md text-xs py-1">

            {{-- Works without JavaScript too; the onchange just saves a tap. --}}
            <noscript><button type="submit" class="text-xs font-semibold text-brand-300">Go</button></noscript>
        </form>

        @unless ($isToday)
            <a href="{{ $link(today()->toDateString()) }}"
               class="text-xs font-semibold text-brand-300 hover:text-brand-200">Back to today</a>
        @endunless
    </div>

    <a href="{{ $link($next) }}" class="{{ $arrow }}" aria-label="Next day" rel="next">
        <x-icon name="chevron-right" class="w-5 h-5" />
    </a>
</div>
