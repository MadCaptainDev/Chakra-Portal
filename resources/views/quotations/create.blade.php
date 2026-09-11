<x-app-layout title="New quotation">
    <x-slot name="header">
        <x-page-header title="New Quotation" />
    </x-slot>

    <div class="max-w-4xl mx-auto">
        <x-card class="p-4 sm:p-6">
            <form method="POST" action="{{ route('quotations.store') }}">
                @include('quotations._form')
            </form>
        </x-card>
    </div>
</x-app-layout>
