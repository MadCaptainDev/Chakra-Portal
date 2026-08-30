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

                    {{-- One row per content type. This is the split the old
                         single "published" number hid: eight things published
                         is a different month depending on whether it was
                         eight reels or eight stories. --}}
                    <div class="space-y-2.5">
                        @foreach ($card['types'] as $source => $type)
                            <div>
                                <div class="flex items-baseline justify-between gap-2 mb-1">
                                    <span class="text-xs text-brand-100/70">{{ $type['label'] }}</span>
                                    <span class="flex items-baseline gap-2 shrink-0">
                                        @if ($type['delta'] !== 0)
                                            {{-- Against the same month last time, so a
                                                 quiet month reads as quiet rather than
                                                 as a number with no reference point. --}}
                                            <span @class([
                                                'text-[11px] tabular-nums',
                                                'text-green-400' => $type['delta'] > 0,
                                                'text-red-300' => $type['delta'] < 0,
                                            ])>
                                                {{ $type['delta'] > 0 ? '▲' : '▼' }}{{ abs($type['delta']) }}
                                            </span>
                                        @endif
                                        <span class="text-sm font-semibold tabular-nums text-white">
                                            {{ $type['actual'] }}@if ($type['target'])<span class="text-brand-100/40">/{{ $type['target'] }}</span>@endif
                                        </span>
                                    </span>
                                </div>

                                @if ($type['target'])
                                    <div class="h-1.5 rounded-full bg-white/[0.07] overflow-hidden">
                                        <div @class([
                                                'h-full rounded-full',
                                                'bg-green-400' => $type['pace'] === 'on_track',
                                                'bg-amber-400' => $type['pace'] === 'behind',
                                                'bg-brand-400' => $type['pace'] === null,
                                             ])
                                             style="width: {{ min(100, $type['pct'] ?? 0) }}%"></div>
                                    </div>
                                @endif
                            </div>
                        @endforeach

                        @if ($card['stories'] > 0)
                            <div class="flex items-baseline justify-between gap-2 pt-1">
                                <span class="text-xs text-brand-100/70">Stories</span>
                                <span class="text-sm font-semibold tabular-nums text-white">{{ $card['stories'] }}</span>
                            </div>
                        @endif
                    </div>

                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 pt-3 border-t border-white/5 text-[11px]">
                        <span class="text-brand-100/50">
                            Total <span class="tabular-nums text-white font-semibold">{{ $card['total'] }}</span>@if ($card['target'])<span class="text-brand-100/40">/{{ $card['target'] }}</span>@endif
                        </span>
                        @php($behind = collect($card['types'])->where('pace', 'behind')->count())
                        @if ($behind > 0)
                            <span class="text-amber-300">{{ $behind }} behind pace</span>
                        @elseif (collect($card['types'])->where('pace', 'on_track')->isNotEmpty())
                            <span class="text-green-400">On track</span>
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
