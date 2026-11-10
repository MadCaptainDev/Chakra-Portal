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

    {{-- A picture: the crew tick kit by sight at the studio door. --}}
    <div x-data="{ preview: @js($item?->thumbUrl()), gone: false }">
        <x-input-label :for="'image-'.($item?->id ?? 'new')" value="Photo (optional)" />
        <div class="mt-1 flex items-center gap-3">
            <template x-if="preview && !gone">
                <img :src="preview" alt="" class="h-16 w-16 shrink-0 rounded-lg bg-white/5 object-cover ring-1 ring-white/10">
            </template>
            <template x-if="!preview || gone">
                <span class="flex h-16 w-16 shrink-0 items-center justify-center rounded-lg bg-white/5 text-xs text-brand-100/40 ring-1 ring-white/10">No photo</span>
            </template>
            <div class="min-w-0 flex-1">
                <input :id="'image-{{ $item?->id ?? 'new' }}'" type="file" name="image" accept="image/*"
                       @change="preview = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : preview; gone = false"
                       class="block w-full text-xs text-brand-100/70 file:mr-3 file:rounded-lg file:border-0 file:bg-white/10 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-white hover:file:bg-white/20">
                @if ($item?->image_path)
                    <label class="mt-1.5 inline-flex cursor-pointer items-center gap-1.5 text-xs text-brand-100/50">
                        <input type="checkbox" name="remove_image" value="1" x-model="gone" class="rounded bg-white/10 border-white/25 text-red-400 focus:ring-red-400">
                        Remove photo
                    </label>
                @endif
            </div>
        </div>
        <x-input-error :messages="$errors->get('image')" class="mt-2" />
    </div>

    {{-- What goes with it: picked once here, added to every shoot with it. --}}
    @php
        $bundleRows = collect(old('bundle', $item?->bundle->map(fn ($b) => ['item_id' => $b->id, 'quantity' => $b->pivot->quantity])->all() ?? []))
            ->map(fn ($row) => ['item_id' => (int) $row['item_id'], 'quantity' => (int) ($row['quantity'] ?? 1)])->values();
        $bundleOptions = $pairables->reject(fn ($p) => $item && $p->id === $item->id)->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values();
    @endphp
    <div x-data="{
            rows: @js($bundleRows),
            options: @js($bundleOptions),
            pick: '',
            name(id) { return this.options.find(o => o.id === id)?.name ?? 'Removed item' },
            add() { const id = parseInt(this.pick); if (id && !this.rows.some(r => r.item_id === id)) this.rows.push({ item_id: id, quantity: 1 }); this.pick = '' },
         }">
        <input type="hidden" name="bundle_sent" value="1">
        <x-input-label value="Goes with it (optional)" />
        <p class="mt-0.5 text-xs text-brand-100/50">
            Adding this item to a shoot adds these too, so nobody has to remember them: a camera's lens, its memory card, its batteries.
        </p>
        <ul class="mt-2 space-y-1.5">
            <template x-for="(row, i) in rows" :key="row.item_id">
                <li class="flex items-center gap-2 rounded-lg bg-white/5 px-3 py-2">
                    <input type="hidden" :name="'bundle[' + i + '][item_id]'" :value="row.item_id">
                    <span class="min-w-0 flex-1 truncate text-sm text-white" x-text="name(row.item_id)"></span>
                    <span class="text-xs text-brand-100/50">×</span>
                    <input type="number" min="1" max="99" x-model.number="row.quantity" :name="'bundle[' + i + '][quantity]'"
                           class="w-16 rounded-md border-white/15 bg-white/5 px-2 py-1 text-sm text-white">
                    <button type="button" @click="rows.splice(i, 1)" class="rounded-md px-2 py-1 text-brand-100/40 hover:bg-white/5 hover:text-red-300" aria-label="Remove">✕</button>
                </li>
            </template>
        </ul>
        <div class="mt-2 flex gap-2">
            <select x-model="pick" class="min-w-0 flex-1 rounded-lg border-white/15 bg-white/5 py-2 text-sm text-white">
                <option value="" class="text-black">Choose an item…</option>
                <template x-for="o in options.filter(o => !rows.some(r => r.item_id === o.id))" :key="o.id">
                    <option :value="o.id" x-text="o.name" class="text-black"></option>
                </template>
            </select>
            <button type="button" @click="add()" :disabled="!pick" class="rounded-lg bg-white/10 px-3 text-sm font-semibold text-white disabled:opacity-40">Add</button>
        </div>
        <x-input-error :messages="$errors->get('bundle')" class="mt-2" />
    </div>

    <div class="grid grid-cols-2 gap-3" x-data="{ status: '{{ old('status', $item?->status ?? \App\Models\EquipmentItem::STATUS_AVAILABLE) }}' }">
        <div>
            <x-input-label :for="'status-'.($item?->id ?? 'new')" value="Condition" />
            <x-select :id="'status-'.($item?->id ?? 'new')" name="status" class="mt-1" x-model="status">
                @foreach (\App\Models\EquipmentItem::STATUSES as $value => $label)
                    <option value="{{ $value }}" @selected(old('status', $item?->status ?? \App\Models\EquipmentItem::STATUS_AVAILABLE) === $value)>{{ $label }}</option>
                @endforeach
            </x-select>
            <x-input-error :messages="$errors->get('status')" class="mt-2" />
        </div>

        <div x-show="status !== '{{ \App\Models\EquipmentItem::STATUS_AVAILABLE }}'" x-cloak>
            <x-input-label :for="'status_note-'.($item?->id ?? 'new')" value="Detail (optional)" />
            <x-text-input :id="'status_note-'.($item?->id ?? 'new')" name="status_note" type="text" class="mt-1"
                          :value="old('status_note', $item?->status_note)" placeholder="Sent to Sony service centre, back ~20th" />
        </div>
    </div>

    <div>
        <x-input-label :for="'notes-'.($item?->id ?? 'new')" value="Notes (optional)" />
        <x-textarea :id="'notes-'.($item?->id ?? 'new')" name="notes" rows="2" class="mt-1"
                    placeholder="Lives in the Pelican case">{{ old('notes', $item?->notes) }}</x-textarea>
    </div>

    @if ($item)
        {{-- Retiring rather than deleting is what "we sold it" means: the item
             leaves every picker and keeps its history. --}}
        <label class="inline-flex items-center gap-2 min-h-[44px] text-sm text-brand-100/80 cursor-pointer">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $item->is_active))
                   class="rounded bg-white/10 border-white/25 text-brand-400 focus:ring-brand-400">
            In service
        </label>
    @endif
</div>
