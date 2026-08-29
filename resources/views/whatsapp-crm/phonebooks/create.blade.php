<x-app-layout title="New phonebook">
    <x-slot name="header">
        <x-page-header title="New Phonebook" eyebrow="WhatsApp CRM" />
    </x-slot>

    <div class="max-w-2xl mx-auto">
        <x-card class="p-4 sm:p-6">
            <form method="POST" action="{{ route('whatsapp-crm.phonebooks.store') }}">
                @include('whatsapp-crm.phonebooks._form')
            </form>
        </x-card>
    </div>
</x-app-layout>
