@csrf

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div class="sm:col-span-2">
        <x-input-label for="title" value="What is being shot" />
        <x-text-input id="title" name="title" type="text" class="mt-1" required autofocus
                      :value="old('title', $shoot->title)" placeholder="Tea montage — day 1" />
        <x-input-error :messages="$errors->get('title')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="client_id" value="Client" />
        <x-select id="client_id" name="client_id" class="mt-1">
            <option value="">No client</option>
            @foreach ($clients as $client)
                <option value="{{ $client->id }}" @selected(old('client_id', $shoot->client_id) == $client->id)>{{ $client->name }}</option>
            @endforeach
        </x-select>
        <x-input-error :messages="$errors->get('client_id')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="status" value="Status" />
        <x-select id="status" name="status" class="mt-1" required>
            @foreach ($statuses as $value => $label)
                <option value="{{ $value }}" @selected(old('status', $shoot->status ?? \App\Models\Shoot::STATUS_PLANNED) === $value)>{{ $label }}</option>
            @endforeach
        </x-select>
        <x-input-error :messages="$errors->get('status')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="starts_at" value="Call time" />
        <x-text-input id="starts_at" name="starts_at" type="datetime-local" class="mt-1" required
                      :value="old('starts_at', $shoot->starts_at?->format('Y-m-d\TH:i'))" />
        <x-input-error :messages="$errors->get('starts_at')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="ends_at" value="Expected wrap (optional)" />
        <x-text-input id="ends_at" name="ends_at" type="datetime-local" class="mt-1"
                      :value="old('ends_at', $shoot->ends_at?->format('Y-m-d\TH:i'))" />
        <p class="mt-1 text-xs text-brand-100/60">Left blank, the shoot is treated as holding its kit for the rest of that day.</p>
        <x-input-error :messages="$errors->get('ends_at')" class="mt-2" />
    </div>

    <div class="sm:col-span-2">
        <x-input-label for="location" value="Location" />
        <x-text-input id="location" name="location" type="text" class="mt-1"
                      :value="old('location', $shoot->location)" placeholder="Inside Manapparai, Trichy" />
        <x-input-error :messages="$errors->get('location')" class="mt-2" />
    </div>

    <div class="sm:col-span-2">
        <x-input-label for="notes" value="Notes" />
        <x-textarea id="notes" name="notes" rows="3" class="mt-1"
                    placeholder="Parking behind the shop. Ask for Murugan.">{{ old('notes', $shoot->notes) }}</x-textarea>
        <p class="mt-1 text-xs text-brand-100/60">Internal only — these are left off the call sheet.</p>
        <x-input-error :messages="$errors->get('notes')" class="mt-2" />
    </div>
</div>
