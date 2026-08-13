{{-- Shared by the add and edit modals on the register. --}}
<div class="space-y-4">
    <div>
        <x-input-label for="name-{{ $item?->id ?? 'new' }}" value="Name" />
        <x-text-input :id="'name-'.($item?->id ?? 'new')" name="name" type="text" class="mt-1" required
                      :value="old('name', $item?->name)" placeholder="Sony FX3 body" />
        <x-input-error :messages="$errors->get('name')" class="mt-2" />
    </div>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <x-input-label :for="'category-'.($item?->id ?? 'new')" value="Category" />
            <x-select :id="'category-'.($item?->id ?? 'new')" name="category_id" class="mt-1">
                <option value="">Uncategorised</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected(old('category_id', $item?->category_id) == $category->id)>
                        {{ $category->name }}
                    </option>
                @endforeach
            </x-select>
        </div>

        <div>
            <x-input-label :for="'quantity-'.($item?->id ?? 'new')" value="How many" />
            <x-text-input :id="'quantity-'.($item?->id ?? 'new')" name="quantity" type="number" min="1" max="999"
                          class="mt-1" required :value="old('quantity', $item?->quantity ?? 1)" />
            <x-input-error :messages="$errors->get('quantity')" class="mt-2" />
        </div>
    </div>

    <div>
        <x-input-label :for="'identifier-'.($item?->id ?? 'new')" value="Serial or asset tag (optional)" />
        <x-text-input :id="'identifier-'.($item?->id ?? 'new')" name="identifier" type="text" class="mt-1"
                      :value="old('identifier', $item?->identifier)" placeholder="FX3-01" />
    </div>

    <div>
        <x-input-label :for="'notes-'.($item?->id ?? 'new')" value="Notes (optional)" />
        <x-textarea :id="'notes-'.($item?->id ?? 'new')" name="notes" rows="2" class="mt-1"
                    placeholder="Lives in the Pelican case">{{ old('notes', $item?->notes) }}</x-textarea>
    </div>

    @if ($item)
        {{-- Retiring rather than deleting is what "we sold it" means: the item
             leaves every picker and keeps its history. --}}
        <label class="inline-flex items-center gap-2 min-h-[44px] text-sm text-gray-700 cursor-pointer">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $item->is_active))
                   class="rounded border-gray-300 text-brand-500 focus:ring-brand-400">
            In service
        </label>
    @endif
</div>
