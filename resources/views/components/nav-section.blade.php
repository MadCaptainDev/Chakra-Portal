@props(['label'])

{{-- Twelve flat nav rows were hard to scan. Grouping them costs one muted
     label per group and turns the list into four short ones. --}}
<div class="pt-4 first:pt-0">
    <p class="px-3 pb-1.5 text-[10px] font-bold uppercase tracking-[0.12em] text-brand-200/40">
        {{ $label }}
    </p>
    <div class="space-y-0.5">
        {{ $slot }}
    </div>
</div>
