@php
    // Rendered inside a <form> the caller owns, so the CSRF token, the method
    // and the hidden type stay with whichever action is being submitted.
    $term = $term ?? null;
    $uid = $term?->id ?? 'new';
@endphp

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
    <div class="sm:col-span-2">
        <x-input-label :for="'term_name_'.$uid" :value="$meta['label']" />
        <x-text-input :id="'term_name_'.$uid" name="name" type="text" class="mt-1"
                      value="{{ old('name', $term->name ?? '') }}" required />
        <x-input-error :messages="$errors->get('name')" class="mt-2" />
    </div>

    <div>
        <x-input-label :for="'term_order_'.$uid" value="Sort order" />
        <x-text-input :id="'term_order_'.$uid" name="sort_order" type="number" min="0" max="9999" class="mt-1"
                      value="{{ old('sort_order', $term->sort_order ?? 0) }}" />
        <p class="mt-1 text-xs text-brand-100/60">Lower shows first.</p>
        <x-input-error :messages="$errors->get('sort_order')" class="mt-2" />
    </div>
</div>

<label class="mt-4 flex items-start gap-3 min-h-[44px] cursor-pointer">
    <input type="checkbox" name="is_active" value="1"
           @checked(old('is_active', $term?->is_active ?? true))
           class="mt-0.5 rounded bg-white/10 border-white/25 text-brand-400 focus:ring-brand-400">
    <span>
        <span class="block text-sm font-semibold text-white">In use</span>
        <span class="block text-xs text-brand-100/60">
            Untick to retire it: it disappears from the pickers, and anything already using it keeps reading correctly.
        </span>
    </span>
</label>

<div class="mt-4">
    <x-primary-button>{{ $term ? 'Save changes' : 'Add '.Str::lower($meta['label']) }}</x-primary-button>
</div>
