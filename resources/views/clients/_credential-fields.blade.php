@php
    use App\Models\ClientCredential;

    /*
     * Shared by the add form and each row's edit form.
     *
     * The password field is always blank, including when editing: the stored
     * value is not in this page and must not be. Leaving it blank on an edit
     * keeps what is stored.
     */
    $uid = $credential?->id ?? 'new';
@endphp

<div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
    <div>
        <x-input-label :for="'kind_'.$uid" value="Account" />
        <x-select :id="'kind_'.$uid" name="kind" class="mt-1" required>
            @foreach (ClientCredential::KINDS as $value => $label)
                <option value="{{ $value }}" @selected(old('kind', $credential?->kind) === $value)>{{ $label }}</option>
            @endforeach
        </x-select>
    </div>

    <div>
        <x-input-label :for="'label_'.$uid" value="Label (optional)" />
        <x-text-input :id="'label_'.$uid" name="label" type="text" class="mt-1 block w-full"
                      value="{{ old('label', $credential?->label) }}" placeholder="e.g. Main account" />
    </div>

    <div>
        <x-input-label :for="'username_'.$uid" value="Username / handle / email" />
        <x-text-input :id="'username_'.$uid" name="username" type="text" class="mt-1 block w-full"
                      value="{{ old('username', $credential?->username) }}" placeholder="@handle or name@gmail.com" />
    </div>

    <div>
        <x-input-label :for="'secret_'.$uid" :value="$credential ? 'New password (leave blank to keep)' : 'Password'" />
        {{-- type=text rather than password: it is typed once by the person who
             already has it, and hiding it behind dots only causes typos. --}}
        <x-text-input :id="'secret_'.$uid" name="secret" type="text" class="mt-1 block w-full"
                      autocomplete="off" :required="$credential === null" />
    </div>

    <div class="sm:col-span-2">
        <x-input-label :for="'url_'.$uid" value="Link (optional)" />
        <x-text-input :id="'url_'.$uid" name="url" type="url" class="mt-1 block w-full"
                      value="{{ old('url', $credential?->url) }}" placeholder="https://instagram.com/…" />
    </div>

    <div class="sm:col-span-2">
        <x-input-label :for="'notes_'.$uid" value="Notes (optional)" />
        <x-textarea :id="'notes_'.$uid" name="notes" rows="2" class="mt-1"
                    placeholder="Recovery codes, which email it is tied to, who set it up">{{ old('notes') }}</x-textarea>
        <p class="mt-1 text-xs text-brand-100/60">Encrypted along with the password, and only shown when revealed.</p>
    </div>
</div>

<x-input-error :messages="$errors->get('kind')" class="mt-2" />
<x-input-error :messages="$errors->get('secret')" class="mt-2" />
<x-input-error :messages="$errors->get('url')" class="mt-2" />
