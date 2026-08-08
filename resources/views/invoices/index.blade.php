@php
    $prev = $month->copy()->subMonthNoOverflow()->format('Y-m');
    $next = $month->copy()->addMonthNoOverflow()->format('Y-m');
    $monthParam = $month->format('Y-m');
    $pdfUrls = $invoices->mapWithKeys(fn ($invoice) => [$invoice->id => route('invoices.pdf', $invoice)])->all();
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Invoices">
            <x-slot name="actions">
                <a href="{{ route('invoices.create') }}" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-brand-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-brand-500">
                    + New Invoice
                </a>
            </x-slot>
        </x-page-header>
    </x-slot>

    @php
        $statusFilters = [
            '' => 'All',
            'pending_approval' => 'Pending Approval',
            'unpaid' => 'Unpaid',
            'partial' => 'Partially Paid',
            'overdue' => 'Overdue',
            'paid' => 'Paid',
        ];
    @endphp

    <div
        class="space-y-4"
        x-data="invoiceIndexSelection({
            allIds: @js($invoices->pluck('id')->values()),
            pdfUrls: @js($pdfUrls),
            downloadUrl: @js(route('invoices.download-pdfs')),
            csrf: @js(csrf_token()),
        })"
    >
        <div class="flex items-center justify-between gap-2">
            <a href="{{ route('invoices.index', array_filter(['month' => $prev, 'status' => $status, 'search' => $search])) }}"
               class="inline-flex items-center min-h-[44px] px-3 rounded-md bg-white border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50">&larr; Prev</a>

            <div class="text-center">
                <p class="font-semibold text-gray-900">{{ $month->format('F Y') }}</p>
                <p class="text-xs text-gray-500">
                    {{ $invoices->total() }} {{ Str::plural('invoice', $invoices->total()) }}
                    &middot; {{ number_format($monthTotal, 2) }} invoiced
                </p>
                @if (! $month->isSameMonth(now()))
                    <a href="{{ route('invoices.index', array_filter(['status' => $status, 'search' => $search])) }}"
                       class="text-xs text-brand-500 hover:text-brand-600">Back to this month</a>
                @endif
            </div>

            <a href="{{ route('invoices.index', array_filter(['month' => $next, 'status' => $status, 'search' => $search])) }}"
               class="inline-flex items-center min-h-[44px] px-3 rounded-md bg-white border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50">Next &rarr;</a>
        </div>

        <div class="flex flex-wrap gap-2">
            @foreach ($statusFilters as $value => $label)
                <a href="{{ route('invoices.index', array_filter(['month' => $monthParam, 'search' => $search, 'status' => $value])) }}"
                   class="px-3 py-1.5 rounded-full text-xs font-semibold {{ $status === $value ? 'bg-brand-400 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>

        <form method="GET" action="{{ route('invoices.index') }}">
            <input type="hidden" name="month" value="{{ $monthParam }}">
            <input type="hidden" name="status" value="{{ $status }}">
            <input type="text" name="search" value="{{ $search }}" placeholder="Search by invoice number or client name..."
                class="w-full sm:max-w-md rounded-md border-gray-300 shadow-sm focus:border-brand-400 focus:ring-brand-400 min-h-[44px]">
        </form>

        @forelse ($invoices as $invoice)
        @empty
            <x-empty-state message="No invoices in {{ $month->format('F Y') }}{{ $status || $search ? ' for this filter' : '' }}.">
                <a href="{{ route('invoices.create') }}" class="text-brand-500 font-semibold text-sm hover:text-brand-600">Create an invoice &rarr;</a>
            </x-empty-state>
        @endforelse

        @if ($invoices->isNotEmpty())
            {{-- Bulk actions --}}
            <div
                class="flex flex-col sm:flex-row sm:items-center gap-3 rounded-lg border border-gray-200 bg-white p-3 shadow-sm"
                x-show="selected.length > 0"
                x-cloak
            >
                <p class="text-sm text-gray-700">
                    <span class="font-semibold" x-text="selected.length"></span>
                    selected
                </p>
                <div class="flex flex-1 flex-col sm:flex-row sm:items-center gap-2 sm:justify-end">
                    <label class="sr-only" for="invoice-bulk-action">Bulk action</label>
                    <select
                        id="invoice-bulk-action"
                        x-model="action"
                        @change="runAction()"
                        class="w-full sm:w-auto min-h-[44px] rounded-md border-gray-300 shadow-sm focus:border-brand-400 focus:ring-brand-400 text-sm"
                    >
                        <option value="">Select action…</option>
                        <option value="download-pdf">Download PDF</option>
                    </select>
                    <button
                        type="button"
                        @click="clearSelection()"
                        class="inline-flex items-center justify-center min-h-[44px] px-3 rounded-md border border-gray-300 bg-white text-sm font-semibold text-gray-700 hover:bg-gray-50"
                    >
                        Clear
                    </button>
                </div>
                <p class="text-xs text-gray-500 sm:w-full" x-show="busy" x-cloak>Preparing download…</p>
            </div>

            {{-- Mobile: card list --}}
            <div class="md:hidden space-y-3">
                <label class="flex items-center gap-3 min-h-[44px] px-1 text-sm font-semibold text-gray-700">
                    <input
                        type="checkbox"
                        class="h-5 w-5 rounded border-gray-300 text-brand-400 focus:ring-brand-400"
                        :checked="allSelected"
                        :indeterminate="partiallySelected"
                        @change="toggleAll($event.target.checked)"
                    >
                    Select all on this page
                </label>

                @foreach ($invoices as $invoice)
                    <div class="bg-white shadow-sm rounded-lg p-4">
                        <div class="flex items-start gap-3">
                            <label class="inline-flex items-center justify-center min-h-[44px] min-w-[44px] -ml-1">
                                <input
                                    type="checkbox"
                                    class="h-5 w-5 rounded border-gray-300 text-brand-400 focus:ring-brand-400"
                                    value="{{ $invoice->id }}"
                                    :checked="isSelected({{ $invoice->id }})"
                                    @change="toggle({{ $invoice->id }}, $event.target.checked)"
                                >
                            </label>
                            <a href="{{ route('invoices.show', $invoice) }}" class="min-w-0 flex-1">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="font-semibold text-gray-900">{{ $invoice->invoice_number ?? 'Pending' }}</span>
                                    <x-badge :status="$invoice->displayStatus()" />
                                </div>
                                <div class="mt-1 flex items-center justify-between text-sm text-gray-500">
                                    <span>{{ $invoice->client->name }}</span>
                                    <span>{{ $invoice->invoice_date->format('d/m/Y') }}</span>
                                </div>
                                <div class="mt-1 text-right text-gray-900 font-semibold">
                                    {{ number_format($invoice->total, 2) }}
                                    @if ($invoice->isPartiallyPaid())
                                        <span class="block text-xs font-semibold text-amber-700">
                                            {{ number_format($invoice->paidTotal(), 2) }} paid &middot;
                                            {{ number_format($invoice->balanceDue(), 2) }} due
                                        </span>
                                    @endif
                                </div>
                            </a>
                        </div>
                        <div class="mt-3 flex justify-end gap-2 border-t border-gray-100 pt-3">
                            <a href="{{ route('invoices.pdf', $invoice) }}"
                               class="inline-flex items-center justify-center min-h-[44px] px-3 rounded-md bg-brand-400 text-xs font-semibold uppercase tracking-widest text-white hover:bg-brand-500">
                                PDF
                            </a>
                            <a href="{{ route('invoices.show', $invoice) }}"
                               class="inline-flex items-center justify-center min-h-[44px] px-3 rounded-md border border-gray-300 bg-white text-xs font-semibold uppercase tracking-widest text-gray-700 hover:bg-gray-50">
                                View
                            </a>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Desktop: table --}}
            <x-card class="hidden md:block overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 w-12">
                                <label class="inline-flex items-center justify-center min-h-[44px] min-w-[44px]">
                                    <span class="sr-only">Select all</span>
                                    <input
                                        type="checkbox"
                                        class="h-5 w-5 rounded border-gray-300 text-brand-400 focus:ring-brand-400"
                                        :checked="allSelected"
                                        :indeterminate="partiallySelected"
                                        @change="toggleAll($event.target.checked)"
                                    >
                                </label>
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoice #</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Client</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach ($invoices as $invoice)
                            <tr class="hover:bg-gray-50" :class="isSelected({{ $invoice->id }}) ? 'bg-brand-50/40' : ''">
                                <td class="px-4 py-4">
                                    <label class="inline-flex items-center justify-center min-h-[44px] min-w-[44px]">
                                        <input
                                            type="checkbox"
                                            class="h-5 w-5 rounded border-gray-300 text-brand-400 focus:ring-brand-400"
                                            value="{{ $invoice->id }}"
                                            :checked="isSelected({{ $invoice->id }})"
                                            @change="toggle({{ $invoice->id }}, $event.target.checked)"
                                        >
                                    </label>
                                </td>
                                <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $invoice->invoice_number ?? 'Pending' }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ $invoice->client->name }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ $invoice->invoice_date->format('d/m/Y') }}</td>
                                <td class="px-6 py-4 text-sm"><x-badge :status="$invoice->displayStatus()" /></td>
                                <td class="px-6 py-4 text-sm text-gray-900 text-right">
                                    {{ number_format($invoice->total, 2) }}
                                    @if ($invoice->isPartiallyPaid())
                                        <span class="block text-xs font-semibold text-amber-700">
                                            {{ number_format($invoice->paidTotal(), 2) }} paid &middot;
                                            {{ number_format($invoice->balanceDue(), 2) }} due
                                        </span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-right text-sm">
                                    <div class="inline-flex items-center justify-end gap-3">
                                        <a href="{{ route('invoices.pdf', $invoice) }}"
                                           class="inline-flex items-center justify-center min-h-[44px] px-2 font-semibold text-brand-500 hover:text-brand-600"
                                           title="Download PDF">
                                            PDF
                                        </a>
                                        <a href="{{ route('invoices.show', $invoice) }}" class="inline-flex items-center justify-center min-h-[44px] px-2 text-brand-500 hover:text-brand-600 font-semibold">View</a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
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
