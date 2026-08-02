<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="$client->name">
            <x-slot name="actions">
                <a href="{{ route('clients.edit', $client) }}" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-brand-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-brand-500">
                    Edit Client
                </a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-6">
        {{-- Contact info --}}
        <x-card class="p-4 sm:p-6">
            <h3 class="font-semibold text-gray-900 mb-3">Contact Info</h3>
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-gray-500">Address</dt>
                    <dd class="text-gray-900">{{ $client->address ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Email</dt>
                    <dd class="text-gray-900">{{ $client->email ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Phone</dt>
                    <dd class="text-gray-900">{{ $client->phone ?: '—' }}</dd>
                </div>
            </dl>
        </x-card>

        {{-- Invoice history --}}
        <div>
            <h3 class="font-semibold text-gray-900 mb-3">Invoice History</h3>
            @if ($invoices->isEmpty())
                <x-empty-state message="No invoices for this client yet." />
            @else
                {{-- Mobile: card list --}}
                <div class="md:hidden space-y-3">
                    @foreach ($invoices as $invoice)
                        <a href="{{ route('invoices.show', $invoice) }}" class="block bg-white shadow-sm rounded-lg p-4">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-semibold text-gray-900">{{ $invoice->invoice_number ?? 'Pending' }}</span>
                                <x-badge :status="$invoice->isOverdue() ? 'overdue' : $invoice->status" />
                            </div>
                            <div class="mt-1 flex items-center justify-between text-sm text-gray-500">
                                <span>{{ $invoice->invoice_date->format('d/m/Y') }}</span>
                                <span class="text-gray-900 font-semibold">{{ number_format($invoice->total, 2) }}</span>
                            </div>
                        </a>
                    @endforeach
                </div>

                {{-- Desktop: table --}}
                <x-card class="hidden md:block overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Invoice #</th>
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
        </div>

    </div>
</x-app-layout>
