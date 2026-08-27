<x-app-layout :title="$shoot->title">
    <x-slot name="header">
        <x-page-header :title="'Edit '.$shoot->title" eyebrow="Shoots" />
    </x-slot>

    <div class="max-w-3xl">
        <x-card class="p-4 sm:p-6">
            <form method="POST" action="{{ route('shoots.update', $shoot) }}">
                @method('PUT')
                @include('shoots._form')
                <div class="flex items-center gap-4 mt-6">
                    <x-btn type="submit">Save</x-btn>
                    <a href="{{ route('shoots.show', $shoot) }}" class="text-sm text-brand-100/70 hover:text-white">Cancel</a>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
