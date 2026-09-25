<x-app-layout title="Edit proposal">
    <x-slot name="header">
        <x-page-header :title="'Edit — '.$proposal->title" eyebrow="Proposals"
                       subtitle="Open a section to edit it. Changes show on the client's link as soon as you save." />
    </x-slot>

    <div class="max-w-4xl mx-auto">
        <form method="POST" action="{{ route('proposals.update', $proposal) }}" enctype="multipart/form-data">
            @method('PUT')
            @include('proposals._form')
        </form>
    </div>
</x-app-layout>
