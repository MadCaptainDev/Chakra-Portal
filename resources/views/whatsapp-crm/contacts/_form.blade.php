@csrf

<div class="mb-6">
    <x-input-label for="phone" value="Phone" />
    <x-text-input id="phone" name="phone" type="text" class="mt-1" required autofocus
        value="{{ old('phone', $contact->phone ?? '') }}" placeholder="e.g. 9876543210" />
    <x-input-error :messages="$errors->get('phone')" class="mt-2" />
    <p class="text-xs text-brand-100/60 mt-1">A bare 10-digit number is treated as Indian (+91). Stored normalised either way.</p>
</div>

<div class="mb-6">
    <x-input-label for="name" value="Name (optional)" />
    <x-text-input id="name" name="name" type="text" class="mt-1" value="{{ old('name', $contact->name ?? '') }}" />
    <x-input-error :messages="$errors->get('name')" class="mt-2" />
</div>

<div class="mb-6">
    <x-input-label value="Merge fields (optional)" />
    <p class="text-xs text-brand-100/60 mt-1 mb-2">Filled positionally into a campaign template's body parameters -- var1 first.</p>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        @foreach (['var1', 'var2', 'var3', 'var4', 'var5'] as $index => $field)
            <div>
                <x-input-label :for="$field" :value="'Var '.($index + 1)" />
                <x-text-input :id="$field" :name="$field" type="text" class="mt-1" value="{{ old($field, $contact->{$field} ?? '') }}" />
                <x-input-error :messages="$errors->get($field)" class="mt-2" />
            </div>
        @endforeach
    </div>
</div>

<div class="mb-6">
    <x-input-label value="Phonebooks" />
    @if ($phonebooks->isEmpty())
        <p class="text-sm text-brand-100/60 mt-1">
            No phonebooks yet.
            <a href="{{ route('whatsapp-crm.phonebooks.create') }}" class="text-brand-500 hover:text-brand-300 font-semibold">Create one</a>
            to organise contacts into lists.
        </p>
    @else
        @php
            $selectedPhonebooks = old('phonebooks', isset($contact) ? $contact->phonebooks->pluck('id')->all() : []);
        @endphp
        <div class="mt-1 grid grid-cols-1 sm:grid-cols-2 gap-2">
            @foreach ($phonebooks as $phonebook)
                <label class="inline-flex items-center gap-2 min-h-[44px] text-sm text-brand-100/80">
                    <input type="checkbox" name="phonebooks[]" value="{{ $phonebook->id }}"
                           class="rounded border-white/15 bg-white/5 text-brand-400 focus:ring-brand-400"
                           @checked(in_array($phonebook->id, $selectedPhonebooks))>
                    {{ $phonebook->name }}
                </label>
            @endforeach
        </div>
    @endif
    <x-input-error :messages="$errors->get('phonebooks')" class="mt-2" />
</div>

<div class="flex flex-wrap items-center gap-4">
    <x-primary-button>Save Contact</x-primary-button>
    <a href="{{ route('whatsapp-crm.contacts.index') }}" class="text-sm text-brand-100/70 hover:text-white">Cancel</a>
</div>
