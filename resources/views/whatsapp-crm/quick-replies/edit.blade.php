<x-app-layout title="Edit quick reply">
    <x-slot name="header">
        <x-page-header title="Edit Quick Reply" eyebrow="WhatsApp CRM" />
    </x-slot>

    <div class="max-w-2xl mx-auto">
        <x-card class="p-4 sm:p-6">
            <form method="POST" action="{{ route('whatsapp-crm.quick-replies.update', $quickReply) }}">
                @method('PUT')
                @include('whatsapp-crm.quick-replies._form')
            </form>
        </x-card>
    </div>
</x-app-layout>
