@csrf

<div class="mb-6">
    <x-input-label for="name" value="Name" />
    <x-text-input id="name" name="name" type="text" class="mt-1" required autofocus
        value="{{ old('name', $phonebook->name ?? '') }}" placeholder="e.g. Leads" />
    <x-input-error :messages="$errors->get('name')" class="mt-2" />
</div>

<div class="mb-6">
    <x-input-label for="description" value="Description (optional)" />
    <x-textarea id="description" name="description" rows="3" class="mt-1">{{ old('description', $phonebook->description ?? '') }}</x-textarea>
    <x-input-error :messages="$errors->get('description')" class="mt-2" />
</div>

<div class="flex flex-wrap items-center gap-4">
    <x-primary-button>Save Phonebook</x-primary-button>
    <a href="{{ route('whatsapp-crm.phonebooks.index') }}" class="text-sm text-brand-100/70 hover:text-white">Cancel</a>
</div>
