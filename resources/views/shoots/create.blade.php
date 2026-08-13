<x-app-layout title="Plan a shoot">
    <x-slot name="header">
        <x-page-header title="Plan a shoot" eyebrow="Shoots"
                       subtitle="The when and where now. Crew and kit go on next." />
    </x-slot>

    <div class="max-w-3xl">
        <x-card class="p-4 sm:p-6">
            <form method="POST" action="{{ route('shoots.store') }}">
                @include('shoots._form')
                <div class="flex items-center gap-4 mt-6">
                    <x-btn type="submit">Create shoot</x-btn>
                    <a href="{{ route('shoots.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Cancel</a>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
