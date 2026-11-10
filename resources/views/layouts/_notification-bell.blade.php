{{--
    Top-bar bell. Pulls from NotificationCenterController@feed (your own
    open to-dos, active announcements, client comments on your proposals --
    nothing new persisted). "Unread"
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
        known: new Set(),
        storageKey: 'notif-last-seen-{{ auth()->id() }}',
        toggle() {
            this.open = !this.open;
            if (this.open && !this.loaded) this.load();
            if (this.open) {
                localStorage.setItem(this.storageKey, new Date().toISOString());
                this.unread = 0;
                // Opening the bell reads the inbox, as the app does.
                if (this.items.some((i) => i.unread)) {
                    fetch('{{ route('notification-center.read') }}', { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } });
                    this.items.forEach((i) => { i.unread = false; });
                }
            }
        },
        load(quiet = false) {
            if (!quiet) this.loading = true;
            fetch('{{ route('notification-center.feed') }}', { headers: { 'Accept': 'application/json' } })
                .then((r) => r.json())
                .then((data) => {
                    const items = data.items || [];
                    // Something new while the page is open: say so on screen, and as a
                    // desktop notification if the browser was allowed to show them.
                    if (this.loaded) {
                        items.filter((i) => i.type === 'inbox' && i.unread && !this.known.has(i.title + i.at)).forEach((i) => {
                            window.dispatchEvent(new CustomEvent('portal-toast', { detail: { title: i.title, body: i.subtitle, url: i.url } }));
                            try { if ('Notification' in window && Notification.permission === 'granted') { const n = new Notification(i.title, { body: i.subtitle, icon: '/favicon.ico' }); n.onclick = () => { window.focus(); if (i.url) location.href = i.url; }; } } catch (e) {}
                        });
                    }
                    items.forEach((i) => this.known.add(i.title + i.at));
                    this.items = items;
                    this.loaded = true;
                    this.countUnread();
                })
                .finally(() => { this.loading = false; });
        },
        countUnread() {
            const seen = localStorage.getItem(this.storageKey);
            if (!seen) { this.unread = this.items.length ? Math.min(this.items.length, 9) : 0; return; }
            this.unread = this.items.filter((i) => i.unread || i.at > seen).length;
        },
     }"
     x-init="load(); setInterval(() => { if (!document.hidden) load(true); }, 60000)"
     @keydown.escape.window="open = false"
     @click.outside="open = false"
     class="relative shrink-0">

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
        <ul class="max-h-[28rem] overflow-y-auto divide-y divide-white/5">
            <template x-for="item in items" :key="item.title + item.at">
                <li>
                    <a :href="item.url || '#'" class="block px-4 py-3 hover:bg-white/5 transition" :class="item.unread ? 'bg-brand-400/[0.07]' : ''">
                        <p class="flex items-center gap-1.5 text-sm font-medium text-white"><span x-show="item.unread" class="h-1.5 w-1.5 shrink-0 rounded-full bg-brand-300"></span><span class="truncate" x-text="item.title"></span></p>
                        <p class="text-xs text-brand-100/50 mt-0.5 whitespace-pre-line line-clamp-3" x-text="item.subtitle"></p>
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
