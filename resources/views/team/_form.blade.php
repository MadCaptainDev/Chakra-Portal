@php
    $member = $member ?? null;
    $uid = $member?->id ?? 'new';
@endphp

<form method="POST" enctype="multipart/form-data"
      action="{{ $member ? route('team.update', $member) : route('team.store') }}"
      x-data="{ preview: '' }">
    @csrf
    @if ($member)
        @method('PUT')
    @endif

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <x-input-label :for="'tm_name_'.$uid" value="Name" />
            <x-text-input :id="'tm_name_'.$uid" name="name" type="text" class="mt-1"
                          value="{{ old('name', $member->name ?? '') }}"
                          list="staff-name-suggestions" required />
            @if (empty($member) && ! empty($staffNames))
                <p class="mt-1 text-xs text-gray-500">Start typing to pick someone already on the staff list.</p>
            @endif
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <div>
            <x-input-label :for="'tm_role_'.$uid" value="Role shown on the site" />
            <x-text-input :id="'tm_role_'.$uid" name="role" type="text" class="mt-1"
                          value="{{ old('role', $member->role ?? '') }}" placeholder="e.g. Lead Editor" />
            <x-input-error :messages="$errors->get('role')" class="mt-2" />
        </div>
    </div>

    @if (! empty($staffNames))
        <datalist id="staff-name-suggestions">
            @foreach ($staffNames as $staffName)
                <option value="{{ $staffName }}"></option>
            @endforeach
        </datalist>
    @endif

    <div class="mt-4">
        <x-input-label :for="'tm_bio_'.$uid" value="Short line (optional)" />
        <x-textarea :id="'tm_bio_'.$uid" name="bio" rows="2" class="mt-1"
                    placeholder="One sentence. Keep it short — it sits under the name.">{{ old('bio', $member->bio ?? '') }}</x-textarea>
        <x-input-error :messages="$errors->get('bio')" class="mt-2" />
    </div>

    <div class="mt-4 flex flex-col sm:flex-row sm:items-start gap-4">
        <div class="shrink-0">
            <div class="w-24 h-24 rounded-full bg-gray-100 border border-gray-200 overflow-hidden flex items-center justify-center">
                <template x-if="preview">
                    <img :src="preview" alt="" class="w-full h-full object-cover">
                </template>
                <template x-if="! preview">
                    @if ($member?->photo_path)
                        <img src="{{ asset($member->photo_path) }}" alt="Current photo" class="w-full h-full object-cover">
                    @else
                        <span class="text-xs text-gray-400">No photo</span>
                    @endif
                </template>
            </div>
        </div>

        <div class="flex-1 min-w-0">
            <x-input-label :for="'tm_photo_'.$uid" :value="$member?->photo_path ? 'Replace photo' : 'Photo'" />
            <input id="tm_photo_{{ $uid }}" name="photo" type="file" accept="image/*"
                   @change="preview = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : ''"
                   class="mt-1 block w-full text-sm text-gray-700 file:mr-3 file:min-h-[44px] file:rounded-md file:border-0 file:bg-brand-50 file:px-4 file:text-sm file:font-semibold file:text-brand-700 hover:file:bg-brand-100">
            <p class="mt-1 text-xs text-gray-500">Square photos crop best. Up to 4 MB.</p>
            <x-input-error :messages="$errors->get('photo')" class="mt-2" />

            <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label :for="'tm_order_'.$uid" value="Sort order" />
                    <x-text-input :id="'tm_order_'.$uid" name="sort_order" type="number" min="0" max="9999" class="mt-1"
                                  value="{{ old('sort_order', $member->sort_order ?? 0) }}" />
                    <x-input-error :messages="$errors->get('sort_order')" class="mt-2" />
                </div>

                <label class="flex items-center gap-3 min-h-[44px] cursor-pointer sm:mt-6">
                    <input type="checkbox" name="is_visible" value="1"
                           @checked(old('is_visible', $member?->is_visible ?? true))
                           class="rounded border-gray-300 text-brand-500 focus:ring-brand-400">
                    <span class="text-sm text-gray-700">Show on the website</span>
                </label>
            </div>
        </div>
    </div>

    <div class="mt-5">
        <x-primary-button>{{ $member ? 'Save changes' : 'Add to team' }}</x-primary-button>
    </div>
</form>
