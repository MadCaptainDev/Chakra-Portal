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
        <div class="w-24 h-24 shrink-0 rounded-md border border-white/10 bg-brand-900/40 overflow-hidden flex items-center justify-center">
            <template x-if="preview">
                <img :src="preview" alt="" class="w-full h-full object-contain">
            </template>

            <template x-if="! preview">
                <div class="w-full h-full flex items-center justify-center">
                    @if (isset($client) && $client->logoUrl())
                        <img src="{{ $client->logoUrl() }}" alt="{{ $client->name }} logo" class="w-full h-full object-contain">
                    @else
                        <span class="text-[10px] text-brand-100/50">No logo</span>
                    @endif
                </div>
            </template>
        </div>

        <div class="min-w-0 flex-1">
            <input id="logo" name="logo" type="file" accept="image/*"
                   @change="preview = $event.target.files.length ? URL.createObjectURL($event.target.files[0]) : null"
                   class="block w-full text-sm text-brand-100/70 file:mr-3 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-xs file:font-semibold file:uppercase file:tracking-widest file:bg-brand-400 file:text-brand-900 hover:file:bg-brand-500">
            <p class="mt-1 text-xs text-brand-100/60">PNG on a transparent background works best. Max 2&nbsp;MB.</p>
            <x-input-error :messages="$errors->get('logo')" class="mt-2" />

            @if (isset($client) && $client->logo_path)
                <label class="mt-2 inline-flex items-center gap-2 text-sm text-brand-100/70">
                    <input type="checkbox" name="remove_logo" value="1" class="rounded bg-white/10 border-white/25 text-brand-400 focus:ring-brand-400">
                    Remove logo
                </label>
            @endif
        </div>
    </div>
</div>

{{-- The sector. Also answered by the client on their brand brief, which writes
     back here -- so this field is how the studio sees and corrects it. --}}
<div class="mb-4">
    <x-input-label for="industry_id" value="Industry" />
    <x-select id="industry_id" name="industry_id" class="mt-1">
        <option value="">—</option>
        @foreach ($industries as $industry)
            <option value="{{ $industry->id }}" @selected((string) old('industry_id', $client->industry_id ?? '') === (string) $industry->id)>
                {{ $industry->name }}
            </option>
        @endforeach
    </x-select>
    {{-- Master Data is admin-only, so the link is too. Somebody with the
         Clients module and nothing else would get a 403 from it. --}}
    @if (auth()->user()?->isAdmin())
        <p class="mt-1 text-xs text-brand-100/60">Managed on <a href="{{ route('taxonomy.index', ['type' => 'industry']) }}" class="text-brand-500 hover:text-brand-300">Master Data</a>.</p>
    @endif
    <x-input-error :messages="$errors->get('industry_id')" class="mt-2" />
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

<div class="mb-4">
    <x-input-label for="phone" value="Phone" />
    <x-text-input id="phone" name="phone" type="text" class="mt-1 block w-full" value="{{ old('phone', $client->phone ?? '') }}" />
    <x-input-error :messages="$errors->get('phone')" class="mt-2" />
</div>

<div class="mb-6">
    <label class="inline-flex items-start gap-2.5 text-sm text-brand-100/80">
        <input type="checkbox" name="whatsapp_portal_enabled" value="1"
               @checked(old('whatsapp_portal_enabled', $client->whatsapp_portal_enabled ?? false))
               class="mt-0.5 rounded bg-white/10 border-white/25 text-brand-400 focus:ring-brand-400">
        <span>
            <span class="font-semibold text-white">WhatsApp self-service portal</span>
            <span class="block mt-0.5 text-xs text-brand-100/60">
                When this number messages the studio, your active <a href="{{ route('whatsapp-crm.flows.index') }}" class="text-brand-300 hover:text-white underline">Client portal automation</a> runs (WhatsApp CRM → Automations).
            </span>
        </span>
    </label>
    <x-input-error :messages="$errors->get('whatsapp_portal_enabled')" class="mt-2" />
</div>

<div class="flex items-center gap-4">
    <x-primary-button>Save</x-primary-button>
    <a href="{{ route('clients.index') }}" class="text-sm text-brand-100/70 hover:text-white">Cancel</a>
</div>
