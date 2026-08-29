<x-app-layout title="Edit phonebook">
    <x-slot name="header">
        <x-page-header title="Edit Phonebook" eyebrow="WhatsApp CRM" />
    </x-slot>

    <div class="max-w-2xl mx-auto">
        <x-card class="p-4 sm:p-6">
            <form method="POST" action="{{ route('whatsapp-crm.phonebooks.update', $phonebook) }}">
                @method('PUT')
                @include('whatsapp-crm.phonebooks._form')
            </form>
        </x-card>
    </div>
</x-app-layout>
