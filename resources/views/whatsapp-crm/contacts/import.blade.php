<x-app-layout title="Import contacts">
    <x-slot name="header">
        <x-page-header title="Import Contacts" eyebrow="WhatsApp CRM"
                       subtitle="A CSV with a phone column (and optionally name, var1..var5) imports straight into one phonebook." />
    </x-slot>

    <div class="max-w-2xl mx-auto space-y-4">
        @if (session('import_result'))
            @php $result = session('import_result'); @endphp
            <x-card class="p-4 sm:p-6">
                <h3 class="font-semibold text-white mb-2">Last import</h3>
                <div class="flex flex-wrap gap-4 text-sm">
                    <p><span class="font-bold text-emerald-300">{{ $result['imported'] }}</span> <span class="text-brand-100/70">imported</span></p>
                    <p><span class="font-bold text-amber-300">{{ $result['skipped'] }}</span> <span class="text-brand-100/70">skipped</span></p>
                </div>
                @if (! empty($result['errors']))
                    <ul class="mt-3 space-y-1 text-xs text-red-300 list-disc list-inside">
                        @foreach ($result['errors'] as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                @endif
            </x-card>
        @endif

        <x-card class="p-4 sm:p-6">
            <form method="POST" action="{{ route('whatsapp-crm.contacts.import') }}" enctype="multipart/form-data">
                @csrf

                <div class="mb-6">
                    <x-input-label for="phonebook_id" value="Phonebook" />
                    <x-select id="phonebook_id" name="phonebook_id" class="mt-1" required>
                        <option value="">Select a phonebook...</option>
                        @foreach ($phonebooks as $phonebook)
                            <option value="{{ $phonebook->id }}" @selected(old('phonebook_id') == $phonebook->id)>{{ $phonebook->name }}</option>
                        @endforeach
                    </x-select>
                    <x-input-error :messages="$errors->get('phonebook_id')" class="mt-2" />
                    @if ($phonebooks->isEmpty())
                        <p class="text-xs text-amber-300 mt-1">
                            No phonebooks yet -- <a href="{{ route('whatsapp-crm.phonebooks.create') }}" class="underline font-semibold">create one</a> first.
                        </p>
                    @endif
                </div>

                <div class="mb-6">
                    <x-input-label for="file" value="CSV file" />
                    <input id="file" name="file" type="file" accept=".csv,text/csv" required
                        class="mt-1 block w-full text-sm text-brand-100/80 file:mr-4 file:py-2.5 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-white/10 file:text-white hover:file:bg-white/[0.16]">
                    <x-input-error :messages="$errors->get('file')" class="mt-2" />
                    <p class="text-xs text-brand-100/60 mt-1">Recognised columns: phone, name, var1, var2, var3, var4, var5. Header row required.</p>
                </div>

                <div class="flex flex-wrap items-center gap-4">
                    <x-primary-button>Import</x-primary-button>
                    <a href="{{ route('whatsapp-crm.contacts.index') }}" class="text-sm text-brand-100/70 hover:text-white">Cancel</a>
                </div>
            </form>
        </x-card>
    </div>
</x-app-layout>
