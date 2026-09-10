{{--
    Customize dashboard: show/hide and reorder the widgets below. Plain
    up/down buttons rather than drag-and-drop -- no new JS dependency, and
    it works identically with a mouse, a keyboard, or a thumb on a phone,
    which a drag handle does not.

    The reactive copy lives in Alpine only; nothing is saved until "Save
    layout" posts it, so opening this and closing it without saving
    changes nothing.
--}}
<x-modal name="customize-dashboard" maxWidth="lg">
    <div
        x-data="{
            items: @js($dashboardWidgets),
            moveUp(i) { if (i > 0) { [this.items[i - 1], this.items[i]] = [this.items[i], this.items[i - 1]]; } },
            moveDown(i) { if (i < this.items.length - 1) { [this.items[i + 1], this.items[i]] = [this.items[i], this.items[i + 1]]; } },
        }"
        class="p-6"
    >
        <h2 class="text-lg font-semibold text-white mb-1">Customize dashboard</h2>
        <p class="text-sm text-brand-100/60 mb-4">Show or hide widgets, and put them in the order you want.</p>

        <form method="POST" action="{{ route('dashboard.layout.update') }}"
              @submit="$refs.payload.value = JSON.stringify(items)">
            @csrf
            @method('PUT')
            <input type="hidden" name="widgets" x-ref="payload">

            <ul class="space-y-2">
                <template x-for="(item, index) in items" :key="item.key">
                    <li class="flex items-center gap-3 rounded-lg bg-white/5 ring-1 ring-white/10 px-3 py-2.5">
                        <label class="flex items-center gap-3 flex-1 min-w-0 cursor-pointer">
                            <input type="checkbox" x-model="item.visible"
                                   class="rounded bg-white/10 border-white/25 text-brand-400 focus:ring-brand-400 shrink-0">
                            <span class="text-sm truncate" :class="item.visible ? 'text-white' : 'text-brand-100/40 line-through'" x-text="item.label"></span>
                        </label>
                        <div class="flex items-center gap-1 shrink-0">
                            <button type="button" @click="moveUp(index)" :disabled="index === 0"
                                    class="w-8 h-8 flex items-center justify-center rounded-md text-brand-100/70 hover:bg-white/10 hover:text-white disabled:opacity-30 disabled:pointer-events-none"
                                    aria-label="Move up">
                                <x-icon name="chevron-right" class="w-4 h-4 -rotate-90" />
                            </button>
                            <button type="button" @click="moveDown(index)" :disabled="index === items.length - 1"
                                    class="w-8 h-8 flex items-center justify-center rounded-md text-brand-100/70 hover:bg-white/10 hover:text-white disabled:opacity-30 disabled:pointer-events-none"
                                    aria-label="Move down">
                                <x-icon name="chevron-right" class="w-4 h-4 rotate-90" />
                            </button>
                        </div>
                    </li>
                </template>
            </ul>

            <div class="mt-6 flex items-center gap-3">
                <x-primary-button type="submit">Save layout</x-primary-button>
                <button type="button" @click="$dispatch('close-modal', 'customize-dashboard')"
                        class="text-sm text-brand-100/70 hover:text-white min-h-[44px] px-2">Cancel</button>
            </div>
        </form>

        <form method="POST" action="{{ route('dashboard.layout.reset') }}" class="mt-2">
            @csrf
            @method('DELETE')
            <button type="submit" class="text-xs text-brand-100/50 hover:text-brand-100/80 underline">
                Reset to default
            </button>
        </form>
    </div>
</x-modal>
