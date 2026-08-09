@props([
    'label',
    'prevUrl',
    'nextUrl',
    'subtitle' => null,
    'todayUrl' => null,
    'todayLabel' => 'Back to this month',
])

{{-- One month navigator: prev / label / next, sized for a thumb. --}}
<div {{ $attributes->merge(['class' => 'flex items-center justify-between gap-2']) }}>
    <a href="{{ $prevUrl }}" rel="prev" aria-label="Previous month"
       class="inline-flex items-center justify-center min-h-[44px] min-w-[44px] px-3 rounded-md bg-white border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50">
        <span aria-hidden="true">&larr;</span>
        <span class="hidden sm:inline ml-1">Prev</span>
    </a>

    <div class="text-center min-w-0">
        <p class="font-semibold text-gray-900 truncate">{{ $label }}</p>
        @if ($subtitle)
            <p class="text-xs text-gray-500 truncate">{{ $subtitle }}</p>
        @endif
        @if ($todayUrl)
            <a href="{{ $todayUrl }}" class="text-xs font-semibold text-brand-500 hover:text-brand-600">{{ $todayLabel }}</a>
        @endif
    </div>

    <a href="{{ $nextUrl }}" rel="next" aria-label="Next month"
       class="inline-flex items-center justify-center min-h-[44px] min-w-[44px] px-3 rounded-md bg-white border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50">
        <span class="hidden sm:inline mr-1">Next</span>
        <span aria-hidden="true">&rarr;</span>
    </a>
</div>
