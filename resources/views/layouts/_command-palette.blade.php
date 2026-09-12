{{--
    Cmd+K / Ctrl+K quick jump. Deliberately backend-free: every link it can
    ever offer is one already sitting in the sidebar DOM behind a permission
    check (`[data-nav-link]`, same attribute the sidebar's own filter box
    uses), so this can never surface a screen the signed-in person can't
    already see, and it never drifts from the real menu.
--}}
<div x-data="{
        open: false,
        q: '',
        items: [],
        active: 0,
        results() {
            const q = this.q.trim().toLowerCase();
            if (!q) return this.items.slice(0, 8);
            return this.items
                .filter((i) => i.label.toLowerCase().includes(q))
                .slice(0, 8);
        },
        collect() {
            const seen = new Set();
            this.items = Array.from(document.querySelectorAll('[data-nav-link]'))
                .map((el) => ({ label: (el.innerText || '').trim().replace(/\s+/g, ' '), href: el.getAttribute('href') }))
                .filter((i) => i.label && i.href && !seen.has(i.href) && seen.add(i.href));
        },
        launch() {
            this.collect();
            this.q = '';
            this.active = 0;
            this.open = true;
            this.$nextTick(() => this.$refs.input?.focus());
        },
        go(item) {
            if (item) window.location.href = item.href;
        },
        move(delta) {
            const n = this.results().length;
            if (!n) return;
            this.active = (this.active + delta + n) % n;
        },
     }"
     @open-command-palette.window="launch()"
     @keydown.window="if ((($event.metaKey || $event.ctrlKey) && $event.key.toLowerCase() === 'k')) { $event.preventDefault(); launch(); }"
     @keydown.escape.window="open = false">

    <div x-show="open" x-cloak
         x-transition.opacity
         class="fixed inset-0 z-[60] flex items-start justify-center px-4 pt-[12vh]"
         role="dialog" aria-modal="true" aria-label="Quick jump">
        <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm" @click="open = false"></div>

        <div x-show="open" x-transition:enter="transition ease-out duration-150"
             x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
             class="relative w-full max-w-lg rounded-xl bg-brand-800 ring-1 ring-white/10 shadow-2xl overflow-hidden">

            <div class="flex items-center gap-3 px-4 border-b border-white/10">
                <svg class="w-4 h-4 shrink-0 text-brand-200/50" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z" />
                </svg>
                <input x-ref="input" x-model="q"
                       @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)"
                       @keydown.enter.prevent="go(results()[active])"
                       type="text" placeholder="Jump to…" autocomplete="off"
                       class="flex-1 min-h-[52px] border-0 bg-transparent text-sm text-white placeholder:text-brand-100/40 focus:ring-0 focus:outline-none" />
                <kbd class="hidden sm:inline-block shrink-0 text-[10px] font-semibold text-brand-100/40 border border-white/10 rounded px-1.5 py-0.5">Esc</kbd>
            </div>

            <ul class="max-h-80 overflow-y-auto py-2">
                <template x-for="(item, i) in results()" :key="item.href">
                    <li>
                        <a :href="item.href" @mouseenter="active = i"
                           :class="active === i ? 'bg-brand-400/15 text-white' : 'text-brand-100/80'"
                           class="flex items-center gap-3 mx-2 px-3 py-2.5 min-h-[44px] rounded-lg text-sm font-medium transition">
                            <x-icon name="document" class="w-4 h-4 shrink-0 text-brand-200/50" />
                            <span x-text="item.label"></span>
                        </a>
                    </li>
                </template>
                <li x-show="results().length === 0" x-cloak class="px-5 py-6 text-center text-sm text-brand-100/40">
                    Nothing matches "<span x-text="q"></span>"
                </li>
            </ul>
        </div>
    </div>
</div>
