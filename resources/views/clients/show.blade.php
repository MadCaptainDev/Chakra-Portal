<x-app-layout title="Client">
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
        <x-card class="p-4 sm:p-6 border border-brand-100/40">
            <h3 class="font-semibold text-brand-900 mb-3">Contact Info</h3>
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
                @if ($ventureLabel)
                    <div>
                        <dt class="text-gray-500">Timesheet venture</dt>
                        <dd class="text-gray-900">{{ $ventureLabel }}</dd>
                    </div>
                @endif
            </dl>
        </x-card>

        {{-- Production hours against this client --}}
        <div>
            <h3 class="font-semibold text-brand-900 mb-3">Timesheet hours</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <x-card class="p-4 sm:p-5 border border-brand-100/60">
                    <p class="text-xs text-brand-600 uppercase tracking-wide font-semibold">Total logged</p>
                    <p class="text-2xl font-bold text-brand-900 mt-1">{{ \App\Models\TimesheetEntry::formatMinutes($timesheet['minutes']) }}</p>
                    <p class="text-xs text-gray-500 mt-1">{{ $timesheet['entries'] }} {{ Str::plural('entry', $timesheet['entries']) }}</p>
                </x-card>
                <x-charts.horizontal-bars
                    :items="collect($timesheet['byType'])->map(fn ($row) => ['label' => $row['label'], 'minutes' => $row['minutes']])->all()"
                    :max-minutes="max(1, collect($timesheet['byType'])->max('minutes') ?: 0)"
                    title="By type"
                    :limit="4"
                    :linkable="false"
                    empty="No timesheet hours for this client yet."
                />
            </div>
        </div>

        {{-- Invoice history --}}
        <div>
            <h3 class="font-semibold text-brand-900 mb-3">Invoice History</h3>
            @if ($invoices->isEmpty())
                <x-empty-state message="No invoices for this client yet." />
            @else
                {{-- Mobile: card list --}}
                <div class="md:hidden space-y-3">
                    @foreach ($invoices as $invoice)
                        <a href="{{ route('invoices.show', $invoice) }}" class="block bg-white shadow-sm rounded-lg p-4 border border-brand-100/40">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-semibold text-gray-900">{{ $invoice->invoice_number ?? 'Pending' }}</span>
                                <x-badge :status="$invoice->displayStatus()" />
                            </div>
                            <div class="mt-1 flex items-center justify-between text-sm text-gray-500">
                                <span>{{ $invoice->invoice_date->format('d/m/Y') }}</span>
                                <span class="text-gray-900 font-semibold">{{ number_format($invoice->total, 2) }}</span>
                            </div>
                        </a>
                    @endforeach
                </div>

                {{-- Desktop: table --}}
                <x-card class="hidden md:block overflow-x-auto border border-brand-100/40">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-brand-50/50">
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
                                    <td class="px-6 py-4 text-sm"><x-badge :status="$invoice->displayStatus()" /></td>
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
