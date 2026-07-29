@php
    $chartMax = max(1, $monthlyRevenue->max('total'));
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Dashboard" />
    </x-slot>

    <div class="space-y-6">
        @if ($pendingApprovalCount > 0)
            <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <p class="text-sm text-amber-800">
                    <span class="font-semibold">{{ $pendingApprovalCount }}</span>
                    {{ Str::plural('invoice', $pendingApprovalCount) }} waiting for your approval.
                </p>
                <a href="{{ route('invoices.index', ['status' => 'pending_approval']) }}" class="text-sm font-semibold text-amber-800 underline shrink-0">Review now &rarr;</a>
            </div>
        @endif

        <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
            <x-stat-card label="Total Invoiced" value="{{ number_format($totalInvoiced, 2) }}" accent="brand" />
            <x-stat-card label="This Month" value="{{ number_format($thisMonthRevenue, 2) }}" accent="green" />
            <x-stat-card label="Outstanding" value="{{ number_format($outstanding, 2) }}" accent="amber" />
            <x-stat-card label="Overdue" value="{{ $overdueCount }}" accent="red">
                {{ number_format($overdueAmount, 2) }} due
            </x-stat-card>
            <x-stat-card label="Pending Approval" value="{{ $pendingApprovalCount }}" accent="gray" />
        </div>

        <x-card class="p-4 sm:p-6">
            <h3 class="font-semibold text-gray-800 mb-4">Revenue — Last 6 Months</h3>
            <div class="flex items-end gap-3 h-40">
                @foreach ($monthlyRevenue as $month)
                    <div class="flex-1 flex flex-col items-center gap-2">
                        <div class="w-full flex items-end justify-center" style="height: 120px;">
                            <div class="w-full max-w-[40px] bg-brand-400 rounded-t"
                                 style="height: {{ max(2, (int) round(($month['total'] / $chartMax) * 120)) }}px"
                                 title="{{ number_format($month['total'], 2) }}"></div>
                        </div>
                        <span class="text-xs text-gray-500">{{ $month['label'] }}</span>
                    </div>
                @endforeach
            </div>
        </x-card>

        <x-card class="p-4 sm:p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-semibold text-gray-800">Recent Invoices</h3>
                <a href="{{ route('invoices.index') }}" class="text-sm font-semibold text-brand-500 hover:text-brand-600">View all &rarr;</a>
            </div>

            @if ($recentInvoices->isEmpty())
                <x-empty-state message="No invoices yet." />
            @else
                <div class="divide-y divide-gray-200">
                    @foreach ($recentInvoices as $invoice)
                        <a href="{{ route('invoices.show', $invoice) }}" class="py-3 flex items-center justify-between gap-3 hover:bg-gray-50 -mx-2 px-2 rounded">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-900">{{ $invoice->invoice_number }}</p>
                                <p class="text-xs text-gray-500 truncate">{{ $invoice->client->name }}</p>
                            </div>
                            <div class="text-right shrink-0">
                                <p class="text-sm font-semibold text-gray-900">{{ number_format($invoice->total, 2) }}</p>
                                <x-badge :status="$invoice->isOverdue() ? 'overdue' : $invoice->status" />
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>
