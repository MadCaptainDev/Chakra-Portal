{{-- ——— Needs attention. Every domain feeds this one list. Open by
     default -- this is the one list somebody opens the dashboard
     for -- but shrinkable like everything else, for a day it's
     already been cleared. ——— --}}
<section x-data="{ open: true }">
    <details open @toggle="open = $event.target.open">
        <summary class="list-none cursor-pointer flex items-baseline justify-between gap-4 mb-4">
            <span class="flex items-center gap-2">
                <x-icon name="chevron-right" class="w-4 h-4 text-brand-100/50 transition-transform shrink-0" x-bind:class="{ 'rotate-90': open }" />
                <x-section-label dark>Needs attention</x-section-label>
                @if (count($actionItems) > 0)
                    <span class="text-[10px] font-semibold text-brand-100/60 tabular-nums">({{ count($actionItems) }})</span>
                @endif
            </span>
            <p class="text-xs text-brand-100/60 shrink-0">Worst first</p>
        </summary>

        <div class="space-y-2.5">
            @forelse ($actionItems as $item)
                @php $tone = $tones[$item['tone']] ?? $tones['brand']; @endphp
                <a href="{{ $item['href'] }}"
                   class="group flex flex-wrap items-center gap-4 rounded-xl p-4 sm:px-5 ring-1 transition-colors
                          {{ $tone['bg'] }} {{ $tone['ring'] }} hover:bg-white/[0.09]">
                    <span class="shrink-0 w-2 h-9 rounded-full {{ $tone['bar'] }}"></span>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2.5">
                            <p class="font-semibold">{{ $item['title'] }}</p>
                            <span class="text-[9px] font-semibold uppercase tracking-[0.14em] text-brand-100/60
                                         border border-white/15 rounded-full px-2 py-0.5">
                                {{ $item['domain'] ?? 'Money' }}
                            </span>
                        </div>
                        <p class="mt-1 text-sm text-brand-100/70">{{ $item['detail'] }}</p>
                    </div>

                    <span class="shrink-0 inline-flex items-center gap-1 text-xs font-semibold uppercase tracking-widest text-brand-200 ml-auto sm:ml-0">
                        {{ $item['cta'] }}
                        <x-icon name="chevron-right" class="w-4 h-4 group-hover:translate-x-0.5 transition-transform" />
                    </span>
                </a>
            @empty
                <p class="text-sm text-brand-100/50">Nothing needs you right now.</p>
            @endforelse
        </div>
    </details>
</section>
