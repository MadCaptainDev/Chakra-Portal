{{-- ——— Money ——— --}}
<section>
    <div class="flex items-baseline justify-between gap-4 mb-4">
        <x-section-label dark>Money</x-section-label>
    </div>

    {{-- Shrinkable: open by default (this is the number the section
         exists for), collapsible for anyone who's already checked
         it today. "More detail" goes straight to this month's real
         ledger rather than repeating them here. --}}
    <div x-data="{ open: true }">
        <details open @toggle="open = $event.target.open">
            <summary class="list-none cursor-pointer flex items-baseline justify-between gap-4 mb-4">
                <span class="flex items-center gap-2">
                    <x-icon name="chevron-right" class="w-4 h-4 text-brand-100/50 transition-transform shrink-0" x-bind:class="{ 'rotate-90': open }" />
                    {{-- Echoed rather than written as literal markup so the
                         apostrophe is escaped, which DashboardTest pins. --}}
                    <span class="text-xs text-brand-100/60">{{ "This Month's Outflow — ".$month->format('F Y') }}</span>
                </span>
                <a href="{{ route('expenses.index', ['month' => $month->format('Y-m')]) }}"
                   class="shrink-0 text-xs font-semibold uppercase tracking-widest text-brand-300 hover:text-white">
                    More detail →
                </a>
            </summary>

            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3.5">
                @php
                    $outflowTiles = [
                        ['label' => 'Total due', 'value' => $money($outflowDue), 'note' => null, 'accent' => false],
                        ['label' => 'Paid', 'value' => $money($outflowPaid), 'accent' => false,
                         'note' => $outflowDue > 0 ? round($outflowPaid / $outflowDue * 100).'% settled' : null],
                        ['label' => 'Still pending', 'value' => $money($outflowPending), 'accent' => $outflowPending > 0,
                         'note' => $outflowPending > 0 ? 'Clear before month end' : 'All settled'],
                        ['label' => 'EMI portion', 'value' => $money($emiThisMonth), 'accent' => false,
                         'note' => $outflowDue > 0 ? round($emiThisMonth / $outflowDue * 100).'% of the total' : null],
                    ];
                @endphp

                @foreach ($outflowTiles as $tile)
                    <div @class([
                        'rounded-xl p-5 ring-1',
                        'bg-gradient-to-br from-amber-400/20 to-white/5 ring-amber-400/40' => $tile['accent'],
                        'bg-white/5 ring-white/10' => ! $tile['accent'],
                    ])>
                        <p @class(['text-[10px] font-semibold uppercase tracking-[0.16em]', 'text-amber-100' => $tile['accent'], 'text-brand-100/70' => ! $tile['accent']])>{{ $tile['label'] }}</p>
                        <p class="mt-3 text-xl sm:text-2xl font-extrabold leading-none tabular-nums tracking-tight">{{ $tile['value'] }}</p>
                        @if ($tile['note'])
                            <p class="mt-2 text-xs text-brand-100/60">{{ $tile['note'] }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </details>
    </div>

    {{-- The two lists you act on: what is owed to the studio, and what
         the studio's own money is stuck behind. Side by side because
         they are read against each other. --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3.5 mt-3.5 items-start">

    <x-card tone="dark" class="p-5 sm:p-6">
        <div class="flex items-baseline justify-between gap-4">
            <div>
                <x-section-label dark>Unpaid invoices</x-section-label>
                <p class="mt-1 text-xs text-brand-100/60">{{ $money($outstanding) }} open</p>
            </div>
            <a href="{{ route('invoices.index', ['status' => 'unpaid']) }}"
               class="text-xs font-semibold text-brand-300 hover:text-brand-200 transition-colors">All</a>
        </div>

        <div class="mt-4 divide-y divide-white/10">
            @forelse ($recentUnpaid as $invoice)
                <a href="{{ route('invoices.show', $invoice) }}"
                   class="flex items-center justify-between gap-3 py-3 group">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold truncate group-hover:text-brand-200 transition-colors">{{ $invoice->invoice_number }}</p>
                        <p class="text-xs text-brand-100/60 truncate">
                            {{ $invoice->client->name }}
                            @if ($invoice->isOverdue())
                                <span class="text-red-300 font-semibold">&middot; overdue</span>
                            @endif
                            @if ($invoice->isPartiallyPaid())
                                <span class="text-amber-300 font-semibold">&middot; part paid</span>
                            @endif
                        </p>
                    </div>
                    <p class="shrink-0 text-sm font-bold tabular-nums">{{ $money($invoice->balanceDue()) }}</p>
                </a>
            @empty
                <p class="py-8 text-center text-sm text-brand-100/60">Everything is collected.</p>
            @endforelse
        </div>
    </x-card>

    {{-- Where cash is stuck --}}
    @if (count($bottlenecks) > 0)
        @php $stuckMax = max(1, collect($bottlenecks)->max('value')); @endphp

        <x-card tone="dark" class="p-5 sm:p-6">
            <div class="flex items-baseline justify-between gap-4">
                <x-section-label dark>Where cash is stuck</x-section-label>
                <p class="text-xs text-brand-100/60">Tap a row to go and clear it</p>
            </div>

            <div class="mt-5 space-y-4">
                @foreach ($bottlenecks as $row)
                    <a href="{{ $row['href'] }}" class="block group">
                        <div class="flex justify-between items-baseline text-sm mb-1.5">
                            <span class="group-hover:text-brand-200 transition-colors">{{ $row['label'] }}</span>
                            <span class="font-semibold tabular-nums">{{ $money($row['value']) }}</span>
                        </div>
                        <div class="h-2 rounded-full bg-white/10 overflow-hidden">
                            <div class="h-full rounded-full" style="width: {{ max(3, round($row['value'] / $stuckMax * 100)) }}%; background: {{ $row['color'] }}"></div>
                        </div>
                    </a>
                @endforeach
            </div>
        </x-card>
    @endif

    </div>

    {{-- Collected vs paid out. Full width -- six months of bars squeezed
         into half a screen is unreadable. --}}
    <x-card tone="dark" class="p-5 sm:p-6 mt-3.5">
        <div class="flex flex-wrap items-baseline justify-between gap-4">
            <div>
                <x-section-label dark>Collected vs paid out</x-section-label>
                <p class="mt-1 text-xs text-brand-100/60">Last 6 months</p>
            </div>
            <div class="flex gap-4 text-[11px] text-brand-100/60">
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-sm bg-brand-400"></span>Collected</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-sm bg-white/25"></span>Paid out</span>
            </div>
        </div>

        @php $peak = max(1, $monthlyCashflow->flatMap(fn ($p) => [$p['income'], $p['expense']])->max()); @endphp

        <div class="mt-6 flex items-end gap-2 sm:gap-6 h-40 sm:h-48 overflow-x-auto">
            @foreach ($monthlyCashflow as $point)
                <div class="flex-1 min-w-[36px] flex flex-col items-center h-full">
                    <div class="flex-1 w-full flex items-end justify-center gap-1.5">
                        <div class="w-1/4 min-w-[8px] rounded-t bg-gradient-to-b from-brand-300 to-brand-600"
                             style="height: {{ max(1, round($point['income'] / $peak * 100)) }}%"
                             title="Collected {{ $money($point['income'], 0) }}"></div>
                        <div class="w-1/4 min-w-[8px] rounded-t bg-white/20"
                             style="height: {{ max(1, round($point['expense'] / $peak * 100)) }}%"
                             title="Paid out {{ $money($point['expense'], 0) }}"></div>
                    </div>
                    <p class="mt-2 text-[10px] sm:text-[11px] text-brand-100/60">{{ $point['label'] }}</p>
                </div>
            @endforeach
        </div>
    </x-card>

    {{-- Expense and income mix --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3.5 mt-3.5">
        @php
            $mixes = [
                ['title' => 'Expense mix', 'subtitle' => "This month's due, by type", 'items' => $expenseSplit,
                 'empty' => 'No expenses due this month.'],
                ['title' => 'Income mix', 'items' => $incomeSplit,
                 'subtitle' => $incomeMode === 'collected'
                    ? 'Collections this month, by client'
                    : 'Invoiced this month, by client — nothing collected yet',
                 'empty' => 'No income recorded this month.'],
            ];
        @endphp

        @foreach ($mixes as $mix)
            @php
                $rows = collect($mix['items'])->filter(fn ($i) => (float) ($i['value'] ?? 0) > 0)->sortByDesc('value')->take(6);
                $mixMax = max(1, (float) ($rows->max('value') ?? 0));
            @endphp

            <x-card tone="dark" class="p-5 sm:p-6">
                <x-section-label dark>{{ $mix['title'] }}</x-section-label>
                <p class="mt-1 text-xs text-brand-100/60">{{ $mix['subtitle'] }}</p>

                <div class="mt-5 space-y-3.5">
                    @forelse ($rows as $row)
                        <div>
                            <div class="flex justify-between items-baseline gap-3 text-sm mb-1.5">
                                <span class="truncate">{{ $row['label'] }}</span>
                                <span class="shrink-0 tabular-nums text-brand-100/70">{{ $money($row['value']) }}</span>
                            </div>
                            {{-- Per-type colour, the same key the "where cash
                                 is stuck" rows use, so a colour means the
                                 same thing everywhere on the page. --}}
                            <div class="h-1.5 rounded-full bg-white/10 overflow-hidden">
                                <div class="h-full rounded-full"
                                     style="width: {{ round($row['value'] / $mixMax * 100) }}%; background-color: {{ $row['color'] ?? '#67BCD4' }}"></div>
                            </div>
                        </div>
                    @empty
                        <p class="py-6 text-sm text-brand-100/60">{{ $mix['empty'] }}</p>
                    @endforelse
                </div>
            </x-card>
        @endforeach
    </div>
</section>
