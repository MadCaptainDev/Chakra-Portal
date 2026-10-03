@php
    use App\Support\InvoiceFilters;

    $f = $filters;
    $url = fn (array $changes = []) => route('invoices.index', $f->params($changes));
    $money = fn (float $v) => '₹'.number_format($v, 0);
    $pdfUrls = $invoices->mapWithKeys(fn ($invoice) => [$invoice->id => route('invoices.pdf', $invoice)])->all();

    // Removable pills for whatever is narrowing the list right now.
    $pills = array_filter([
        $client ? ['Client: '.$client->name, ['client' => null] + ($f->period === InvoiceFilters::PERIOD_ALL ? ['period' => InvoiceFilters::PERIOD_MONTH] : [])] : null,
        $f->status !== '' ? [InvoiceFilters::STATUSES[$f->status], ['status' => null]] : null,
        $f->type !== '' ? [InvoiceFilters::TYPES[$f->type], ['type' => null]] : null,
        $f->min !== null || $f->max !== null
            ? [match (true) {
                $f->min !== null && $f->max !== null => $money($f->min).' – '.$money($f->max),
                $f->min !== null => 'From '.$money($f->min),
                default => 'Up to '.$money($f->max),
            }, ['min' => null, 'max' => null]]
            : null,
        $f->search !== '' ? ['“'.$f->search.'”', ['search' => null]] : null,
        $f->sort !== 'newest' ? ['Sort: '.InvoiceFilters::SORTS[$f->sort], ['sort' => null]] : null,
    ]);
@endphp

<x-app-layout title="Invoices">
    <x-slot name="header">
        <x-page-header title="Invoices" eyebrow="Finance"
                       subtitle="{{ $client ? 'Everything billed to '.$client->name.'.' : 'What was billed, and what still needs collecting.' }}">
            <x-slot name="actions">
                <x-btn :href="route('invoices.export', $f->params())" variant="secondary" icon="document">Export CSV</x-btn>
                <x-btn :href="route('invoices.create')" icon="plus">New invoice</x-btn>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div
        class="space-y-4"
        x-data="invoiceIndexSelection({
            allIds: @js($invoices->pluck('id')->values()),
            pdfUrls: @js($pdfUrls),
            downloadUrl: @js(route('invoices.download-pdfs')),
            csrf: @js(csrf_token()),
        })"
    >
        {{-- ═════════ Filters ═════════ --}}
        <form method="GET" action="{{ route('invoices.index') }}"
              x-data="{ more: {{ $f->advancedCount() > 0 ? 'true' : 'false' }}, period: @js($f->period) }">
            <x-filter-bar>
                {{-- Period: month with ‹ ›, a range, or all time. --}}
                <div class="flex items-center justify-between gap-2">
                    @if ($f->period === InvoiceFilters::PERIOD_MONTH)
                        <a href="{{ $url(['month' => $f->month->copy()->subMonthNoOverflow()->format('Y-m')]) }}"
                           class="inline-flex items-center min-h-[44px] px-3 rounded-lg bg-white/5 ring-1 ring-white/15 text-sm font-semibold text-brand-100/80 hover:bg-white/[0.09]" aria-label="Previous month">&larr;<span class="hidden sm:inline ml-1">Prev</span></a>
                    @else
                        <span class="w-[44px]"></span>
                    @endif

                    <div class="text-center min-w-0">
                        <p class="font-semibold text-white truncate">{{ $f->periodLabel() }}</p>
                        @if ($f->period === InvoiceFilters::PERIOD_MONTH && ! $f->month->isSameMonth(now()))
                            <a href="{{ $url(['month' => now()->format('Y-m')]) }}" class="text-xs text-brand-400 hover:text-brand-300">Back to this month</a>
                        @elseif ($f->period !== InvoiceFilters::PERIOD_MONTH)
                            <a href="{{ $url(['period' => InvoiceFilters::PERIOD_MONTH, 'month' => now()->format('Y-m')]) }}" class="text-xs text-brand-400 hover:text-brand-300">Show this month only</a>
                        @endif
                    </div>

                    @if ($f->period === InvoiceFilters::PERIOD_MONTH)
                        <a href="{{ $url(['month' => $f->month->copy()->addMonthNoOverflow()->format('Y-m')]) }}"
                           class="inline-flex items-center min-h-[44px] px-3 rounded-lg bg-white/5 ring-1 ring-white/15 text-sm font-semibold text-brand-100/80 hover:bg-white/[0.09]" aria-label="Next month"><span class="hidden sm:inline mr-1">Next</span>&rarr;</a>
                    @else
                        <span class="w-[44px]"></span>
                    @endif
                </div>

                {{-- Client + search: the two things typed or picked most. --}}
                <div class="grid gap-2 sm:grid-cols-[minmax(0,260px)_minmax(0,1fr)_auto]">
                    <label class="sr-only" for="invoice-client">Client</label>
                    <x-select id="invoice-client" name="client"
                              x-on:change="period = $event.target.value ? 'all' : 'month'; $nextTick(() => $el.form.requestSubmit())">
                        <option value="">All clients</option>
                        @foreach ($clients as $c)
                            <option value="{{ $c->id }}" @selected($f->clientId === $c->id)>{{ $c->name }}</option>
                        @endforeach
                    </x-select>

                    <label class="sr-only" for="invoice-search">Search</label>
                    <input id="invoice-search" type="search" name="search" value="{{ $f->search }}" placeholder="Invoice number or client name…"
                           class="w-full rounded-md bg-white/5 border-white/15 text-white placeholder:text-brand-100/40 focus:border-brand-400 focus:ring-brand-400 min-h-[44px]">

                    <button type="button" @click="more = ! more" :aria-expanded="more"
                            class="inline-flex items-center justify-center gap-2 min-h-[44px] px-4 rounded-md ring-1 text-sm font-semibold transition"
                            :class="more ? 'bg-white text-brand-900 ring-white' : 'bg-white/5 text-brand-100/80 ring-white/15 hover:bg-white/10'">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M4 6h16M7 12h10M10 18h4" /></svg>
                        More filters
                        @if ($f->advancedCount() > 0)
                            <span class="min-w-[20px] h-5 px-1.5 rounded-full bg-brand-400 text-brand-900 text-[11px] leading-5 font-bold">{{ $f->advancedCount() }}</span>
                        @endif
                    </button>
                </div>

                {{-- Status: the filter used most, kept one tap away. --}}
                <div class="flex gap-2 overflow-x-auto -mx-1 px-1 pb-0.5">
                    @foreach (InvoiceFilters::STATUSES as $value => $label)
                        <a href="{{ $url(['status' => $value]) }}"
                           class="shrink-0 px-3 py-1.5 rounded-lg text-xs font-semibold {{ $f->status === $value ? 'bg-brand-400 text-brand-900' : 'bg-white/10 text-brand-100/70 hover:bg-white/[0.16]' }}">
                            {{ $label }}
                        </a>
                    @endforeach
                </div>

                {{-- ── More filters ── --}}
                <div x-show="more" x-cloak x-transition.opacity class="rounded-xl bg-brand-900/50 ring-1 ring-white/10 p-3 sm:p-4 space-y-4">
                    <input type="hidden" name="status" value="{{ $f->status }}">

                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-brand-100/60 mb-2">Period</p>
                        <div class="inline-flex rounded-lg bg-white/5 ring-1 ring-white/10 p-1">
                            @foreach ([InvoiceFilters::PERIOD_MONTH => 'One month', InvoiceFilters::PERIOD_RANGE => 'Date range', InvoiceFilters::PERIOD_ALL => 'All time'] as $value => $label)
                                <label class="cursor-pointer">
                                    <input type="radio" name="period" value="{{ $value }}" x-model="period" class="sr-only">
                                    <span class="inline-flex items-center min-h-[36px] px-3 rounded-md text-xs font-semibold transition"
                                          :class="period === '{{ $value }}' ? 'bg-white text-brand-900' : 'text-brand-100/70 hover:text-white'">{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>

                        <div class="mt-3 grid gap-2 sm:grid-cols-2 sm:max-w-md" x-show="period === 'month'">
                            <div>
                                <label class="block text-xs text-brand-100/60 mb-1" for="invoice-month">Month</label>
                                <input id="invoice-month" type="month" name="month" value="{{ $f->month->format('Y-m') }}" :disabled="period !== 'month'"
                                       class="w-full rounded-md bg-white/5 border-white/15 text-white min-h-[44px] [color-scheme:dark]">
                            </div>
                        </div>

                        <div class="mt-3 grid gap-2 grid-cols-2 sm:max-w-md" x-show="period === 'range'" x-cloak>
                            <div>
                                <label class="block text-xs text-brand-100/60 mb-1" for="invoice-from">From</label>
                                <input id="invoice-from" type="date" name="from" value="{{ $f->from?->toDateString() }}" :disabled="period !== 'range'"
                                       class="w-full rounded-md bg-white/5 border-white/15 text-white min-h-[44px] [color-scheme:dark]">
                            </div>
                            <div>
                                <label class="block text-xs text-brand-100/60 mb-1" for="invoice-to">To</label>
                                <input id="invoice-to" type="date" name="to" value="{{ $f->to?->toDateString() }}" :disabled="period !== 'range'"
                                       class="w-full rounded-md bg-white/5 border-white/15 text-white min-h-[44px] [color-scheme:dark]">
                            </div>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-2" x-show="period === 'range'" x-cloak>
                            @foreach ([
                                'This financial year' => [now()->month >= 4 ? now()->startOfYear()->addMonths(3) : now()->subYear()->startOfYear()->addMonths(3), now()],
                                'Last 3 months' => [now()->subMonthsNoOverflow(2)->startOfMonth(), now()],
                                'Last month' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
                            ] as $label => [$from, $to])
                                <a href="{{ $url(['period' => InvoiceFilters::PERIOD_RANGE, 'from' => $from->toDateString(), 'to' => $to->toDateString()]) }}"
                                   class="px-2.5 py-1 rounded-md bg-white/10 text-[11px] font-semibold text-brand-100/80 hover:bg-white/[0.16]">{{ $label }}</a>
                            @endforeach
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <div>
                            <label class="block text-[11px] font-semibold uppercase tracking-wider text-brand-100/60 mb-1" for="invoice-type">Work</label>
                            <x-select id="invoice-type" name="type">
                                @foreach (InvoiceFilters::TYPES as $value => $label)
                                    <option value="{{ $value }}" @selected($f->type === $value)>{{ $label }}</option>
                                @endforeach
                            </x-select>
                        </div>

                        <div>
                            <p class="block text-[11px] font-semibold uppercase tracking-wider text-brand-100/60 mb-1">Amount (₹)</p>
                            <div class="flex items-center gap-2">
                                <input type="number" inputmode="decimal" min="0" step="any" name="min" value="{{ $f->min }}" placeholder="Min" aria-label="Minimum amount"
                                       class="w-full rounded-md bg-white/5 border-white/15 text-white placeholder:text-brand-100/40 min-h-[44px]">
                                <span class="text-brand-100/40">–</span>
                                <input type="number" inputmode="decimal" min="0" step="any" name="max" value="{{ $f->max }}" placeholder="Max" aria-label="Maximum amount"
                                       class="w-full rounded-md bg-white/5 border-white/15 text-white placeholder:text-brand-100/40 min-h-[44px]">
                            </div>
                        </div>

                        <div>
                            <label class="block text-[11px] font-semibold uppercase tracking-wider text-brand-100/60 mb-1" for="invoice-sort">Sort by</label>
                            <x-select id="invoice-sort" name="sort">
                                @foreach (InvoiceFilters::SORTS as $value => $label)
                                    <option value="{{ $value }}" @selected($f->sort === $value)>{{ $label }}</option>
                                @endforeach
                            </x-select>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-1">
                        <a href="{{ route('invoices.index') }}" class="inline-flex items-center min-h-[44px] px-3 text-sm font-semibold text-brand-100/60 hover:text-white">Reset all</a>
                        <x-btn type="submit">Apply filters</x-btn>
                    </div>
                </div>

                {{-- What is narrowing the list, each removable. --}}
                @if ($pills !== [])
                    <div class="flex flex-wrap items-center gap-2">
                        @foreach ($pills as [$label, $remove])
                            <a href="{{ $url($remove) }}"
                               class="inline-flex items-center gap-1.5 pl-3 pr-2 py-1 rounded-full bg-brand-400/15 ring-1 ring-brand-400/30 text-xs font-semibold text-brand-100 hover:bg-brand-400/25"
                               title="Remove this filter">
                                {{ $label }}
                                <span class="w-4 h-4 rounded-full bg-white/10 flex items-center justify-center text-[10px] leading-none">✕</span>
                            </a>
                        @endforeach
                        @if (count($pills) > 1)
                            <a href="{{ route('invoices.index') }}" class="text-xs font-semibold text-brand-100/50 hover:text-white">Clear all</a>
                        @endif
                    </div>
                @endif
            </x-filter-bar>
        </form>

        {{-- ═════════ Money for exactly what is filtered ═════════ --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <x-card padding="sm">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-brand-100/60">Invoiced</p>
                <p class="mt-1 text-xl font-bold text-white tabular-nums">{{ $money($summary['invoiced']) }}</p>
                <p class="text-xs text-brand-100/50">{{ $summary['count'] }} {{ Str::plural('invoice', $summary['count']) }}</p>
            </x-card>
            <x-card padding="sm">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-brand-100/60">Collected</p>
                <p class="mt-1 text-xl font-bold text-emerald-300 tabular-nums">{{ $money($summary['collected']) }}</p>
                <p class="text-xs text-brand-100/50">
                    {{ $summary['invoiced'] > 0 ? round($summary['collected'] / $summary['invoiced'] * 100) : 0 }}% of invoiced
                </p>
            </x-card>
            <a href="{{ $url(['status' => 'unpaid']) }}" class="block">
                <x-card padding="sm" interactive>
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-brand-100/60">Outstanding</p>
                    <p class="mt-1 text-xl font-bold tabular-nums {{ $summary['outstanding'] > 0 ? 'text-amber-300' : 'text-white' }}">{{ $money($summary['outstanding']) }}</p>
                    <p class="text-xs text-brand-100/50">still to collect</p>
                </x-card>
            </a>
            <a href="{{ $url(['status' => 'overdue']) }}" class="block">
                <x-card padding="sm" interactive>
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-brand-100/60">Overdue</p>
                    <p class="mt-1 text-xl font-bold tabular-nums {{ $summary['overdue'] > 0 ? 'text-red-300' : 'text-white' }}">{{ $money($summary['overdue']) }}</p>
                    <p class="text-xs text-brand-100/50">past the due date</p>
                </x-card>
            </a>
        </div>

        @forelse ($invoices as $invoice)
        @empty
            <x-empty-state message="No invoices {{ $f->period === InvoiceFilters::PERIOD_ALL ? '' : 'in '.$f->periodLabel() }}{{ $f->isFiltered() ? ' for these filters' : '' }}.">
                @if ($f->isFiltered() || $f->period !== InvoiceFilters::PERIOD_ALL)
                    <x-btn :href="$url(['period' => InvoiceFilters::PERIOD_ALL])" variant="secondary" size="sm">Search all time</x-btn>
                @endif
                <x-btn :href="route('invoices.create')" icon="plus" size="sm">Create an invoice</x-btn>
            </x-empty-state>
        @endforelse

        @if ($invoices->isNotEmpty())
            {{-- Bulk actions --}}
            <div
                class="flex flex-col sm:flex-row sm:items-center gap-3 rounded-lg border border-white/10 bg-white/5 p-3 shadow-sm"
                x-show="selected.length > 0"
                x-cloak
            >
                <p class="text-sm text-brand-100/80">
                    <span class="font-semibold" x-text="selected.length"></span>
                    selected
                </p>
                <div class="flex flex-1 flex-col sm:flex-row sm:items-center gap-2 sm:justify-end">
                    <label class="sr-only" for="invoice-bulk-action">Bulk action</label>
                    <select
                        id="invoice-bulk-action"
                        x-model="action"
                        @change="runAction()"
                        class="w-full sm:w-auto min-h-[44px] rounded-md border-white/15 shadow-sm focus:border-brand-400 focus:ring-brand-400 text-sm"
                    >
                        <option value="">Select action…</option>
                        <option value="download-pdf">Download PDF</option>
                    </select>
                    <button
                        type="button"
                        @click="clearSelection()"
                        class="inline-flex items-center justify-center min-h-[44px] px-3 rounded-md border border-white/15 bg-white/5 text-sm font-semibold text-brand-100/80 hover:bg-white/[0.09]"
                    >
                        Clear
                    </button>
                </div>
                <p class="text-xs text-brand-100/60 sm:w-full" x-show="busy" x-cloak>Preparing download…</p>
            </div>

            {{-- Mobile: card list --}}
            <div class="md:hidden space-y-3">
                <label class="flex items-center gap-3 min-h-[44px] px-1 text-sm font-semibold text-brand-100/80">
                    <input
                        type="checkbox"
                        class="h-5 w-5 rounded border-white/15 text-brand-400 focus:ring-brand-400"
                        :checked="allSelected"
                        :indeterminate="partiallySelected"
                        @change="toggleAll($event.target.checked)"
                    >
                    Select all on this page
                </label>

                @foreach ($invoices as $invoice)
                    <div class="bg-white/5 shadow-sm rounded-lg p-4">
                        <div class="flex items-start gap-3">
                            <label class="inline-flex items-center justify-center min-h-[44px] min-w-[44px] -ml-1">
                                <input
                                    type="checkbox"
                                    class="h-5 w-5 rounded border-white/15 text-brand-400 focus:ring-brand-400"
                                    value="{{ $invoice->id }}"
                                    :checked="isSelected({{ $invoice->id }})"
                                    @change="toggle({{ $invoice->id }}, $event.target.checked)"
                                >
                            </label>
                            <a href="{{ route('invoices.show', $invoice) }}" class="min-w-0 flex-1">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="font-semibold text-white">{{ $invoice->invoice_number ?? 'Pending' }}</span>
                                    <x-badge :status="$invoice->displayStatus()" />
                                </div>
                                <div class="mt-1 flex items-center justify-between text-sm text-brand-100/60">
                                    <span>
                                        {{ $invoice->client->name }}
                                        @if ($invoice->saasProduct)
                                            <span class="ml-1 px-1.5 py-0.5 rounded text-[10px] font-semibold uppercase tracking-wider bg-indigo-400/15 text-indigo-200">
                                                {{ $invoice->saas_invoice_type === \App\Models\Invoice::STUDIO_TYPE_AMC ? 'AMC' : 'DEV' }}
                                            </span>
                                        @endif
                                    </span>
                                    <span>{{ $invoice->invoice_date->format('d/m/Y') }}</span>
                                </div>
                                <div class="mt-1 text-right text-white font-semibold">
                                    {{ number_format($invoice->total, 2) }}
                                    @if ($invoice->isPartiallyPaid())
                                        <span class="block text-xs font-semibold text-amber-200">
                                            {{ number_format($invoice->paidTotal(), 2) }} paid &middot;
                                            {{ number_format($invoice->balanceDue(), 2) }} due
                                        </span>
                                    @endif
                                </div>
                            </a>
                        </div>
                        <div class="mt-3 flex justify-end gap-2 border-t border-white/10 pt-3">
                            <a href="{{ route('invoices.pdf', $invoice) }}"
                               class="inline-flex items-center justify-center min-h-[44px] px-3 rounded-md bg-brand-400 text-xs font-semibold uppercase tracking-widest text-brand-900 hover:bg-brand-500">
                                PDF
                            </a>
                            <a href="{{ route('invoices.show', $invoice) }}"
                               class="inline-flex items-center justify-center min-h-[44px] px-3 rounded-md border border-white/15 bg-white/5 text-xs font-semibold uppercase tracking-widest text-brand-100/80 hover:bg-white/[0.09]">
                                View
                            </a>
                        </div>
                    </div>
                @endforeach

                <div class="bg-white/5 shadow-sm rounded-lg p-4 flex items-center justify-between">
                    <p class="text-sm font-semibold text-brand-100/80">Sum</p>
                    <p class="text-white font-semibold">{{ number_format($summary["invoiced"], 2) }}</p>
                </div>
            </div>

            {{-- Desktop: table --}}
            <x-card class="hidden md:block overflow-x-auto">
                <table class="min-w-full divide-y divide-white/10">
                    <thead class="bg-brand-900/40">
                        <tr>
                            <th class="px-4 py-3 w-12">
                                <label class="inline-flex items-center justify-center min-h-[44px] min-w-[44px]">
                                    <span class="sr-only">Select all</span>
                                    <input
                                        type="checkbox"
                                        class="h-5 w-5 rounded border-white/15 text-brand-400 focus:ring-brand-400"
                                        :checked="allSelected"
                                        :indeterminate="partiallySelected"
                                        @change="toggleAll($event.target.checked)"
                                    >
                                </label>
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Invoice #</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Client</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Status</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-brand-100/60 uppercase">Total</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-brand-100/60 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @foreach ($invoices as $invoice)
                            <tr class="hover:bg-white/[0.09]" :class="isSelected({{ $invoice->id }}) ? 'bg-white/5' : ''">
                                <td class="px-4 py-4">
                                    <label class="inline-flex items-center justify-center min-h-[44px] min-w-[44px]">
                                        <input
                                            type="checkbox"
                                            class="h-5 w-5 rounded border-white/15 text-brand-400 focus:ring-brand-400"
                                            value="{{ $invoice->id }}"
                                            :checked="isSelected({{ $invoice->id }})"
                                            @change="toggle({{ $invoice->id }}, $event.target.checked)"
                                        >
                                    </label>
                                </td>
                                <td class="px-6 py-4 text-sm font-medium text-white">{{ $invoice->invoice_number ?? 'Pending' }}</td>
                                <td class="px-6 py-4 text-sm text-brand-100/60">
                                    {{ $invoice->client->name }}
                                    @if ($invoice->saasProduct)
                                        <span class="ml-1 px-1.5 py-0.5 rounded text-[10px] font-semibold uppercase tracking-wider bg-indigo-400/15 text-indigo-200">
                                            {{ $invoice->saas_invoice_type === \App\Models\Invoice::STUDIO_TYPE_AMC ? 'AMC' : 'DEV' }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-sm text-brand-100/60">{{ $invoice->invoice_date->format('d/m/Y') }}</td>
                                <td class="px-6 py-4 text-sm"><x-badge :status="$invoice->displayStatus()" /></td>
                                <td class="px-6 py-4 text-sm text-white text-right">
                                    {{ number_format($invoice->total, 2) }}
                                    @if ($invoice->isPartiallyPaid())
                                        <span class="block text-xs font-semibold text-amber-200">
                                            {{ number_format($invoice->paidTotal(), 2) }} paid &middot;
                                            {{ number_format($invoice->balanceDue(), 2) }} due
                                        </span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-right text-sm">
                                    <div class="inline-flex items-center justify-end gap-3">
                                        <a href="{{ route('invoices.pdf', $invoice) }}"
                                           class="inline-flex items-center justify-center min-h-[44px] px-2 font-semibold text-brand-500 hover:text-brand-300"
                                           title="Download PDF">
                                            PDF
                                        </a>
                                        <a href="{{ route('invoices.show', $invoice) }}" class="inline-flex items-center justify-center min-h-[44px] px-2 text-brand-500 hover:text-brand-300 font-semibold">View</a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="bg-brand-900/40">
                            <td colspan="5" class="px-6 py-3 text-right text-xs font-semibold uppercase tracking-wide text-brand-100/60">Sum</td>
                            <td class="px-6 py-3 text-sm font-bold text-white text-right">{{ number_format($summary["invoiced"], 2) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </x-card>
        @endif

        <div>
            {{ $invoices->links() }}
        </div>
    </div>

    <script>
        function invoiceIndexSelection({ allIds, pdfUrls, downloadUrl, csrf }) {
            return {
                allIds: (allIds || []).map(Number),
                pdfUrls: pdfUrls || {},
                downloadUrl,
                csrf,
                selected: [],
                action: '',
                busy: false,

                get allSelected() {
                    return this.allIds.length > 0 && this.selected.length === this.allIds.length;
                },

                get partiallySelected() {
                    return this.selected.length > 0 && this.selected.length < this.allIds.length;
                },

                isSelected(id) {
                    return this.selected.includes(Number(id));
                },

                toggle(id, checked) {
                    id = Number(id);
                    if (checked) {
                        if (! this.selected.includes(id)) {
                            this.selected.push(id);
                        }
                    } else {
                        this.selected = this.selected.filter((value) => value !== id);
                    }
                },

                toggleAll(checked) {
                    this.selected = checked ? [...this.allIds] : [];
                },

                clearSelection() {
                    this.selected = [];
                    this.action = '';
                    this.busy = false;
                },

                runAction() {
                    if (this.action !== 'download-pdf' || this.selected.length === 0 || this.busy) {
                        this.action = '';
                        return;
                    }

                    this.busy = true;

                    if (this.selected.length === 1) {
                        const url = this.pdfUrls[this.selected[0]];
                        if (url) {
                            window.location.href = url;
                        }
                        this.action = '';
                        this.busy = false;
                        return;
                    }

                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = this.downloadUrl;
                    form.style.display = 'none';

                    const token = document.createElement('input');
                    token.type = 'hidden';
                    token.name = '_token';
                    token.value = this.csrf;
                    form.appendChild(token);

                    this.selected.forEach((id) => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'ids[]';
                        input.value = String(id);
                        form.appendChild(input);
                    });

                    document.body.appendChild(form);
                    form.submit();
                    form.remove();

                    this.action = '';
                    window.setTimeout(() => { this.busy = false; }, 1500);
                },
            };
        }
    </script>
</x-app-layout>
