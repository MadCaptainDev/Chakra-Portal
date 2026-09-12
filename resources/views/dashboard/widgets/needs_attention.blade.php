{{-- ——— Needs attention. Every domain feeds this one list. Collapsed by
     default: the count in the header is the part that gets read at a
     glance, and a dozen open rows pushed the rest of the dashboard
     below the fold on every single load. One tap opens it. ——— --}}
<section x-data="{ open: false }">
    <details @toggle="open = $event.target.open">
        {{-- items-center, not the items-baseline the other widget headers use:
             this is the only one with a fixed-height pill in the row, and a
             22px pill has no text baseline to share with the note opposite
             it. --}}
        <summary class="list-none cursor-pointer flex items-center justify-between gap-4 mb-4">
            <span class="flex items-center gap-2">
                <x-icon name="chevron-right" class="w-4 h-4 text-brand-100/50 transition-transform shrink-0" x-bind:class="{ 'rotate-90': open }" />
                <x-section-label dark>Needs attention</x-section-label>
                @if (count($actionItems) > 0)
                    @php
                        // Collapsed, this pill is the whole signal -- so it carries
                        // the worst tone in the list rather than a neutral count.
                        $worst = collect($actionItems)->pluck('tone')->contains('red') ? 'red'
                            : (collect($actionItems)->pluck('tone')->contains('amber') ? 'amber' : 'brand');
                        $pill = match ($worst) {
                            'red' => 'bg-red-400/15 text-red-200 ring-red-400/40',
                            'amber' => 'bg-amber-400/15 text-amber-200 ring-amber-400/40',
                            default => 'bg-brand-400/15 text-brand-200 ring-brand-400/40',
                        };
                    @endphp
                    <span class="inline-flex items-center justify-center min-w-[22px] h-[22px] px-2 rounded-full ring-1 text-[11px] font-bold tabular-nums {{ $pill }}">
                        {{ count($actionItems) }}
                    </span>
                @endif
            </span>
            <p class="text-xs text-brand-100/60 shrink-0" x-show="open" x-cloak>Worst first</p>
            <p class="text-xs text-brand-100/60 shrink-0" x-show="! open">Tap to open</p>
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
