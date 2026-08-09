@php
    // Rendered inside a <form> the caller owns, so the CSRF token and method
    // spoofing stay with whichever action is being submitted.
    $category = $category ?? null;
    $uid = $category?->id ?? 'new';
@endphp

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
    <div class="sm:col-span-2">
        <x-input-label :for="'cat_name_'.$uid" value="Name" />
        <x-text-input :id="'cat_name_'.$uid" name="name" type="text" class="mt-1"
                      value="{{ old('name', $category->name ?? '') }}"
                      placeholder="e.g. Weddings" required />
        <x-input-error :messages="$errors->get('name')" class="mt-2" />
    </div>

    <div>
        <x-input-label :for="'cat_order_'.$uid" value="Sort order" />
        <x-text-input :id="'cat_order_'.$uid" name="sort_order" type="number" min="0" max="9999" class="mt-1"
                      value="{{ old('sort_order', $category->sort_order ?? 0) }}" />
        <x-input-error :messages="$errors->get('sort_order')" class="mt-2" />
    </div>
</div>

<label class="mt-4 flex items-center gap-3 min-h-[44px] cursor-pointer">
    <input type="checkbox" name="is_visible" value="1"
           @checked(old('is_visible', $category?->is_visible ?? true))
           class="rounded border-gray-300 text-brand-500 focus:ring-brand-400">
    <span class="text-sm text-gray-700">Show this tab on the website</span>
</label>

<div class="mt-4">
    <x-primary-button>{{ $category ? 'Save changes' : 'Add category' }}</x-primary-button>
</div>
