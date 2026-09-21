<x-app-layout title="Log an advance">
    <x-slot name="header">
        <x-page-header title="Log an advance" eyebrow="Client Advances"
                       subtitle="Something you paid that belongs to a client" />
    </x-slot>

    <x-card class="p-5 sm:p-6 max-w-3xl">
        <form method="POST" action="{{ route('client-advances.store') }}" enctype="multipart/form-data">
            @include('client-advances._form')

            <div class="flex items-center gap-3 mt-7">
                <x-btn type="submit">Save</x-btn>
                <a href="{{ route('client-advances.index') }}" class="text-sm text-brand-100/60 hover:text-white">Cancel</a>
            </div>
        </form>
    </x-card>
</x-app-layout>
