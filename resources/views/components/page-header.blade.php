@props(['title', 'subtitle' => null, 'eyebrow' => null, 'dark' => true])

{{-- $dark survives only as a no-op prop: the call sites that pass it were
     written when the Dashboard was the one dark screen, and there is no light
     signed-in screen left for it to switch to. --}}
<div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
    <div class="min-w-0">
        @if ($eyebrow)
            <p class="text-[11px] font-semibold uppercase tracking-wider text-brand-300 mb-1">{{ $eyebrow }}</p>
        @endif
        <h2 class="font-bold text-xl sm:text-2xl text-white leading-tight tracking-tight">{{ $title }}</h2>
        @if ($subtitle)
            <p class="mt-1 text-sm text-brand-100/70 leading-snug">{{ $subtitle }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex flex-wrap items-center gap-2 sm:shrink-0">
            {{ $actions }}
        </div>
    @endisset
</div>
