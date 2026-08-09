<x-app-layout title="New recurring invoice">
    <x-slot name="header">
        <x-page-header :title="($sourceInvoice ?? null)
            ? 'New Recurring Schedule from '.($sourceInvoice->invoice_number ?? 'invoice')
            : 'New Recurring Schedule'" />
    </x-slot>

    <div class="max-w-4xl mx-auto space-y-4">
        @if ($sourceInvoice ?? null)
            <div class="bg-brand-50 border border-brand-200 rounded-lg p-4 text-sm text-brand-800">
                Pre-filled from invoice
                <a href="{{ route('invoices.show', $sourceInvoice) }}" class="font-semibold underline">{{ $sourceInvoice->invoice_number ?? 'draft' }}</a>
                for <span class="font-semibold">{{ $sourceInvoice->client->name }}</span>.
                Check the frequency and first occurrence below &mdash;
                <span class="font-semibold">nothing is scheduled until you save</span>.
            </div>
        @endif

        <x-card class="p-4 sm:p-6">
            <form method="POST" action="{{ route('recurring.store') }}">
                @include('recurring._form')
            </form>
        </x-card>
    </div>
</x-app-layout>
