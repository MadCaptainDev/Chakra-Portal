@props(['title', 'subtitle' => null, 'eyebrow' => null])

<div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
    <div class="min-w-0">
        @if ($eyebrow)
            <p class="text-[11px] font-semibold uppercase tracking-wider text-brand-600 mb-1">{{ $eyebrow }}</p>
        @endif
        <h2 class="font-bold text-xl sm:text-2xl text-gray-900 leading-tight tracking-tight">{{ $title }}</h2>
        @if ($subtitle)
            <p class="mt-1 text-sm text-gray-500 leading-snug">{{ $subtitle }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex flex-wrap items-center gap-2 sm:shrink-0">
            {{ $actions }}
        </div>
    @endisset
</div>
