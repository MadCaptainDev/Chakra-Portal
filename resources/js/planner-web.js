/*
 * Chakra Planner in the browser (planner/web.blade.php): a month of the four
 * Notion planners. Reads and writes through the same endpoints the desktop
 * app uses (Api\Planner\*), under the portal session. Moving a video to
 * another day writes the publish date to Notion first; Notion says no and
 * nothing moves.
 */
const NOTION = {
    default: '#9aa9b1', gray: '#9aa9b1', brown: '#c19a6b', orange: '#fb923c', yellow: '#facc15',
    green: '#4ade80', blue: '#60a5fa', purple: '#c084fc', pink: '#f472b6', red: '#f87171',
};
const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

export function plannerWeb(urls, canEdit) {
    return {
        canEdit,
        month: (() => { const d = new Date(); return new Date(d.getFullYear(), d.getMonth(), 1); })(),
        meta: { sources: [], clients: [], notion: {} },
        items: [],
        undated: [],
        sources: [], // planners shown; empty = all
        client: '',
        q: '',
        open: null,
        loading: false,
        refreshing: false,
        note: '',
        dragId: null,
        overDay: null,

        async init() {
            this.meta = await this.get(urls.meta).catch(() => this.meta);
            await this.load();
            setInterval(() => { if (!document.hidden && !this.dragId) this.load(true); }, 60_000);
        },

        async get(url) {
            const r = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!r.ok) throw new Error(r.status);
            return r.json();
        },

        get gridStart() {
            const d = new Date(this.month);
            d.setDate(1 - ((d.getDay() + 6) % 7)); // weeks start Monday
            return d;
        },

        async load(quiet = false) {
            if (!quiet) this.loading = true;
            const end = new Date(this.gridStart);
            end.setDate(end.getDate() + 41);
            const p = new URLSearchParams({ from: iso(this.gridStart), to: iso(end), per_page: 1000 });
            try {
                const [dated, undated] = await Promise.all([
                    this.get(`${urls.items}?${p}`),
                    this.get(`${urls.items}?undated=1&per_page=300`),
                ]);
                this.items = dated.data;
                this.undated = undated.data;
            } catch {
                this.note = 'Could not load the planner. Try again in a moment.';
            } finally {
                this.loading = false;
            }
        },

        // ——— What is shown ———
        shows(i) {
            if (this.sources.length && !this.sources.includes(i.source)) return false;
            if (this.client && i.client !== this.client) return false;
            if (this.q && !(i.title || '').toLowerCase().includes(this.q.toLowerCase())) return false;
            return true;
        },
        get days() {
            const today = iso(new Date());
            const by = {};
            this.items.filter((i) => this.shows(i)).forEach((i) => { (by[i.published_date] ??= []).push(i); });
            return Array.from({ length: 42 }, (_, n) => {
                const d = new Date(this.gridStart);
                d.setDate(d.getDate() + n);
                const key = iso(d);
                return { key, day: d.getDate(), inMonth: d.getMonth() === this.month.getMonth(), today: key === today, items: by[key] ?? [] };
            });
        },
        get undatedShown() {
            return this.undated.filter((i) => this.shows(i));
        },
        get monthLabel() {
            return this.month.toLocaleDateString('en-IN', { month: 'long', year: 'numeric' });
        },
        get counts() {
            const m = this.month.getMonth();
            const inMonth = this.items.filter((i) => this.shows(i) && new Date(i.published_date).getMonth() === m);
            return { total: inMonth.length, out: inMonth.filter((i) => i.status === 'Published').length };
        },
        color(i) {
            return NOTION[i.status_color] ?? NOTION.default;
        },
        label(source) {
            return this.meta.sources.find((s) => s.key === source)?.label ?? source;
        },
        toggleSource(key) {
            this.sources = this.sources.includes(key) ? this.sources.filter((s) => s !== key) : [...this.sources, key];
        },

        // ——— Moving through months ———
        shift(n) {
            this.month = new Date(this.month.getFullYear(), this.month.getMonth() + n, 1);
            this.load();
        },
        thisMonth() {
            const d = new Date();
            this.month = new Date(d.getFullYear(), d.getMonth(), 1);
            this.load();
        },

        // ——— One video ———
        async show(i) {
            this.open = { ...i, loading: true };
            try {
                const d = await this.get(urls.item.replace('__ID__', i.id));
                if (this.open?.id === i.id) this.open = d.item;
            } catch {
                if (this.open) this.open.loading = false;
            }
        },

        // ——— Moving a video's publish date (Notion first) ———
        drag(i) { if (this.canEdit) this.dragId = i.id; },
        async drop(dayKey) {
            const id = this.dragId;
            this.dragId = null;
            this.overDay = null;
            if (id) await this.move(id, dayKey);
        },
        async move(id, date) {
            const all = [...this.items, ...this.undated];
            const it = all.find((x) => x.id === id);
            if (!it || it.published_date === date) return;
            const from = it.published_date;
            this.note = 'Saving to Notion…';
            it.published_date = date; // shown at once, put back if Notion refuses
            this.place(it);
            try {
                const r = await fetch(urls.move.replace('__ID__', id), {
                    method: 'PATCH',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify({ published_date: date, from }),
                });
                const d = await r.json().catch(() => ({}));
                if (!r.ok) throw new Error(d.message || 'Notion did not take the change.');
                Object.assign(it, d.item);
                if (this.open?.id === id) this.open = d.item;
                this.note = `Moved to ${date ? new Date(date).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }) : 'no date'} — saved in Notion.`;
            } catch (e) {
                it.published_date = from;
                this.place(it);
                this.note = e.message;
            }
            setTimeout(() => { this.note = ''; }, 5000);
        },
        place(it) {
            this.items = this.items.filter((x) => x.id !== it.id);
            this.undated = this.undated.filter((x) => x.id !== it.id);
            (it.published_date ? this.items : this.undated).push(it);
        },

        async refresh() {
            this.refreshing = true;
            try {
                const r = await fetch(urls.refresh, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } });
                const d = await r.json();
                this.note = d.message;
                this.meta.notion.last_synced_at = d.last_synced_at;
                await this.load(true);
            } catch {
                this.note = 'Refresh failed. Try again.';
            } finally {
                this.refreshing = false;
            }
        },
        ago(at) {
            if (!at) return 'never';
            const m = Math.round((Date.now() - new Date(at)) / 60000);
            return m < 1 ? 'just now' : m < 60 ? `${m} min ago` : `${Math.round(m / 60)} h ago`;
        },
    };
}
