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
            'overdue' => 'Overdue',
            'paid' => 'Paid',
        ];
    @endphp

    <div class="space-y-4">
        <div class="flex flex-wrap gap-2">
            @foreach ($statusFilters as $value => $label)
                <a href="{{ route('invoices.index', array_filter(['search' => $search, 'status' => $value])) }}"
                   class="px-3 py-1.5 rounded-full text-xs font-semibold {{ $status === $value ? 'bg-brand-400 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>

        <form method="GET" action="{{ route('invoices.index') }}">
            <input type="hidden" name="status" value="{{ $status }}">
            <input type="text" name="search" value="{{ $search }}" placeholder="Search by invoice number or client name..."
                class="w-full sm:max-w-md rounded-md border-gray-300 shadow-sm focus:border-brand-400 focus:ring-brand-400 min-h-[44px]">
        </form>

        @forelse ($invoices as $invoice)
        @empty
            <x-empty-state message="No invoices match this filter.">
                <a href="{{ route('invoices.create') }}" class="text-brand-500 font-semibold text-sm hover:text-brand-600">Create an invoice &rarr;</a>
            </x-empty-state>
        @endforelse

        @if ($invoices->isNotEmpty())
            {{-- Mobile: card list --}}
            <div class="md:hidden space-y-3">
                @foreach ($invoices as $invoice)
                    <a href="{{ route('invoices.show', $invoice) }}" class="block bg-white shadow-sm rounded-lg p-4">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-semibold text-gray-900">{{ $invoice->invoice_number ?? 'Pending' }}</span>
                            <x-badge :status="$invoice->isOverdue() ? 'overdue' : $invoice->status" />
                        </div>
                        <div class="mt-1 flex items-center justify-between text-sm text-gray-500">
                            <span>{{ $invoice->client->name }}</span>
                            <span>{{ $invoice->invoice_date->format('d/m/Y') }}</span>
                        </div>
                        <div class="mt-1 text-right text-gray-900 font-semibold">{{ number_format($invoice->total, 2) }}</div>
                    </a>
                @endforeach
            </div>

            {{-- Desktop: table --}}
            <x-card class="hidden md:block overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoice #</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Client</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach ($invoices as $invoice)
                            <tr>
                                <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $invoice->invoice_number ?? 'Pending' }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ $invoice->client->name }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ $invoice->invoice_date->format('d/m/Y') }}</td>
                                <td class="px-6 py-4 text-sm"><x-badge :status="$invoice->isOverdue() ? 'overdue' : $invoice->status" /></td>
                                <td class="px-6 py-4 text-sm text-gray-900 text-right">{{ number_format($invoice->total, 2) }}</td>
                                <td class="px-6 py-4 text-right text-sm">
                                    <a href="{{ route('invoices.show', $invoice) }}" class="text-brand-500 hover:text-brand-600 font-semibold">View</a>
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
</x-app-layout>
