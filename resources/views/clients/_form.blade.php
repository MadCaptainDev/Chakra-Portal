@csrf

<div class="mb-4">
    <x-input-label for="name" value="Name" />
    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" value="{{ old('name', $client->name ?? '') }}" required autofocus />
    <x-input-error :messages="$errors->get('name')" class="mt-2" />
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
