@props([
    'label',
    'collapsible' => false,
    'forceOpen' => false,
])

{{--
    Group label for sidebar rows. Collapsible is opt-in (admin Permission
    groups). Open state lives on the parent <nav> Alpine scope (openGroups /
    isGroupOpen / toggleGroup) so the nav filter is not shadowed. When the
    filter query is non-empty, groups stay open via isGroupOpen().
--}}
@php
    $sectionId = 'nav-section-'.\Illuminate\Support\Str::slug($label);
    $groupKey = \Illuminate\Support\Str::slug($label);
@endphp

@if ($collapsible)
    <div class="pt-4 first:pt-0"
         id="{{ $sectionId }}"
         x-show="typeof sectionHasMatch === 'undefined' || sectionHasMatch('{{ $sectionId }}')">
        <button type="button"
                @click="typeof toggleGroup === 'function' && toggleGroup('{{ $groupKey }}')"
                class="w-full flex items-center justify-between gap-2 px-3 pb-1.5 text-[10px] font-bold uppercase tracking-[0.12em] text-brand-200/40 hover:text-brand-200/70 transition"
                :aria-expanded="typeof isGroupOpen === 'function' ? isGroupOpen('{{ $groupKey }}').toString() : 'true'">
            <span>{{ $label }}</span>
            <svg class="w-3.5 h-3.5 shrink-0 transition-transform duration-150"
                 :class="typeof isGroupOpen === 'function' && isGroupOpen('{{ $groupKey }}') && 'rotate-180'"
                 fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
            </svg>
        </button>
        <div class="space-y-0.5"
             x-show="typeof isGroupOpen === 'undefined' || isGroupOpen('{{ $groupKey }}')"
             @if (! $forceOpen) style="display: none;" @endif>
            {{ $slot }}
        </div>
    </div>
@else
    <div class="pt-4 first:pt-0" id="{{ $sectionId }}"
         x-show="typeof sectionHasMatch === 'undefined' || sectionHasMatch('{{ $sectionId }}')">
        <p class="px-3 pb-1.5 text-[10px] font-bold uppercase tracking-[0.12em] text-brand-200/40">
            {{ $label }}
        </p>
        <div class="space-y-0.5">
            {{ $slot }}
        </div>
    </div>
@endif
