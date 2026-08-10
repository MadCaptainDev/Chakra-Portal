@csrf

<div class="mb-4">
    <x-input-label for="name" value="Name" />
    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" value="{{ old('name', $client->name ?? '') }}" required autofocus />
    <x-input-error :messages="$errors->get('name')" class="mt-2" />
</div>

{{-- Logo. Shown on the public case study to credit the brand with its own
     mark. Kept optional -- a client without one simply reads as text. --}}
<div class="mb-4" x-data="{ preview: null }">
    <x-input-label :value="isset($client) && $client->logo_path ? 'Replace logo' : 'Logo'" for="logo" />

    <div class="mt-1 flex items-center gap-4">
        <div class="w-24 h-24 shrink-0 rounded-md border border-gray-200 bg-gray-50 overflow-hidden flex items-center justify-center">
            <template x-if="preview">
                <img :src="preview" alt="" class="w-full h-full object-contain">
            </template>

            <template x-if="! preview">
                <div class="w-full h-full flex items-center justify-center">
                    @if (isset($client) && $client->logoUrl())
                        <img src="{{ $client->logoUrl() }}" alt="{{ $client->name }} logo" class="w-full h-full object-contain">
                    @else
                        <span class="text-[10px] text-gray-400">No logo</span>
                    @endif
                </div>
            </template>
        </div>

        <div class="min-w-0 flex-1">
            <input id="logo" name="logo" type="file" accept="image/*"
                   @change="preview = $event.target.files.length ? URL.createObjectURL($event.target.files[0]) : null"
                   class="block w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-xs file:font-semibold file:uppercase file:tracking-widest file:bg-brand-400 file:text-brand-900 hover:file:bg-brand-500">
            <p class="mt-1 text-xs text-gray-500">PNG on a transparent background works best. Max 2&nbsp;MB.</p>
            <x-input-error :messages="$errors->get('logo')" class="mt-2" />

            @if (isset($client) && $client->logo_path)
                <label class="mt-2 inline-flex items-center gap-2 text-sm text-gray-600">
                    <input type="checkbox" name="remove_logo" value="1" class="rounded border-gray-300 text-brand-500 focus:ring-brand-400">
                    Remove logo
                </label>
            @endif
        </div>
    </div>
</div>

<div class="mb-4">
    <x-input-label for="address" value="Address" />
    <x-text-input id="address" name="address" type="text" class="mt-1 block w-full" value="{{ old('address', $client->address ?? '') }}" />
    <x-input-error :messages="$errors->get('address')" class="mt-2" />
</div>

<div class="mb-4">
    <x-input-label for="email" value="Email" />
    <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" value="{{ old('email', $client->email ?? '') }}" />
    <x-input-error :messages="$errors->get('email')" class="mt-2" />
</div>

<div class="mb-6">
    <x-input-label for="phone" value="Phone" />
    <x-text-input id="phone" name="phone" type="text" class="mt-1 block w-full" value="{{ old('phone', $client->phone ?? '') }}" />
    <x-input-error :messages="$errors->get('phone')" class="mt-2" />
</div>

<div class="flex items-center gap-4">
    <x-primary-button>Save</x-primary-button>
    <a href="{{ route('clients.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Cancel</a>
</div>
