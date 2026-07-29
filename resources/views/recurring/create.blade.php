<x-app-layout>
    <x-slot name="header">
        <x-page-header title="New Recurring Schedule" />
    </x-slot>

    <div class="max-w-4xl mx-auto">
        <x-card class="p-4 sm:p-6">
            <form method="POST" action="{{ route('recurring.store') }}">
                @include('recurring._form')
            </form>
        </x-card>
    </div>
</x-app-layout>
