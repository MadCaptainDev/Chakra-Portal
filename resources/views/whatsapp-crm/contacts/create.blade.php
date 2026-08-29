<x-app-layout title="New contact">
    <x-slot name="header">
        <x-page-header title="New Contact" eyebrow="WhatsApp CRM" />
    </x-slot>

    <div class="max-w-2xl mx-auto">
        <x-card class="p-4 sm:p-6">
            <form method="POST" action="{{ route('whatsapp-crm.contacts.store') }}">
                @include('whatsapp-crm.contacts._form')
            </form>
        </x-card>
    </div>
</x-app-layout>
