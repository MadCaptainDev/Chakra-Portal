@php
    /*
    | Everything the old plain <select> made someone squint at, computed once
    | here instead of once per option: free count, what's promised elsewhere,
    | and condition -- grouped by category so "what's left in Camera" is a
    | glance, not a scroll through an alphabetical list of forty things.
    | $available, $committed, $shortfalls, $alreadyOn come from shoots.show's
    | own scope (this partial is @include'd, not a component).
    */
    $pickerItems = $available
        ->reject(fn ($item) => in_array($item->id, $alreadyOn, true))
        ->map(function ($item) use ($committed, $shortfalls, $available, $alreadyOn) {
            $short = (int) ($shortfalls[$item->id] ?? 0);
            $stock = max(0, $item->quantity - $short);
            $taken = (int) ($committed[$item->id]->committed ?? 0);
            $free = max(0, $stock - $taken);

            // Accessories that always travel with this item -- see
            // EquipmentItem::accessories(). Already-on-the-shoot ones are
            // dropped the same way the top-level list is: nothing to
            // auto-add if it's already sitting on the kit.
            $accessoryIds = $available
                ->where('paired_with_id', $item->id)
                ->reject(fn ($a) => in_array($a->id, $alreadyOn, true))
                ->pluck('id')
                ->values();

            $pairedWithName = $item->paired_with_id
                ? $available->firstWhere('id', $item->paired_with_id)?->name
                : null;

            return [
                'id' => $item->id,
                'name' => $item->name,
                'identifier' => $item->identifier,
                'category' => $item->categoryLabel(),
                'total' => $item->quantity,
                'free' => $free,
                'available' => $item->isAvailable(),
                'statusLabel' => $item->isAvailable() ? null : $item->statusLabel(),
                'accessoryIds' => $accessoryIds,
                'pairedWithName' => $pairedWithName,
            ];
        })
        ->values();

    $pickerGroups = $pickerItems->groupBy('category')->sortKeys();
@endphp

<div x-data="equipmentPicker({{ Illuminate\Support\Js::from($pickerItems) }})" x-cloak>
    <x-btn type="button" size="sm" @click="open = true">
        <x-icon name="plus" class="w-4 h-4" />
        Add equipment
    </x-btn>

    <div x-show="open" x-transition.opacity
         class="fixed inset-0 z-[70] flex items-end sm:items-center justify-center sm:p-4"
         role="dialog" aria-modal="true" aria-label="Add equipment">
        <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm" @click="close()"></div>

        <div x-show="open" x-transition:enter="transition ease-out duration-150"
             x-transition:enter-start="opacity-0 translate-y-4 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
             @keydown.escape.window="close()"
             class="relative w-full sm:max-w-2xl max-h-[90vh] sm:max-h-[85vh] flex flex-col rounded-t-2xl sm:rounded-xl bg-brand-800 ring-1 ring-white/10 shadow-2xl overflow-hidden">

            <div class="shrink-0 px-4 sm:px-5 pt-4 pb-3 border-b border-white/10">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-sm font-semibold text-white">Add equipment</p>
                    <button type="button" @click="close()" class="inline-flex items-center justify-center w-9 h-9 -mr-1.5 rounded-lg text-brand-100/60 hover:bg-white/10 hover:text-white transition" aria-label="Close">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>
                <div class="mt-3 flex items-center gap-2 min-h-[40px] px-3 rounded-lg bg-white/5 border border-white/10 focus-within:border-brand-400 focus-within:ring-1 focus-within:ring-brand-400/60">
                    <svg class="shrink-0 w-4 h-4 text-brand-200/50" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z" /></svg>
                    <input x-ref="search" x-model="q" type="search" placeholder="Search equipment…" autocomplete="off"
                           class="flex-1 min-w-0 min-h-[38px] border-0 bg-transparent p-0 text-sm text-white placeholder:text-brand-100/40 focus:ring-0 focus:outline-none" />
                </div>
            </div>

            <div class="flex-1 overflow-y-auto px-2 sm:px-3 py-2">
                <template x-for="group in groups()" :key="group.category">
                    <div class="mb-1">
                        <p class="px-2.5 pt-3 pb-1.5 text-[10px] font-bold uppercase tracking-[0.12em] text-brand-200/40" x-text="group.category"></p>
                        <template x-for="item in group.items" :key="item.id">
                            <div class="flex items-center gap-3 px-2.5 py-2.5 min-h-[56px] rounded-lg hover:bg-white/[0.05] transition"
                                 :class="picked(item.id) && 'bg-brand-400/10'">
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-white truncate">
                                        <span x-text="item.name"></span>
                                        <span x-show="item.identifier" class="text-brand-100/40 font-normal" x-text="'· ' + item.identifier"></span>
                                    </p>
                                    <p class="text-xs mt-0.5" :class="!item.available ? 'text-red-300' : item.free <= 0 ? 'text-amber-300' : 'text-brand-100/50'">
                                        <template x-if="!item.available"><span x-text="item.statusLabel"></span></template>
                                        <template x-if="item.available && item.free <= 0">Promised elsewhere — none free</template>
                                        <template x-if="item.available && item.free > 0 && item.total > 1" x-text="item.free + ' of ' + item.total + ' free'"></template>
                                        <template x-if="item.available && item.free > 0 && item.total <= 1">Free</template>
                                    </p>
                                    <p x-show="item.pairedWithName" class="text-[11px] mt-0.5 text-brand-300/70">
                                        <span x-text="'Goes with ' + item.pairedWithName"></span>
                                    </p>
                                </div>

                                <template x-if="!picked(item.id)">
                                    <button type="button" @click="add(item)"
                                            class="shrink-0 inline-flex items-center justify-center h-9 px-3.5 rounded-lg text-xs font-semibold bg-white/10 text-white hover:bg-white/20 transition">
                                        Add
                                    </button>
                                </template>
                                <template x-if="picked(item.id)">
                                    <div class="shrink-0 flex items-center gap-2">
                                        <span x-show="wasAutoAdded(item.id)" class="text-[10px] font-semibold uppercase tracking-wide text-brand-300/70">Auto</span>
                                        <div class="flex items-center gap-1">
                                            <button type="button" @click="dec(item.id)" class="w-9 h-9 rounded-lg bg-white/10 hover:bg-white/20 text-white flex items-center justify-center transition" aria-label="Fewer">−</button>
                                            <span class="w-6 text-center text-sm font-semibold text-white" x-text="qty(item.id)"></span>
                                            <button type="button" @click="inc(item)" class="w-9 h-9 rounded-lg bg-white/10 hover:bg-white/20 text-white flex items-center justify-center transition" aria-label="More">+</button>
                                            <button type="button" @click="remove(item.id)" class="w-9 h-9 rounded-lg text-brand-100/40 hover:text-red-300 hover:bg-white/5 flex items-center justify-center transition" aria-label="Remove">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                            </button>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>

                <p x-show="groups().length === 0" x-cloak class="px-3 py-10 text-center text-sm text-brand-100/40">
                    Nothing matches "<span x-text="q"></span>"
                </p>
            </div>

            <form method="POST" action="{{ route('shoots.kit.store-many', $shoot) }}" class="shrink-0 border-t border-white/10 px-4 sm:px-5 py-3.5 bg-brand-900/40 flex items-center justify-between gap-3">
                @csrf
                <template x-for="(item, index) in selected" :key="item.id">
                    <span>
                        <input type="hidden" :name="'items[' + index + '][equipment_item_id]'" :value="item.id" />
                        <input type="hidden" :name="'items[' + index + '][quantity]'" :value="item.quantity" />
                    </span>
                </template>
                <p class="text-xs text-brand-100/50">
                    <span x-show="selected.length === 0">Nothing selected yet</span>
                    <span x-show="selected.length > 0" x-text="selected.length + ' item' + (selected.length === 1 ? '' : 's') + ' selected'"></span>
                </p>
                <x-btn type="submit" size="sm" x-bind:disabled="selected.length === 0">
                    Add to kit
                </x-btn>
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
    function equipmentPicker(items) {
        return {
            open: false,
            q: '',
            items,
            selected: [],

            groups() {
                const q = this.q.trim().toLowerCase();
                const filtered = q
                    ? this.items.filter((i) => (i.name + ' ' + (i.identifier || '') + ' ' + i.category).toLowerCase().includes(q))
                    : this.items;

                const byCategory = {};
                filtered.forEach((item) => {
                    (byCategory[item.category] ||= []).push(item);
                });

                return Object.keys(byCategory).sort().map((category) => ({ category, items: byCategory[category] }));
            },
            picked(id) { return this.selected.some((s) => s.id === id); },
            qty(id) { return this.selected.find((s) => s.id === id)?.quantity ?? 1; },
            wasAutoAdded(id) { return !!this.selected.find((s) => s.id === id)?.auto; },
            add(item, auto = false) {
                if (this.picked(item.id)) return;
                this.selected.push({ id: item.id, quantity: 1, max: item.total || 999, auto });

                // A battery only ever goes out with its own camera -- adding
                // the camera brings its paired accessories along instead of
                // making someone remember each one separately. Still just a
                // suggestion: the +/- and × controls work on these exactly
                // like anything picked by hand, including removing them.
                (item.accessoryIds || []).forEach((id) => {
                    const accessory = this.items.find((i) => i.id === id);
                    if (accessory) this.add(accessory, true);
                });
            },
            remove(id) {
                this.selected = this.selected.filter((s) => s.id !== id);
            },
            inc(item) {
                const row = this.selected.find((s) => s.id === item.id);
                if (row && row.quantity < row.max) row.quantity++;
            },
            dec(id) {
                const row = this.selected.find((s) => s.id === id);
                if (!row) return;
                if (row.quantity <= 1) { this.remove(id); return; }
                row.quantity--;
            },
            close() { this.open = false; this.q = ''; },
        };
    }
</script>
@endpush
