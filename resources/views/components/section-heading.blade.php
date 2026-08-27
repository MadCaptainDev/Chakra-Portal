@props(['title', 'subtitle' => null, 'href' => null, 'linkLabel' => 'View all'])

{{-- The "h3 + right-aligned link" pair repeated on nearly every index and
     dashboard, each with its own spacing. One component, one spacing. --}}
<div class="flex items-end justify-between gap-3 mb-3">
    <div class="min-w-0">
        <h3 class="font-semibold text-white leading-tight">{{ $title }}</h3>
        @if ($subtitle)
            <p class="text-xs text-brand-100/60 mt-0.5 leading-snug">{{ $subtitle }}</p>
        @endif
    </div>

    @if ($href)
        <a href="{{ $href }}"
           class="shrink-0 inline-flex items-center gap-1 text-sm font-semibold text-brand-300 hover:text-brand-200">
            {{ $linkLabel }}
            <x-icon name="chevron-right" class="w-4 h-4" />
        </a>
    @elseif (isset($actions))
        <div class="shrink-0 flex items-center gap-2">{{ $actions }}</div>
    @endif
</div>
