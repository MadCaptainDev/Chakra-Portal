{{--
    The kit picker as a photo grid (resources/js/kit-grid.js). Tap a picture
    to add it, tap again for one more; its bundle comes along.
    $gridItems: list of [id, name, identifier, category, order, total, free,
      available, statusLabel, accessoryIds, brings, image]
    $action:    where the picked kit is posted
    $style:     'rows' → items[i][equipment_item_id] + items[i][quantity] (shoot page)
                'ids'  → items[] + qty[id] (crew run page)
--}}
<div x-data="kitGrid(@js($gridItems))" class="flex min-h-0 flex-1 flex-col">
    <div class="shrink-0 space-y-2 pb-2">
        <input x-model="q" type="search" placeholder="Search kit…" autocomplete="off"
               class="h-11 w-full rounded-xl border-0 bg-white/10 px-3.5 text-sm text-white placeholder:text-white/35 focus:ring-2 focus:ring-brand-400">
        <div class="-mx-1 flex gap-1.5 overflow-x-auto px-1 pb-1">
            <button type="button" @click="cat = ''" class="h-8 shrink-0 rounded-full px-3 text-xs font-semibold ring-1 transition" :class="cat === '' ? 'bg-white text-brand-900 ring-white' : 'text-white/70 ring-white/15'">All</button>
            <template x-for="c in categories" :key="c">
                <button type="button" @click="cat = cat === c ? '' : c" class="h-8 shrink-0 rounded-full px-3 text-xs font-semibold ring-1 transition" :class="cat === c ? 'bg-white text-brand-900 ring-white' : 'text-white/70 ring-white/15'" x-text="c"></button>
            </template>
        </div>
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto">
        <div class="grid grid-cols-3 gap-2 sm:grid-cols-4">
            <template x-for="item in shown" :key="item.id">
                <div class="relative">
                    <button type="button" @click="tap(item)" :disabled="!item.available"
                            class="group block w-full overflow-hidden rounded-xl bg-white/5 text-left ring-2 transition active:scale-[0.97] disabled:opacity-40"
                            :class="qty(item.id) ? 'ring-emerald-400' : 'ring-transparent hover:ring-white/20'">
                        <span class="relative block aspect-square bg-white">
                            <template x-if="item.image"><img :src="item.image" alt="" loading="lazy" class="h-full w-full object-cover"></template>
                            <template x-if="!item.image"><span class="flex h-full w-full items-center justify-center bg-white/10 text-2xl font-bold text-brand-900/30" x-text="item.name.charAt(0).toUpperCase()"></span></template>
                            <span x-show="qty(item.id)" x-cloak class="absolute right-1.5 top-1.5 flex h-7 min-w-7 items-center justify-center rounded-full bg-emerald-500 px-1.5 text-sm font-bold text-white shadow" x-text="'×' + qty(item.id)"></span>
                            <span x-show="row(item.id)?.auto" x-cloak class="absolute left-1.5 top-1.5 rounded-full bg-violet-500 px-1.5 py-0.5 text-[10px] font-bold text-white">with</span>
                        </span>
                        <span class="block px-2 py-1.5">
                            <span class="block truncate text-[12px] font-semibold leading-tight text-white" x-text="item.name"></span>
                            <span class="block truncate text-[10px]" :class="!item.available ? 'text-red-300' : (item.free <= 0 ? 'text-amber-300' : 'text-white/45')"
                                  x-text="!item.available ? item.statusLabel : (item.free <= 0 ? 'None free' : (item.total > 1 ? item.free + ' of ' + item.total + ' free' : 'Free'))"></span>
                            <span x-show="item.brings" class="block truncate text-[10px] text-violet-200/70" x-text="'+ ' + item.brings"></span>
                        </span>
                    </button>
                    <button type="button" x-show="qty(item.id)" x-cloak @click="less(item.id)" class="absolute bottom-[46px] right-1.5 flex h-8 w-8 items-center justify-center rounded-full bg-black/70 text-lg font-bold text-white" aria-label="One less">−</button>
                </div>
            </template>
        </div>
        <p x-show="!shown.length" x-cloak class="py-10 text-center text-sm text-white/40">Nothing matches.</p>
    </div>

    <form method="POST" action="{{ $action }}" class="shrink-0 border-t border-white/10 pt-3">
        @csrf
        <template x-for="(s, i) in selected" :key="s.id">
            <span>
                @if ($style === 'rows')
                    <input type="hidden" :name="'items[' + i + '][equipment_item_id]'" :value="s.id">
                    <input type="hidden" :name="'items[' + i + '][quantity]'" :value="s.quantity">
                @else
                    <input type="hidden" name="items[]" :value="s.id">
                    <input type="hidden" :name="'qty[' + s.id + ']'" :value="s.quantity">
                @endif
            </span>
        </template>
        <div class="flex items-center gap-2">
            <p class="min-w-0 flex-1 truncate text-xs text-white/55">
                <span x-show="!selected.length">Tap a picture to add it</span>
                <span x-show="selected.length" x-text="selected.map((s) => (s.quantity > 1 ? s.quantity + '× ' : '') + name(s.id)).join(', ')"></span>
            </p>
            <button type="button" x-show="selected.length" x-cloak @click="clear()" class="h-11 px-2 text-xs text-white/50 hover:text-white">Clear</button>
            <button type="submit" :disabled="!selected.length" class="h-11 shrink-0 rounded-xl bg-emerald-500 px-4 text-sm font-bold text-white disabled:opacity-40"
                    x-text="selected.length ? 'Add ' + count + ' to kit' : 'Add to kit'"></button>
        </div>
    </form>
</div>
