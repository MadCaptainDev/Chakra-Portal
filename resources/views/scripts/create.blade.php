<x-app-layout title="New script">
    <x-slot name="header">
        <x-page-header title="New script" eyebrow="Scripts"
                       subtitle="The details now, the writing next — a new script opens on Hook, Body and CTA." />
    </x-slot>

    <div class="max-w-3xl">
        <x-card class="p-4 sm:p-6">
            <form method="POST" action="{{ route('scripts.store') }}">
                @include('scripts._form')

                <div class="flex items-center gap-4 mt-6">
                    <x-btn type="submit">Create and start writing</x-btn>
                    <a href="{{ route('scripts.index') }}" class="text-sm text-brand-100/70 hover:text-white">Cancel</a>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
