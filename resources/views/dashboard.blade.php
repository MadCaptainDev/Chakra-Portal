@php
    $toneStyles = [
        'red' => ['card' => 'border-red-200 bg-red-50', 'title' => 'text-red-900', 'body' => 'text-red-700', 'chip' => 'bg-red-100 text-red-700', 'icon' => 'alert'],
        'amber' => ['card' => 'border-amber-200 bg-amber-50', 'title' => 'text-amber-900', 'body' => 'text-amber-800', 'chip' => 'bg-amber-100 text-amber-700', 'icon' => 'alert'],
        'green' => ['card' => 'border-green-200 bg-green-50', 'title' => 'text-green-900', 'body' => 'text-green-700', 'chip' => 'bg-green-100 text-green-700', 'icon' => 'check-circle'],
        'brand' => ['card' => 'border-brand-200 bg-brand-50', 'title' => 'text-brand-900', 'body' => 'text-brand-800', 'chip' => 'bg-brand-100 text-brand-700', 'icon' => 'sparkles'],
    ];
@endphp

<x-app-layout title="Dashboard">
    <x-slot name="header">
        <x-page-header title="Dashboard" :eyebrow="$month->format('F Y')"
                       subtitle="Where the money is this month, and what still needs doing.">
            <x-slot name="actions">
                <x-btn :href="route('invoices.create')" icon="plus">New invoice</x-btn>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-6 sm:space-y-8">

        {{-- ——— Headline numbers ——— --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
            <x-stat-card label="Collected" icon="wallet" accent="green"
                         value="{{ number_format($thisMonthRevenue, 2) }}">
                Payments dated {{ $month->format('M Y') }}
            </x-stat-card>

            <x-stat-card label="Paid out" icon="card" accent="gray"
                         value="{{ number_format($expensePaid, 2) }}">
                Of {{ number_format($expenseDue, 2) }} due
            </x-stat-card>

            <x-stat-card label="Net cash" icon="{{ $netCash >= 0 ? 'trending-up' : 'trending-down' }}"
                         :accent="$netCash >= 0 ? 'green' : 'red'"
                         value="{{ number_format($netCash, 2) }}">
                Collected minus paid out
            </x-stat-card>

            <x-stat-card label="Burn coverage" icon="sparkles"
                         :accent="($burnCoverage ?? 0) >= 100 ? 'green' : 'amber'"
                         value="{{ $burnCoverage !== null ? $burnCoverage.'%' : '—' }}">
                Collections against expenses due
            </x-stat-card>
        </div>

        {{-- ——— What needs doing. Highest-value block on the page, so it sits
                 above every chart rather than in a widget someone dragged away. --}}
        <section>
            <x-section-heading title="Needs attention"
                               subtitle="Ordered by what costs you most to ignore." />

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                @foreach ($actionItems as $item)
                    @php $tone = $toneStyles[$item['tone']] ?? $toneStyles['brand']; @endphp
                    <a href="{{ $item['href'] }}"
                       class="group flex items-start gap-3 rounded-xl border p-4 min-h-[44px] transition hover:shadow-md {{ $tone['card'] }}">
                        <span class="shrink-0 inline-flex items-center justify-center w-9 h-9 rounded-lg {{ $tone['chip'] }}">
                            <x-icon :name="$tone['icon']" class="w-5 h-5" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold {{ $tone['title'] }}">{{ $item['title'] }}</p>
                            <p class="text-xs mt-1 {{ $tone['body'] }}">{{ $item['detail'] }}</p>
                        </div>
                        <span class="shrink-0 inline-flex items-center gap-0.5 text-xs font-bold {{ $tone['title'] }} opacity-70 group-hover:opacity-100 transition">
                            {{ $item['cta'] }}
                            <x-icon name="chevron-right" class="w-4 h-4" />
                        </span>
                    </a>
                @endforeach
            </div>
        </section>

        {{-- ——— Cashflow + unpaid ——— --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 sm:gap-6">
            <x-card padding="md" class="lg:col-span-2">
                <x-section-heading title="Collected vs paid out"
                                   subtitle="Last 6 months" />
                <x-charts.cashflow-bars :points="$monthlyCashflow" />
            </x-card>

            <x-card padding="md">
                <x-section-heading title="Unpaid invoices"
                                   :subtitle="number_format($outstanding, 2).' open'"
                                   :href="route('invoices.index', ['status' => 'unpaid'])"
                                   link-label="All" />

                <div class="-mx-2 divide-y divide-gray-100">
                    @forelse ($recentUnpaid as $invoice)
                        <a href="{{ route('invoices.show', $invoice) }}"
                           class="flex items-center justify-between gap-3 px-2 py-2.5 min-h-[44px] rounded-lg hover:bg-gray-50 transition">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-gray-900 truncate">{{ $invoice->invoice_number }}</p>
                                <p class="text-xs text-gray-500 truncate">
                                    {{ $invoice->client->name }}
                                    @if ($invoice->isOverdue())
                                        <span class="text-red-600 font-semibold">· overdue</span>
                                    @endif
                                    @if ($invoice->isPartiallyPaid())
                                        <span class="text-amber-700 font-semibold">· part paid</span>
                                    @endif
                                </p>
                            </div>
                            <p class="text-sm font-bold text-gray-900 shrink-0 tabular-nums">
                                {{ number_format($invoice->balanceDue(), 0) }}
                            </p>
                        </a>
                    @empty
                        <p class="text-sm text-gray-400 py-8 text-center">Everything is collected.</p>
                    @endforelse
                </div>
            </x-card>
        </div>

        {{-- ——— Where cash is stuck ——— --}}
        @if (count($bottlenecks) > 0)
            <x-card padding="md">
                <x-section-heading title="Where cash is stuck"
                                   subtitle="Amounts still open — tap a row to go and clear it." />
                <x-charts.bar-list :items="$bottlenecks" />
            </x-card>
        @endif

        {{-- ——— Outflow ——— --}}
        <section>
            <x-section-heading title="This Month's Outflow — {{ $month->format('F Y') }}"
                               subtitle="Everything the studio owes this month."
                               :href="route('expenses.index')" />

            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
                <x-stat-card label="Total due" value="{{ number_format($outflowDue, 2) }}" accent="gray" icon="card" />
                <x-stat-card label="Paid" value="{{ number_format($outflowPaid, 2) }}" accent="green" icon="check-circle" />
                <x-stat-card label="Still pending" value="{{ number_format($outflowPending, 2) }}"
                             :accent="$outflowPending > 0 ? 'red' : 'gray'" icon="alert" />
                <x-stat-card label="EMI portion" value="{{ number_format($emiThisMonth, 2) }}" accent="brand"
                             icon="refresh" :href="route('emi.index')" />
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6 mt-4">
                <x-card padding="md">
                    <x-section-heading title="Expense mix"
                                       subtitle="This month's due, by type" />
                    <x-charts.bar-list :items="$expenseSplit" :decimals="2" empty="No expenses due this month." />
                </x-card>

                <x-card padding="md">
                    <x-section-heading title="Income mix"
                                       :subtitle="$incomeMode === 'collected'
                                            ? 'Collections this month, by client'
                                            : 'Invoiced this month, by client — nothing collected yet'" />
                    <x-charts.bar-list :items="$incomeSplit" :decimals="2" empty="No income recorded this month." />
                </x-card>
            </div>
        </section>
    </div>
</x-app-layout>
