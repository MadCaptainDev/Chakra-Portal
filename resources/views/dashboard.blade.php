@php
    $toneClasses = [
        'red' => 'border-red-200 bg-red-50 text-red-800',
        'amber' => 'border-amber-200 bg-amber-50 text-amber-900',
        'green' => 'border-green-200 bg-green-50 text-green-800',
        'brand' => 'border-brand-200 bg-brand-50 text-brand-900',
    ];
    $hasExpenseSplit = count($expenseSplit['values'] ?? []) > 0;
    $hasIncomeSplit = count($incomeSplit['values'] ?? []) > 0;
@endphp

@push('styles')
    <link href="https://cdn.jsdelivr.net/npm/gridstack@10.3.1/dist/gridstack.min.css" rel="stylesheet">
    <style>
        .dashboard-grid .grid-stack-item-content {
            inset: 4px;
            overflow: auto;
            background: #fff;
            border-radius: 0.5rem;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
            border: 1px solid #f1f5f9;
        }
        .dashboard-grid .ui-resizable-handle { opacity: 0.35; }
        .dashboard-grid .grid-stack-item:hover .ui-resizable-handle { opacity: 0.7; }
        .dashboard-widget { height: 100%; display: flex; flex-direction: column; min-height: 0; }
        .dashboard-widget-body { flex: 1 1 auto; min-height: 0; position: relative; }
        .dashboard-widget-body canvas { max-height: 100%; }
        .dashboard-drag-handle { cursor: move; }
        @media (max-width: 1023px) {
            /* GridStack is deliberately not initialised below this width, so undo
               gridstack.min.css's absolute positioning and let the items stack. */
            .dashboard-grid { height: auto !important; }
            .dashboard-grid > .grid-stack-item {
                position: static !important;
                inset: auto !important;
                transform: none !important;
                width: 100% !important;
                height: auto !important;
                min-height: 0 !important;
                padding: 0 !important;
                margin: 0 0 0.75rem 0 !important;
            }
            .dashboard-grid > .grid-stack-item > .grid-stack-item-content {
                position: static !important;
                inset: auto !important;
                overflow: visible !important;
            }
            .dashboard-widget { height: auto !important; }

            /* .dashboard-widget-body is flex:1 of a now auto-height parent, which
               resolves to 0 and collapses the charts. Give it a real height. */
            .dashboard-widget-body { min-height: 260px; }

            /* Tab filter. Alpine owns nothing but the data-tab value, so no inline
               display is ever written and desktop cannot be affected. */
            .dashboard-grid[data-tab] > .grid-stack-item[data-panel] { display: none; }
            /* .grid-stack-item is repeated here on purpose: without it these
               selectors score lower than the hide rule above and every panel
               stays hidden. Equal specificity, later in source order, so these win. */
            .dashboard-grid[data-tab="overview"]    > .grid-stack-item[data-panel~="overview"],
            .dashboard-grid[data-tab="cashflow"]    > .grid-stack-item[data-panel~="cashflow"],
            .dashboard-grid[data-tab="outstanding"] > .grid-stack-item[data-panel~="outstanding"],
            .dashboard-grid[data-tab="splits"]      > .grid-stack-item[data-panel~="splits"] { display: block; }

            /* Drag handles are dead weight when drag is off. */
            .dashboard-drag-handle { display: none; }
        }
    </style>
@endpush

<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Dashboard">
            <x-slot name="actions">
                <div class="flex items-center gap-3">
                    <span class="text-sm text-gray-500">{{ $month->format('F Y') }} snapshot</span>
                    {{-- Nothing to reset below lg: the grid is not draggable there. --}}
                    <button type="button" id="resetDashboardLayout"
                            class="hidden lg:inline-flex items-center text-xs font-semibold text-gray-500 hover:text-brand-600 min-h-[44px]">
                        Reset layout
                    </button>
                </div>
            </x-slot>
        </x-page-header>
    </x-slot>

    {{-- x-data lives on a plain div: Blade component tags break compilation. --}}
    <div class="space-y-6" x-data="dashboardTabs()">
        {{-- Fixed KPIs — always first, and pinned above the tabs on mobile --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <x-stat-card label="Collected this month" value="{{ number_format($thisMonthRevenue, 2) }}" accent="green">
                Payments dated {{ $month->format('M Y') }}
            </x-stat-card>
            <x-stat-card label="Expenses paid" value="{{ number_format($expensePaid, 2) }}" accent="gray">
                Of {{ number_format($expenseDue, 2) }} due
            </x-stat-card>
            <x-stat-card label="Net cash (month)" value="{{ number_format($netCash, 2) }}" :accent="$netCash >= 0 ? 'green' : 'red'">
                Collected minus expenses paid
            </x-stat-card>
            <x-stat-card label="Collection vs burn" value="{{ $burnCoverage !== null ? $burnCoverage.'%' : '—' }}" :accent="($burnCoverage ?? 0) >= 100 ? 'green' : 'amber'">
                Revenue covering this month’s expense due
            </x-stat-card>
        </div>

        {{-- Mobile: tabs. Desktop keeps the draggable grid. --}}
        <nav class="lg:hidden flex gap-1 overflow-x-auto border-b border-gray-200 -mb-px"
             role="tablist" aria-label="Dashboard sections">
            <template x-for="item in tabs" :key="item.key">
                <button type="button" role="tab" @click="select(item.key)"
                        :aria-selected="tab === item.key ? 'true' : 'false'"
                        class="shrink-0 inline-flex items-center min-h-[44px] px-4 text-sm font-semibold border-b-2 -mb-px transition-colors"
                        :class="tab === item.key
                            ? 'border-brand-500 text-brand-600'
                            : 'border-transparent text-gray-500 hover:text-gray-800 hover:border-gray-300'"
                        x-text="item.label"></button>
            </template>
        </nav>

        {{-- Drag and resize are both off below lg, so don't advertise them there. --}}
        <p class="hidden lg:block text-xs text-gray-500 -mt-2">Drag widgets by the handle · resize from corners · layout is saved on this browser</p>

        {{-- data-tab is server-rendered so the CSS filter is right before Alpine boots. --}}
        <div class="grid-stack dashboard-grid" id="dashboardGrid" data-tab="overview" :data-tab="tab">
            {{-- Main changeable chart --}}
            <div class="grid-stack-item" data-panel="cashflow" gs-id="main-chart" gs-x="0" gs-y="0" gs-w="12" gs-h="5" gs-min-w="6" gs-min-h="4">
                <div class="grid-stack-item-content">
                    <div class="dashboard-widget p-4 sm:p-5">
                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-3">
                            <div class="flex items-start gap-2 min-w-0">
                                <span class="dashboard-drag-handle mt-1 text-gray-300 hover:text-gray-500" title="Drag">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M7 4a1 1 0 100-2 1 1 0 000 2zm6 0a1 1 0 100-2 1 1 0 000 2zM7 11a1 1 0 100-2 1 1 0 000 2zm6 0a1 1 0 100-2 1 1 0 000 2zM7 18a1 1 0 100-2 1 1 0 000 2zm6 0a1 1 0 100-2 1 1 0 000 2z"/></svg>
                                </span>
                                <div>
                                    <h3 class="font-semibold text-gray-900" id="mainChartTitle">Overall cashflow</h3>
                                    <p class="text-xs text-gray-500" id="mainChartSubtitle">Collected vs paid out, last 6 months</p>
                                </div>
                            </div>
                            <label class="shrink-0 text-xs font-semibold text-gray-600">
                                Graph
                                <select id="mainMetric" class="ml-2 rounded-md border-gray-300 text-sm font-medium text-gray-900 focus:border-brand-400 focus:ring-brand-400 min-h-[44px]">
                                    <option value="cashflow">Cashflow</option>
                                    <option value="expense">Expense split</option>
                                    <option value="income">Income split</option>
                                    <option value="bottlenecks">Where cash is stuck</option>
                                </select>
                            </label>
                        </div>
                        <div class="dashboard-widget-body">
                            <canvas id="mainChart" aria-label="Main dashboard chart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Bottlenecks summary --}}
            <div class="grid-stack-item" data-panel="outstanding" gs-id="bottlenecks" gs-x="0" gs-y="5" gs-w="6" gs-h="3" gs-min-w="4" gs-min-h="3">
                <div class="grid-stack-item-content">
                    <div class="dashboard-widget p-4 sm:p-5">
                        <div class="flex items-start gap-2 mb-3">
                            <span class="dashboard-drag-handle mt-1 text-gray-300 hover:text-gray-500">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M7 4a1 1 0 100-2 1 1 0 000 2zm6 0a1 1 0 100-2 1 1 0 000 2zM7 11a1 1 0 100-2 1 1 0 000 2zm6 0a1 1 0 100-2 1 1 0 000 2zM7 18a1 1 0 100-2 1 1 0 000 2zm6 0a1 1 0 100-2 1 1 0 000 2z"/></svg>
                            </span>
                            <div class="min-w-0">
                                <h3 class="font-semibold text-gray-900">Where cash is stuck</h3>
                                <p class="text-xs text-gray-500">
                                    {{ number_format($bottlenecks['total'] ?? 0, 0) }} open in total — biggest first
                                </p>
                            </div>
                        </div>

                        {{-- Same order and colours as the Bottlenecks graph, so
                             reading one teaches you the other. --}}
                        <div class="space-y-2.5 overflow-y-auto">
                            @forelse ($bottlenecks['labels'] ?? [] as $index => $label)
                                @php
                                    $value = $bottlenecks['values'][$index] ?? 0;
                                    $largest = max($bottlenecks['values'] ?: [1]);
                                    $share = $largest > 0 ? max(2, (int) round($value / $largest * 100)) : 0;
                                @endphp
                                <div>
                                    <div class="flex items-baseline justify-between gap-2 text-sm">
                                        <span class="text-gray-600 truncate">{{ $label }}</span>
                                        <span class="font-semibold text-gray-900 shrink-0">{{ number_format($value, 0) }}</span>
                                    </div>
                                    {{-- Inline width and colour: both come from
                                         data, and the JIT cannot see either. --}}
                                    <div class="mt-1 h-1.5 rounded-full bg-gray-100 overflow-hidden">
                                        <div class="h-full rounded-full"
                                             style="width: {{ $share }}%; background-color: {{ $bottlenecks['colors'][$index] ?? '#64748b' }}"></div>
                                    </div>
                                </div>
                            @empty
                                <div class="rounded-lg border border-green-200 bg-green-50 p-3">
                                    <p class="text-sm font-semibold text-green-800">Nothing is stuck</p>
                                    <p class="text-xs text-green-700 mt-0.5">No overdue invoices and no unpaid outflow this month.</p>
                                </div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>

            {{-- What needs work --}}
            <div class="grid-stack-item" data-panel="overview" gs-id="actions" gs-x="6" gs-y="5" gs-w="6" gs-h="3" gs-min-w="4" gs-min-h="3">
                <div class="grid-stack-item-content">
                    <div class="dashboard-widget p-4 sm:p-5">
                        @php
                            // "All clear" is the one item that is not a job to
                            // do, so it must not be counted as one.
                            $jobCount = collect($actionItems)->where('tone', '!=', 'green')->count();
                        @endphp

                        <div class="flex items-start justify-between gap-2 mb-3">
                            <div class="flex items-start gap-2 min-w-0">
                                <span class="dashboard-drag-handle mt-1 text-gray-300 hover:text-gray-500">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M7 4a1 1 0 100-2 1 1 0 000 2zm6 0a1 1 0 100-2 1 1 0 000 2zM7 11a1 1 0 100-2 1 1 0 000 2zm6 0a1 1 0 100-2 1 1 0 000 2zM7 18a1 1 0 100-2 1 1 0 000 2zm6 0a1 1 0 100-2 1 1 0 000 2z"/></svg>
                                </span>
                                <div class="min-w-0">
                                    <h3 class="font-semibold text-gray-900">What needs work</h3>
                                    <p class="text-xs text-gray-500">
                                        {{ $jobCount > 0 ? 'Most urgent first' : 'Nothing outstanding' }}
                                    </p>
                                </div>
                            </div>
                            @if ($jobCount > 0)
                                <span class="shrink-0 inline-flex items-center justify-center min-w-[24px] h-6 px-2 rounded-full bg-gray-900 text-white text-xs font-bold">
                                    {{ $jobCount }}
                                </span>
                            @endif
                        </div>

                        {{-- Ranked by the controller: the first card is always
                             the thing to deal with first. --}}
                        <div class="space-y-2 overflow-y-auto">
                            @foreach ($actionItems as $index => $item)
                                <div class="rounded-lg border p-3 {{ $toneClasses[$item['tone']] ?? $toneClasses['brand'] }}">
                                    <div class="flex items-start gap-3">
                                        @if ($item['tone'] !== 'green')
                                            <span class="shrink-0 inline-flex items-center justify-center w-6 h-6 rounded-full bg-white/70 text-xs font-bold">
                                                {{ $index + 1 }}
                                            </span>
                                        @endif

                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-baseline justify-between gap-3">
                                                <p class="text-sm font-semibold">{{ $item['title'] }}</p>
                                                @if ($item['amount'] !== null)
                                                    <p class="text-base font-bold shrink-0 tabular-nums">{{ number_format($item['amount'], 0) }}</p>
                                                @endif
                                            </div>
                                            <p class="text-xs mt-1 opacity-90">{{ $item['detail'] }}</p>

                                            <a href="{{ $item['href'] }}"
                                               class="mt-1 text-xs font-semibold underline min-h-[44px] inline-flex items-center">
                                                {{ $item['cta'] }} &rarr;
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            {{-- Secondary charts (expense + income pies) --}}
            <div class="grid-stack-item" data-panel="splits" gs-id="secondary" gs-x="0" gs-y="8" gs-w="8" gs-h="4" gs-min-w="4" gs-min-h="3">
                <div class="grid-stack-item-content">
                    <div class="dashboard-widget p-4 sm:p-5">
                        <div class="flex items-center gap-2 mb-3">
                            <span class="dashboard-drag-handle text-gray-300 hover:text-gray-500">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M7 4a1 1 0 100-2 1 1 0 000 2zm6 0a1 1 0 100-2 1 1 0 000 2zM7 11a1 1 0 100-2 1 1 0 000 2zm6 0a1 1 0 100-2 1 1 0 000 2zM7 18a1 1 0 100-2 1 1 0 000 2zm6 0a1 1 0 100-2 1 1 0 000 2z"/></svg>
                            </span>
                            <div>
                                <h3 class="font-semibold text-gray-900">Expense & income mix</h3>
                                <p class="text-xs text-gray-500">Secondary view — main graph can switch metrics above</p>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 dashboard-widget-body">
                            <div class="min-h-0 flex flex-col">
                                <p class="text-xs font-semibold text-gray-600 mb-2">Expense split</p>
                                @if ($hasExpenseSplit)
                                    <div class="relative flex-1 min-h-[140px]">
                                        <canvas id="secondaryExpenseChart"></canvas>
                                    </div>
                                @else
                                    <p class="text-sm text-gray-400 py-6 text-center">No expense data</p>
                                @endif
                            </div>
                            <div class="min-h-0 flex flex-col">
                                <p class="text-xs font-semibold text-gray-600 mb-2">Income split</p>
                                @if ($hasIncomeSplit)
                                    <div class="relative flex-1 min-h-[140px]">
                                        <canvas id="secondaryIncomeChart"></canvas>
                                    </div>
                                @else
                                    <p class="text-sm text-gray-400 py-6 text-center">No income data</p>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Unpaid invoices --}}
            <div class="grid-stack-item" data-panel="outstanding" gs-id="unpaid" gs-x="8" gs-y="8" gs-w="4" gs-h="4" gs-min-w="3" gs-min-h="3">
                <div class="grid-stack-item-content">
                    <div class="dashboard-widget p-4 sm:p-5">
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex items-center gap-2 min-w-0">
                                <span class="dashboard-drag-handle text-gray-300 hover:text-gray-500">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M7 4a1 1 0 100-2 1 1 0 000 2zm6 0a1 1 0 100-2 1 1 0 000 2zM7 11a1 1 0 100-2 1 1 0 000 2zm6 0a1 1 0 100-2 1 1 0 000 2zM7 18a1 1 0 100-2 1 1 0 000 2zm6 0a1 1 0 100-2 1 1 0 000 2z"/></svg>
                                </span>
                                <div class="min-w-0">
                                    <h3 class="font-semibold text-gray-900 truncate">Unpaid invoices</h3>
                                    <p class="text-xs text-gray-500">{{ number_format($outstanding, 2) }} open</p>
                                </div>
                            </div>
                            <a href="{{ route('invoices.index', ['status' => 'unpaid']) }}" class="text-xs font-semibold text-brand-500 hover:text-brand-600 shrink-0">All</a>
                        </div>
                        <div class="overflow-y-auto divide-y divide-gray-100">
                            @forelse ($recentUnpaid as $invoice)
                                <a href="{{ route('invoices.show', $invoice) }}" class="py-2 flex items-center justify-between gap-2 hover:bg-gray-50 -mx-1 px-1 rounded">
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-gray-900 truncate">{{ $invoice->invoice_number }}</p>
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
                                    <p class="text-sm font-semibold text-gray-900 shrink-0">{{ number_format($invoice->balanceDue(), 0) }}</p>
                                </a>
                            @empty
                                <p class="text-sm text-gray-400 py-6 text-center">No unpaid invoices.</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>

            {{-- Money going out. The dashboard covered only invoices until the
                 EMI, Salaries and Bills modules landed, which left half the
                 product invisible from the home screen. --}}
            <div class="grid-stack-item" data-panel="overview" gs-id="outflow" gs-x="0" gs-y="12" gs-w="12" gs-h="3" gs-min-w="4" gs-min-h="3">
                <div class="grid-stack-item-content">
                    <div class="dashboard-widget p-4 sm:p-5">
                        <div class="flex items-center justify-between gap-2 mb-3">
                            <div class="flex items-start gap-2 min-w-0">
                                <span class="dashboard-drag-handle mt-1 text-gray-300 hover:text-gray-500" title="Drag">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M7 4a1 1 0 100-2 1 1 0 000 2zm6 0a1 1 0 100-2 1 1 0 000 2zM7 11a1 1 0 100-2 1 1 0 000 2zm6 0a1 1 0 100-2 1 1 0 000 2zM7 18a1 1 0 100-2 1 1 0 000 2zm6 0a1 1 0 100-2 1 1 0 000 2z"/></svg>
                                </span>
                                <h3 class="font-semibold text-gray-800">This Month's Outflow &mdash; {{ $month->format('F Y') }}</h3>
                            </div>
                            <a href="{{ route('expenses.index') }}" class="shrink-0 text-sm font-semibold text-brand-500 hover:text-brand-600">
                                View all &rarr;
                            </a>
                        </div>

                        <div class="dashboard-widget-body grid grid-cols-2 lg:grid-cols-4 gap-4">
                            <x-stat-card label="Total Due" value="{{ number_format($outflowDue, 2) }}" accent="gray" />
                            <x-stat-card label="Paid" value="{{ number_format($outflowPaid, 2) }}" accent="green" />
                            <x-stat-card label="Still Pending" value="{{ number_format($outflowPending, 2) }}"
                                         accent="{{ $outflowPending > 0 ? 'red' : 'gray' }}" />
                            <x-stat-card label="EMI Portion" value="{{ number_format($emiThisMonth, 2) }}" accent="brand">
                                <a href="{{ route('emi.index') }}" class="text-brand-500 hover:text-brand-600">EMI detail &rarr;</a>
                            </x-stat-card>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Plain <script>, not @push('scripts'): Alpine resolves x-data at boot and
         the pushed stack runs the CDN libraries the grid/charts need instead. --}}
    <script>
        const DASHBOARD_STORAGE_KEY = 'chakra.dashboard.v1';
        const DASHBOARD_TABS = ['overview', 'cashflow', 'outstanding', 'splits'];

        function dashboardTabs() {
            return {
                tab: 'overview',
                tabs: [
                    { key: 'overview', label: 'Overview' },
                    { key: 'cashflow', label: 'Cashflow' },
                    { key: 'outstanding', label: 'Outstanding' },
                    { key: 'splits', label: 'Splits' },
                ],

                init() {
                    // Same blob and whitelist guard as the chart metric, so
                    // "Reset layout" stays the one place that clears preferences.
                    try {
                        const saved = JSON.parse(localStorage.getItem(DASHBOARD_STORAGE_KEY) || '{}');
                        if (DASHBOARD_TABS.includes(saved.tab)) {
                            this.tab = saved.tab;
                        }
                    } catch (e) {
                        // Corrupt state falls back to Overview.
                    }
                },

                select(key) {
                    if (! DASHBOARD_TABS.includes(key)) {
                        return;
                    }

                    this.tab = key;

                    try {
                        const next = Object.assign(
                            {},
                            JSON.parse(localStorage.getItem(DASHBOARD_STORAGE_KEY) || '{}'),
                            { tab: key }
                        );
                        localStorage.setItem(DASHBOARD_STORAGE_KEY, JSON.stringify(next));
                    } catch (e) {
                        // Private mode: the tab just won't persist.
                    }

                    // A chart inside a display:none panel measured zero, so ask
                    // the dashboard script to re-measure once this one is painted.
                    this.$nextTick(() => window.dispatchEvent(new CustomEvent('dashboard:panel-shown')));
                },
            };
        }
    </script>

    @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/gridstack@10.3.1/dist/gridstack-all.js" defer></script>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
        <script defer>
            document.addEventListener('DOMContentLoaded', function () {
                const STORAGE_KEY = 'chakra.dashboard.v1';
                const cashflow = @json($monthlyCashflow->values());
                const expenseSplit = @json($expenseSplit);
                const incomeSplit = @json($incomeSplit);
                const bottlenecks = @json($bottlenecks);

                // Indian grouping, because every figure in this product is INR
                // and 12,34,567 is what the team reads without stopping.
                const money = (v) => Number(v).toLocaleString('en-IN', { maximumFractionDigits: 0 });
                const sum = (values) => (values || []).reduce((a, b) => a + Number(b), 0);
                const percent = (part, whole) => (whole > 0 ? Math.round((part / whole) * 100) : 0);

                const latest = cashflow[cashflow.length - 1] || { income: 0, expense: 0, net: 0 };

                const meta = {
                    cashflow: {
                        title: 'Overall cashflow',
                        subtitle: 'Collected vs paid out, last 6 months. '
                            + @json(now()->format('M')) + ' net: '
                            + (latest.net < 0 ? '−' : '+') + money(Math.abs(latest.net)),
                    },
                    expense: {
                        title: 'Expense split',
                        subtitle: 'This month’s due mix — ' + money(sum(expenseSplit.values)) + ' across EMI, salary, bills and one-time',
                    },
                    income: {
                        title: 'Income split',
                        subtitle: @json(($incomeSplit['mode'] ?? '') === 'collected'
                            ? 'Collections this month by client'
                            : 'Invoiced this month by client (no collections recorded yet)')
                            + ' — ' + money(sum(incomeSplit.values)) + ' total',
                    },
                    bottlenecks: {
                        title: 'Where cash is stuck',
                        subtitle: money(sum(bottlenecks.values)) + ' open — biggest blocker first',
                    },
                };

                /*
                 * Prints each bar's value at its end. Chart.js ships no data
                 * labels and the brief forbids adding a plugin package, so this
                 * is a local plugin object rather than chartjs-plugin-datalabels.
                 */
                const barValueLabels = {
                    id: 'barValueLabels',
                    afterDatasetsDraw(chart) {
                        const { ctx } = chart;
                        ctx.save();
                        ctx.font = '600 11px Poppins, sans-serif';
                        ctx.fillStyle = '#334155';
                        ctx.textBaseline = 'middle';

                        chart.data.datasets.forEach((dataset, i) => {
                            const drawn = chart.getDatasetMeta(i);
                            if (drawn.hidden || drawn.type === 'line') return;

                            drawn.data.forEach((bar, index) => {
                                const value = dataset.data[index];
                                if (! value) return;

                                if (chart.options.indexAxis === 'y') {
                                    ctx.textAlign = 'left';
                                    ctx.fillText(money(value), bar.x + 6, bar.y);
                                } else {
                                    ctx.textAlign = 'center';
                                    ctx.fillText(money(value), bar.x, bar.y - 8);
                                }
                            });
                        });

                        ctx.restore();
                    },
                };

                /* The total in the hole of a doughnut, so the slices have
                   something to be a share of. */
                const doughnutTotal = {
                    id: 'doughnutTotal',
                    afterDraw(chart) {
                        const total = sum(chart.data.datasets[0]?.data);
                        if (! total) return;

                        const { ctx, chartArea } = chart;
                        if (! chartArea) return;

                        const x = (chartArea.left + chartArea.right) / 2;
                        const y = (chartArea.top + chartArea.bottom) / 2;

                        ctx.save();
                        ctx.textAlign = 'center';
                        ctx.textBaseline = 'middle';
                        ctx.fillStyle = '#0f172a';
                        ctx.font = '700 15px Poppins, sans-serif';
                        ctx.fillText(money(total), x, y - 2);
                        ctx.fillStyle = '#94a3b8';
                        ctx.font = '600 10px Poppins, sans-serif';
                        ctx.fillText('TOTAL', x, y + 14);
                        ctx.restore();
                    },
                };

                // Legend entries carry their own share, so the chart reads
                // without hovering every slice.
                const shareLegend = {
                    generateLabels(chart) {
                        const data = chart.data.datasets[0]?.data || [];
                        const total = sum(data);

                        return (chart.data.labels || []).map((label, i) => ({
                            text: `${label} — ${percent(data[i], total)}%`,
                            fillStyle: chart.data.datasets[0].backgroundColor[i],
                            strokeStyle: '#fff',
                            lineWidth: 1,
                            index: i,
                        }));
                    },
                    boxWidth: 12,
                    font: { size: 11 },
                };

                let mainChart = null;
                let secondaryExpense = null;
                let secondaryIncome = null;
                let grid = null;

                // The one desktop/mobile line in the app, matching the sidebar.
                const desktopQuery = window.matchMedia('(min-width: 1024px)');

                function loadState() {
                    try {
                        return JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}') || {};
                    } catch (e) {
                        return {};
                    }
                }

                function saveState(partial) {
                    const next = Object.assign({}, loadState(), partial);
                    localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
                }

                function destroyMain() {
                    if (mainChart) {
                        mainChart.destroy();
                        mainChart = null;
                    }
                }

                function renderMain(metric) {
                    const el = document.getElementById('mainChart');
                    if (!el || typeof Chart === 'undefined') return;
                    destroyMain();

                    document.getElementById('mainChartTitle').textContent = meta[metric].title;
                    document.getElementById('mainChartSubtitle').textContent = meta[metric].subtitle;

                    const common = {
                        responsive: true,
                        maintainAspectRatio: false,
                    };

                    if (metric === 'cashflow') {
                        mainChart = new Chart(el, {
                            type: 'bar',
                            data: {
                                labels: cashflow.map((p) => p.label),
                                datasets: [
                                    {
                                        label: 'Collected',
                                        data: cashflow.map((p) => p.income),
                                        backgroundColor: '#4FA9C4',
                                        borderRadius: 4,
                                        maxBarThickness: 28,
                                        order: 2,
                                    },
                                    {
                                        label: 'Paid out',
                                        data: cashflow.map((p) => p.expense),
                                        backgroundColor: '#f59e0b',
                                        borderRadius: 4,
                                        maxBarThickness: 28,
                                        order: 2,
                                    },
                                    {
                                        // The month that ends below zero is the
                                        // whole question, and two bars side by
                                        // side never answered it on their own.
                                        label: 'Net',
                                        type: 'line',
                                        data: cashflow.map((p) => p.net),
                                        borderColor: '#0f172a',
                                        backgroundColor: '#0f172a',
                                        borderWidth: 2,
                                        pointRadius: 3,
                                        pointHoverRadius: 5,
                                        tension: 0.25,
                                        order: 1,
                                    },
                                ],
                            },
                            options: {
                                ...common,
                                interaction: { mode: 'index', intersect: false },
                                plugins: {
                                    legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } },
                                    tooltip: {
                                        callbacks: {
                                            label: (ctx) => {
                                                const value = ctx.parsed.y;
                                                const sign = ctx.dataset.label === 'Net' && value < 0 ? '−' : '';

                                                return `${ctx.dataset.label}: ${sign}${money(Math.abs(value))}`;
                                            },
                                        },
                                    },
                                },
                                scales: {
                                    x: { grid: { display: false } },
                                    y: {
                                        // Not beginAtZero: a negative net month
                                        // has to be visible below the axis.
                                        ticks: { callback: (v) => money(v) },
                                        grid: { color: 'rgba(15, 23, 42, 0.06)' },
                                    },
                                },
                            },
                        });
                        return;
                    }

                    if (metric === 'bottlenecks') {
                        if (! bottlenecks.values?.length) {
                            renderNothing(el, 'Nothing is stuck — no overdue invoices and no unpaid outflow.');
                            return;
                        }

                        const openTotal = sum(bottlenecks.values);

                        mainChart = new Chart(el, {
                            type: 'bar',
                            plugins: [barValueLabels],
                            data: {
                                labels: bottlenecks.labels,
                                datasets: [{
                                    label: 'Amount open',
                                    data: bottlenecks.values,
                                    backgroundColor: bottlenecks.colors,
                                    borderRadius: 4,
                                    maxBarThickness: 36,
                                }],
                            },
                            options: {
                                ...common,
                                indexAxis: 'y',
                                // Headroom on the right, or the printed value
                                // on the longest bar runs off the canvas.
                                layout: { padding: { right: 56 } },
                                plugins: {
                                    legend: { display: false },
                                    tooltip: {
                                        callbacks: {
                                            label: (ctx) => `${money(ctx.parsed.x)} (${percent(ctx.parsed.x, openTotal)}% of what is open)`,
                                        },
                                    },
                                },
                                scales: {
                                    x: {
                                        beginAtZero: true,
                                        ticks: { callback: (v) => money(v) },
                                        grid: { color: 'rgba(15, 23, 42, 0.06)' },
                                    },
                                    y: { grid: { display: false }, ticks: { font: { size: 11 } } },
                                },
                            },
                        });
                        return;
                    }

                    const pie = metric === 'expense' ? expenseSplit : incomeSplit;
                    if (!pie.values?.length) {
                        renderNothing(el, metric === 'expense'
                            ? 'No expenses due this month.'
                            : 'Nothing invoiced or collected this month.');
                        return;
                    }

                    mainChart = new Chart(el, {
                        type: 'doughnut',
                        plugins: [doughnutTotal],
                        data: {
                            labels: pie.labels,
                            datasets: [{
                                data: pie.values,
                                backgroundColor: pie.colors,
                                borderWidth: 2,
                                borderColor: '#fff',
                            }],
                        },
                        options: {
                            ...common,
                            cutout: '58%',
                            plugins: {
                                legend: { position: 'bottom', labels: shareLegend },
                                tooltip: {
                                    callbacks: {
                                        label: (ctx) => {
                                            const total = sum(ctx.dataset.data);
                                            return `${ctx.label}: ${money(ctx.parsed)} (${percent(ctx.parsed, total)}%)`;
                                        },
                                    },
                                },
                            },
                        },
                    });
                }

                /**
                 * An empty metric used to clear the canvas and leave a blank
                 * rectangle, which reads as a broken chart. Say why instead.
                 */
                function renderNothing(el, message) {
                    const ctx = el.getContext('2d');
                    const width = el.clientWidth || el.width;
                    const height = el.clientHeight || el.height;

                    // Canvas pixels are scaled by devicePixelRatio; reset the
                    // transform so the text lands where CSS says it should.
                    ctx.setTransform(1, 0, 0, 1, 0, 0);
                    ctx.clearRect(0, 0, el.width, el.height);

                    ctx.save();
                    ctx.fillStyle = '#94a3b8';
                    ctx.font = '500 13px Poppins, sans-serif';
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.fillText(message, width / 2, height / 2);
                    ctx.restore();
                }

                function renderSecondary() {
                    if (typeof Chart === 'undefined') return;
                    const pieOptions = {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '58%',
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: (ctx) => {
                                        const total = sum(ctx.dataset.data);
                                        return `${ctx.label}: ${money(ctx.parsed)} (${percent(ctx.parsed, total)}%)`;
                                    },
                                },
                            },
                        },
                    };

                    const expEl = document.getElementById('secondaryExpenseChart');
                    if (expEl && expenseSplit.values?.length) {
                        if (secondaryExpense) secondaryExpense.destroy();
                        secondaryExpense = new Chart(expEl, {
                            type: 'doughnut',
                            plugins: [doughnutTotal],
                            data: {
                                labels: expenseSplit.labels,
                                datasets: [{
                                    data: expenseSplit.values,
                                    backgroundColor: expenseSplit.colors,
                                    borderWidth: 2,
                                    borderColor: '#fff',
                                }],
                            },
                            options: pieOptions,
                        });
                    }

                    const incEl = document.getElementById('secondaryIncomeChart');
                    if (incEl && incomeSplit.values?.length) {
                        if (secondaryIncome) secondaryIncome.destroy();
                        secondaryIncome = new Chart(incEl, {
                            type: 'doughnut',
                            plugins: [doughnutTotal],
                            data: {
                                labels: incomeSplit.labels,
                                datasets: [{
                                    data: incomeSplit.values,
                                    backgroundColor: incomeSplit.colors,
                                    borderWidth: 2,
                                    borderColor: '#fff',
                                }],
                            },
                            options: pieOptions,
                        });
                    }
                }

                function resizeCharts() {
                    if (mainChart) mainChart.resize();
                    if (secondaryExpense) secondaryExpense.resize();
                    if (secondaryIncome) secondaryIncome.resize();
                }

                // Desktop only. Below 1024px the widgets are a CSS-stacked, tabbed
                // list, and skipping init is also what guarantees no mobile code
                // path can call grid.save() and clobber the saved desktop layout.
                function initGrid() {
                    if (typeof GridStack === 'undefined' || grid) return;
                    if (! desktopQuery.matches) return;

                    const state = loadState();

                    grid = GridStack.init({
                        column: 12,
                        cellHeight: 80,
                        margin: 8,
                        float: false,
                        animate: true,
                        handle: '.dashboard-drag-handle',
                        alwaysShowResizeHandle: 'mobile',
                    }, '#dashboardGrid');

                    if (Array.isArray(state.layout) && state.layout.length) {
                        grid.load(state.layout);
                    }

                    const persist = () => {
                        saveState({ layout: grid.save(false) });
                        resizeCharts();
                    };

                    grid.on('change', persist);
                    grid.on('resizestop', () => {
                        persist();
                        setTimeout(resizeCharts, 50);
                    });
                    grid.on('dragstop', persist);
                }

                // A tab switch reveals a panel that was display:none, where any
                // chart inside it measured zero. Chart.js re-measures from the
                // container, so resize() is enough - no re-render needed.
                window.addEventListener('dashboard:panel-shown', function () {
                    resizeCharts();
                    setTimeout(resizeCharts, 60);
                });

                let resizeTimer = null;
                window.addEventListener('resize', function () {
                    clearTimeout(resizeTimer);
                    resizeTimer = setTimeout(resizeCharts, 120);
                });

                // The breakpoint used to be read once at boot, so rotating a
                // tablet left the grid in whichever mode it happened to start in.
                desktopQuery.addEventListener('change', function (event) {
                    if (event.matches) {
                        initGrid();
                    } else if (grid) {
                        grid.destroy(false); // Tear down GridStack, keep the DOM.
                        grid = null;
                    }

                    setTimeout(resizeCharts, 60);
                });

                const metricSelect = document.getElementById('mainMetric');
                const state = loadState();
                const initialMetric = ['cashflow', 'expense', 'income', 'bottlenecks'].includes(state.metric)
                    ? state.metric
                    : 'cashflow';

                if (metricSelect) {
                    metricSelect.value = initialMetric;

                    metricSelect.addEventListener('change', function () {
                        saveState({ metric: this.value });
                        renderMain(this.value);
                    });
                }

                document.getElementById('resetDashboardLayout')?.addEventListener('click', function () {
                    localStorage.removeItem(STORAGE_KEY);
                    window.location.reload();
                });

                initGrid();
                renderMain(initialMetric);
                renderSecondary();
                setTimeout(resizeCharts, 100);
            });
        </script>
    @endpush
</x-app-layout>
