<x-app-layout title="Edit quotation">
    <x-slot name="header">
        <x-page-header :title="'Edit Quotation '.($quotation->quotation_number ?? '')" />
    </x-slot>

    <div class="max-w-4xl mx-auto">
        <x-card class="p-4 sm:p-6">
            <form method="POST" action="{{ route('quotations.update', $quotation) }}">
                @method('PUT')
                @include('quotations._form')
            </form>
        </x-card>
    </div>
</x-app-layout>
