<x-app-layout title="Edit client">
    <x-slot name="header">
        <x-page-header title="Edit Client" />
    </x-slot>

    <div class="max-w-2xl mx-auto">
        <x-card class="p-4 sm:p-6">
            <form method="POST" action="{{ route('clients.update', $client) }}" enctype="multipart/form-data">
                @method('PUT')
                @include('clients._form')
            </form>
        </x-card>
    </div>
</x-app-layout>
