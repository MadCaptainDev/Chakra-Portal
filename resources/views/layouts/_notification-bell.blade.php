{{--
    Top-bar bell. Pulls from NotificationCenterController@feed (your own
    open to-dos + active announcements -- nothing new persisted). "Unread"
    is a client-side timestamp in localStorage, not a database column: the
    badge counts items newer than the last time this person opened the
    panel on this device. Good enough for "did I miss anything today?"
    without a migration.
--}}
<div x-data="{
        open: false,
        loading: false,
        loaded: false,
        items: [],
        unread: 0,
        storageKey: 'notif-last-seen-{{ auth()->id() }}',
        toggle() {
            this.open = !this.open;
            if (this.open && !this.loaded) this.load();
            if (this.open) {
                localStorage.setItem(this.storageKey, new Date().toISOString());
                this.unread = 0;
            }
        },
        load() {
            this.loading = true;
            fetch('{{ route('notification-center.feed') }}', { headers: { 'Accept': 'application/json' } })
                .then((r) => r.json())
                .then((data) => {
                    this.items = data.items || [];
                    this.loaded = true;
                    this.countUnread();
                })
                .finally(() => { this.loading = false; });
        },
        countUnread() {
            const seen = localStorage.getItem(this.storageKey);
            if (!seen) { this.unread = this.items.length ? Math.min(this.items.length, 9) : 0; return; }
            this.unread = this.items.filter((i) => i.at > seen).length;
        },
     }"
     x-init="load()"
     @keydown.escape.window="open = false"
     @click.outside="open = false"
     class="relative">

    <button type="button" @click="toggle()"
            class="relative inline-flex items-center justify-center w-11 h-11 rounded-lg text-brand-100 hover:bg-white/10 hover:text-white transition"
            aria-label="Notifications">
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2a2 2 0 01-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
        </svg>
        <span x-show="unread > 0" x-cloak x-text="unread > 9 ? '9+' : unread"
              class="absolute top-1 right-1 inline-flex items-center justify-center min-w-[16px] h-4 px-1 rounded-full bg-red-400 text-brand-900 text-[10px] font-bold leading-none"></span>
    </button>

    <div x-show="open" x-cloak x-transition
         class="absolute right-0 mt-2 w-80 max-w-[85vw] rounded-xl bg-brand-800 ring-1 ring-white/10 shadow-2xl overflow-hidden z-50">
        <div class="px-4 py-3 border-b border-white/10">
            <p class="text-xs font-bold uppercase tracking-widest text-brand-100/60">Notifications</p>
        </div>
        <ul class="max-h-80 overflow-y-auto divide-y divide-white/5">
            <template x-for="item in items" :key="item.title + item.at">
                <li>
                    <a :href="item.url || '#'" class="block px-4 py-3 hover:bg-white/5 transition">
                        <p class="text-sm font-medium text-white truncate" x-text="item.title"></p>
                        <p class="text-xs text-brand-100/50 mt-0.5" x-text="item.subtitle"></p>
                    </a>
                </li>
            </template>
            <li x-show="!loading && items.length === 0" x-cloak class="px-4 py-8 text-center text-sm text-brand-100/40">
                Nothing new
            </li>
            <li x-show="loading" x-cloak class="px-4 py-8 text-center text-sm text-brand-100/40">
                Loading…
            </li>
        </ul>
    </div>
</div>
