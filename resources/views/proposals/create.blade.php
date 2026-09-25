<x-app-layout title="New proposal">
    <x-slot name="header">
        <x-page-header title="New proposal" eyebrow="Proposals"
                       subtitle="Tip: duplicating an existing proposal is the fastest start — the Print Bazzar one carries every block type." />
    </x-slot>

    <div class="max-w-4xl mx-auto">
        <form method="POST" action="{{ route('proposals.store') }}" enctype="multipart/form-data">
            @include('proposals._form')
        </form>
    </div>
</x-app-layout>
