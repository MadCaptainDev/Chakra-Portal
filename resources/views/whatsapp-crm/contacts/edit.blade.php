<x-app-layout title="Edit contact">
    <x-slot name="header">
        <x-page-header title="Edit Contact" eyebrow="WhatsApp CRM" />
    </x-slot>

    <div class="max-w-2xl mx-auto">
        <x-card class="p-4 sm:p-6">
            <form method="POST" action="{{ route('whatsapp-crm.contacts.update', $contact) }}">
                @method('PUT')
                @include('whatsapp-crm.contacts._form')
            </form>
        </x-card>
    </div>
</x-app-layout>
