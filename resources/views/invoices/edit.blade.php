<x-app-layout title="Edit invoice">
    <x-slot name="header">
        <x-page-header title="Edit Invoice {{ $invoice->invoice_number ?? '(pending)' }}" />
    </x-slot>

    <div class="max-w-4xl mx-auto">
        <x-card class="p-4 sm:p-6">
            <form method="POST" action="{{ route('invoices.update', $invoice) }}">
                @method('PUT')
                @include('invoices._form')
            </form>
        </x-card>
    </div>
</x-app-layout>
