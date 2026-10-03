<x-app-layout title="Edit client">
    <x-slot name="header">
        <x-page-header title="Edit Client" />
    </x-slot>

    <div class="max-w-2xl mx-auto">
        <x-card class="p-4 sm:p-6">
            <form method="POST" action="{{ route('clients.update', $client) }}" enctype="multipart/form-data">
                @method('PUT')
                @include('clients._form')
            </form>
        </x-card>

        {{-- What the public website calls this client. Its own form: the
             name above is the one on invoices and stays untouched. --}}
        <x-card class="mt-6 p-4 sm:p-6">
            <form method="POST" action="{{ route('clients.display-name', $client) }}">
                @csrf
                @method('PATCH')
                <h2 class="text-base font-semibold text-white">On the website</h2>
                <p class="mt-1 text-sm text-brand-100/60">
                    The name the portfolio and homepage show. Invoices and quotations keep using
                    <span class="text-white">{{ $client->name }}</span>. Leave blank to use that name everywhere.
                </p>
                <div class="mt-4 flex flex-col sm:flex-row gap-3">
                    <x-text-input name="display_name" class="flex-1" maxlength="120"
                                  :value="old('display_name', $client->display_name)" placeholder="{{ $client->name }}" />
                    <x-primary-button>Save website name</x-primary-button>
                </div>
                <x-input-error :messages="$errors->get('display_name')" class="mt-2" />
            </form>
        </x-card>
    </div>
</x-app-layout>
