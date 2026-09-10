@props([
    'month',
    'contentAccounts',
    'contentCards',
    'pinnedAccountIds',
    'hasPinnedAccounts' => false,
    'routeName' => 'dashboard',
])

@if ($contentAccounts->isNotEmpty())
    <section>
        <div class="flex flex-wrap items-baseline justify-between gap-4 mb-4">
            <x-section-label dark>Content pipeline</x-section-label>
            <p class="text-xs text-brand-100/60">{{ $month->format('F Y') }}</p>
        </div>

        {{-- The picker sits above the cards and stays shut until wanted:
             choosing which accounts to watch is a once-a-month decision,
             and an always-open checkbox list would push the numbers people
             actually came for below the fold. --}}
        <x-card tone="dark" class="p-4 sm:p-5 mb-4">
            <details {{ $hasPinnedAccounts ? '' : 'open' }}>
                <summary class="cursor-pointer text-xs font-semibold uppercase tracking-wider text-brand-100/60 select-none hover:text-white">
                    Choose accounts ({{ $contentCards->count() }} shown)
                </summary>

                <form method="POST" action="{{ route('dashboard.widgets.update') }}" class="mt-4">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="redirect_to" value="{{ $routeName }}">

                    @unless ($hasPinnedAccounts)
                        <p class="text-xs text-brand-100/50 mb-3">
                            Showing the first {{ $contentCards->count() }} by default. Tick the ones you want and save to pin your own.
                        </p>
                    @endunless

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2 mb-4">
                        @foreach ($contentAccounts as $account)
                            <label class="flex items-center gap-2.5 rounded-md bg-white/[0.03] ring-1 ring-white/10 px-3 py-2.5 cursor-pointer hover:bg-white/[0.06]">
                                <input type="checkbox" name="accounts[]" value="{{ $account->id }}"
                                       @checked($pinnedAccountIds->contains($account->id))
                                       class="rounded border-white/20 bg-white/5 text-brand-400 focus:ring-brand-400">
                                <span class="min-w-0">
                                    <span class="block text-sm text-white truncate">{{ $account->name }}</span>
                                    <span class="block text-[11px] text-brand-100/40 truncate">{{ $account->client?->name ?: 'No client' }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>

                    <x-primary-button>Save cards</x-primary-button>
                </form>
            </details>
        </x-card>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
            @foreach ($contentCards as $card)
                @php($account = $card['account'])
                <x-card tone="dark" class="p-5 space-y-4">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-white truncate">{{ $account->name }}</p>
                            <p class="text-[11px] text-brand-100/40 truncate">{{ $account->client?->name ?: 'No client' }}</p>
                        </div>
                        <a href="{{ route('content-dashboard.show', [$account, 'month' => $month->format('Y-m')]) }}"
                           class="text-[11px] font-semibold uppercase tracking-widest text-brand-300 hover:text-white shrink-0">
                            Full view →
                        </a>
                    </div>

                    {{-- Reel is the headline: every video is a reel first, and
                         Post/YouTube are supplementary (see ContentAccount::
                         TARGETABLE's own doc block). A blended "Total" across
                         all three used to let an on-track post count hide a
                         reel shortfall -- this card no longer computes one. --}}
                    @php($reel = $card['types'][\App\Models\ContentItem::SOURCE_REEL] ?? null)
                    @php($reelStatus = $reel['reel_status'] ?? null)
                    @if ($reel)
                        <div>
                            <div class="flex items-baseline justify-between gap-2 mb-1.5">
                                <span class="text-sm font-semibold text-white">{{ $reel['label'] }}</span>
                                <span class="flex items-baseline gap-2 shrink-0">
                                    @if ($reel['delta'] !== 0)
                                        <span @class([
                                            'text-xs tabular-nums',
                                            'text-green-400' => $reel['delta'] > 0,
                                            'text-red-300' => $reel['delta'] < 0,
                                        ])>
                                            {{ $reel['delta'] > 0 ? '▲' : '▼' }}{{ abs($reel['delta']) }}
                                        </span>
                                    @endif
                                    <span class="text-xl font-bold tabular-nums text-white">
                                        {{ $reel['actual'] }}@if ($reel['target'])<span class="text-brand-100/40 text-base font-semibold">/{{ $reel['target'] }}</span>@endif
                                    </span>
                                    @if ($reelStatus)
                                        <span @class([
                                            'text-[10px] font-semibold uppercase tracking-wider px-1.5 py-0.5 rounded',
                                            'bg-green-400/15 text-green-300' => $reelStatus['status'] === 'green',
                                            'bg-amber-400/15 text-amber-300' => $reelStatus['status'] === 'orange',
                                            'bg-red-400/15 text-red-300' => $reelStatus['status'] === 'red',
                                        ])>{{ ['green' => 'On track', 'orange' => 'Plan next shoot', 'red' => 'Behind, nothing scheduled'][$reelStatus['status']] }}</span>
                                    @endif
                                </span>
                            </div>

                            @if ($reel['target'])
                                <div class="h-2 rounded-full bg-white/[0.07] overflow-hidden">
                                    <div @class([
                                            'h-full rounded-full',
                                            'bg-green-400' => ($reelStatus['status'] ?? null) === 'green',
                                            'bg-amber-400' => ($reelStatus['status'] ?? null) === 'orange',
                                            'bg-red-400' => ($reelStatus['status'] ?? null) === 'red',
                                            'bg-brand-400' => $reelStatus === null,
                                         ])
                                         style="width: {{ min(100, $reel['pct'] ?? 0) }}%"></div>
                                </div>
                            @endif

                            @if ($reelStatus)
                                <p class="mt-1 text-[10px] text-brand-100/40">
                                    By today, expect <span class="text-brand-100/70 tabular-nums">{{ $reelStatus['expected'] }}</span> posted ·
                                    Upcoming <span class="text-brand-100/70 tabular-nums">{{ $reelStatus['upcoming'] }}</span>
                                    @if ($reel['next_shoot_date'])
                                        · Next shoot <span class="text-brand-100/70">{{ $reel['next_shoot_date']->format('j M') }}</span>
                                    @endif
                                </p>
                            @elseif (($reel['upcoming'] ?? 0) > 0)
                                <p class="mt-1 text-[10px] text-brand-100/40">
                                    Upcoming <span class="text-brand-100/70 tabular-nums">{{ $reel['upcoming'] }}</span>
                                    @if ($reel['next_shoot_date'])
                                        · Next shoot <span class="text-brand-100/70">{{ $reel['next_shoot_date']->format('j M') }}</span>
                                    @endif
                                </p>
                            @endif
                        </div>
                    @endif

                    {{-- Supplementary: shown for visibility, never summed
                         with Reel into one combined figure. --}}
                    <div class="space-y-2 pt-1 border-t border-white/5">
                        @foreach ($card['types'] as $source => $type)
                            @continue($source === \App\Models\ContentItem::SOURCE_REEL)
                            <div class="flex items-baseline justify-between gap-2">
                                <span class="text-xs text-brand-100/50">{{ $type['label'] }}</span>
                                <span class="text-xs tabular-nums text-brand-100/70">
                                    {{ $type['actual'] }}@if ($type['target'])<span class="text-brand-100/30">/{{ $type['target'] }}</span>@endif
                                </span>
                            </div>
                        @endforeach

                        @if ($card['stories'] > 0)
                            <div class="flex items-baseline justify-between gap-2">
                                <span class="text-xs text-brand-100/50">Stories</span>
                                <span class="text-xs tabular-nums text-brand-100/70">{{ $card['stories'] }}</span>
                            </div>
                        @endif
                    </div>

                    @if ($card['top'])
                        <div class="rounded-lg bg-white/[0.03] ring-1 ring-white/10 px-3 py-2.5">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-brand-100/40 mb-0.5">Top this month</p>
                            <p class="text-xs text-white truncate">{{ $card['top']['item']->title ?: 'Untitled' }}</p>
                            <p class="text-[11px] text-brand-100/50 tabular-nums">{{ number_format($card['top']['views']) }} views</p>
                        </div>
                    @endif
                </x-card>
            @endforeach
        </div>
    </section>
@endif
