<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Add Client" />
    </x-slot>

    <div class="max-w-2xl mx-auto">
        <x-card class="p-4 sm:p-6">
            <form method="POST" action="{{ route('clients.store') }}">
                @include('clients._form')
            </form>
        </x-card>
    </div>
</x-app-layout>
