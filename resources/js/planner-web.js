/*
 * Chakra Planner in the browser (planner/web.blade.php): the four Notion
 * planners as a calendar, a board and a table, edited in place the way
 * Notion is. Reads and writes through the same endpoints the desktop app
 * uses (Api\Planner\*), under the portal session.
 *
 * Every change is shown at once and written to Notion behind it; if Notion
 * says no, the change is put back and the reason shown. One store (byId)
 * holds every item the page has seen, so a month already visited opens with
 * no wait and a poll merges changes in without redrawing what did not move.
 */
const NOTION = {
    default: '#9aa9b1', gray: '#9aa9b1', brown: '#c19a6b', orange: '#fb923c', yellow: '#facc15',
    green: '#4ade80', blue: '#60a5fa', purple: '#c084fc', pink: '#f472b6', red: '#f87171',
};
const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const monthKey = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
const store = {
    get(key, fallback) { try { return JSON.parse(localStorage.getItem(key)) ?? fallback; } catch { return fallback; } },
    set(key, value) { try { localStorage.setItem(key, JSON.stringify(value)); } catch { /* private mode */ } },
};
const names = (text) => (text || '').split(',').map((s) => s.trim()).filter(Boolean);
const POLL_MS = 20_000;

export function plannerWeb(urls, canEdit) {
    const saved = store.get('planner-filter', {});

    return {
        canEdit,
        month: (() => { const d = new Date(); return new Date(d.getFullYear(), d.getMonth(), 1); })(),
        meta: { sources: [], clients: [], statuses: {}, fields: {}, notion: {} },
        byId: {},
        loadedMonths: {},
        ready: false,
        loading: false,
        since: null, // server time of the last read, for the next delta

        sources: saved.sources ?? [], // planners shown; empty = all
        client: (() => { try { return new URLSearchParams(location.search).get('client') ?? saved.client ?? ''; } catch { return saved.client ?? ''; } })(),
        q: '',
        view: (() => { try { return new URLSearchParams(location.search).get('view') || saved.view || localStorage.getItem('planner-view') || 'calendar'; } catch { return 'calendar'; } })(),
        group: saved.group ?? 'status',
        views: store.get('planner-views', []),

        peek: null,
        peekTab: 'details',
        draft: null,
        creating: false,
        saving: {}, // item id => writes in flight
        toast: null,
        toastTimer: null,
        refreshing: false,
        dragId: null,
        overKey: null,
        expanded: null, // a day opened past its first six

        async init() {
            const [meta] = await Promise.all([
                this.get(urls.meta).catch(() => null),
                this.loadMonth(this.month, true),
                this.loadUndated(),
            ]);
            if (meta) this.meta = meta;
            this.ready = true;
            this.prefetch();

            const wanted = new URLSearchParams(location.search).get('item');
            if (wanted) this.show({ id: Number(wanted) });

            setInterval(() => { if (!document.hidden && !this.dragId) this.poll(); }, POLL_MS);
            document.addEventListener('visibilitychange', () => { if (!document.hidden) this.poll(); });
            window.addEventListener('keydown', (e) => this.key(e));
            window.addEventListener('popstate', () => {
                const id = new URLSearchParams(location.search).get('item');
                if (id) this.show({ id: Number(id) }, false); else this.peek = null;
            });
            this.$watch('sources', () => this.remember());
            this.$watch('client', () => this.remember());
            this.$watch('group', () => this.remember());
        },

        // ——— Reading ———
        async get(url) {
            const r = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!r.ok) throw new Error(r.status);
            return r.json();
        },
        async send(method, url, body) {
            const r = await fetch(url, {
                method,
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                body: JSON.stringify(body ?? {}),
            });
            const data = await r.json().catch(() => ({}));
            return { ok: r.ok, status: r.status, data };
        },
        gridStartOf(month) {
            const d = new Date(month);
            d.setDate(1 - ((d.getDay() + 6) % 7)); // weeks start Monday
            return d;
        },
        get gridStart() { return this.gridStartOf(this.month); },

        /** Take rows into the store. A row being written right now keeps what the person just did. */
        merge(rows) {
            for (const row of rows) {
                if (row.missing_from_notion) { delete this.byId[row.id]; continue; }
                if (this.saving[row.id]) continue;
                const have = this.byId[row.id];
                if (have) Object.assign(have, row); else this.byId[row.id] = row;
            }
        },
        async loadMonth(month, loud = false) {
            const key = monthKey(month);
            if (this.loadedMonths[key]) return;
            if (loud) this.loading = true;
            const start = this.gridStartOf(month);
            const end = new Date(start);
            end.setDate(end.getDate() + 41);
            try {
                const d = await this.get(`${urls.items}?${new URLSearchParams({ from: iso(start), to: iso(end), per_page: 1000 })}`);
                this.merge(d.data);
                this.loadedMonths[key] = true;
                this.since ??= d.meta.server_time;
            } catch {
                if (loud) this.say('Could not load the planner. Try again in a moment.', true);
            } finally {
                if (loud) this.loading = false;
            }
        },
        async loadUndated() {
            try {
                for (let page = 1; page <= 5; page++) {
                    const d = await this.get(`${urls.items}?undated=1&per_page=500&page=${page}`);
                    this.merge(d.data);
                    if (page >= d.meta.last_page) break;
                }
            } catch { /* the calendar still works without them */ }
        },
        /** The months either side, quietly, so the arrows never wait. */
        prefetch() {
            const m = this.month;
            [-1, 1].forEach((n) => this.loadMonth(new Date(m.getFullYear(), m.getMonth() + n, 1)));
        },
        /** Only what changed since the last read. */
        async poll() {
            if (!this.since) return;
            try {
                const d = await this.get(`${urls.items}?${new URLSearchParams({ updated_since: this.since, per_page: 1000 })}`);
                this.since = d.meta.server_time;
                this.merge(d.data);
                if (this.peek && !this.saving[this.peek.id]) {
                    const row = d.data.find((x) => x.id === this.peek.id);
                    if (row) Object.assign(this.peek, row);
                }
            } catch { /* next time */ }
        },

        // ——— What is shown ———
        get all() { return Object.values(this.byId); },
        shows(i) {
            if (this.sources.length && !this.sources.includes(i.source)) return false;
            if (this.client && i.client !== this.client) return false;
            if (this.q && !(`${i.title || ''} ${i.client || ''} ${i.editor || ''}`).toLowerCase().includes(this.q.toLowerCase())) return false;
            return true;
        },
        get days() {
            const today = iso(new Date());
            const by = {};
            this.all.forEach((i) => { if (i.published_date && this.shows(i)) (by[i.published_date] ??= []).push(i); });
            return Array.from({ length: 42 }, (_, n) => {
                const d = new Date(this.gridStart);
                d.setDate(d.getDate() + n);
                const key = iso(d);
                return { key, day: d.getDate(), inMonth: d.getMonth() === this.month.getMonth(), today: key === today, items: by[key] ?? [] };
            });
        },
        get undatedShown() {
            return this.all.filter((i) => !i.published_date && this.shows(i));
        },
        get monthLabel() {
            return this.month.toLocaleDateString('en-IN', { month: 'long', year: 'numeric' });
        },
        /** This month's videos (plus undated ones), the ones the filters show. */
        get monthItems() {
            const key = monthKey(this.month);
            return this.all.filter((i) => (!i.published_date || i.published_date.slice(0, 7) === key) && this.shows(i));
        },
        get counts() {
            const inMonth = this.monthItems.filter((i) => i.published_date);
            return { total: inMonth.length, out: inMonth.filter((i) => i.status === 'Published').length };
        },
        byDate(a, b) { return (a.published_date || '9').localeCompare(b.published_date || '9') || a.id - b.id; },

        /** Board columns: by status (Notion's own order and colours), by editor, or by client. */
        get board() {
            const items = this.monthItems;
            const make = (key, name, color, own) => ({ key, name, color, items: items.filter(own).sort(this.byDate) });

            if (this.group === 'editor') {
                const people = [...new Set(items.flatMap((i) => this.editorsOf(i)))].sort();
                return [
                    ...people.map((p) => make(p, p, NOTION.blue, (i) => this.editorsOf(i).includes(p))),
                    make('', 'No editor', NOTION.gray, (i) => this.editorsOf(i).length === 0),
                ];
            }
            if (this.group === 'client') {
                const clients = [...new Set(items.map((i) => i.client).filter(Boolean))].sort();
                return [
                    ...clients.map((c) => make(c, c, NOTION.purple, (i) => i.client === c)),
                    make('', 'No client', NOTION.gray, (i) => !i.client),
                ].filter((col) => col.key !== '' || col.items.length);
            }

            const seen = new Set();
            const columns = [];
            (this.sources.length ? this.sources : Object.keys(this.meta.statuses || {})).forEach((source) => {
                (this.meta.statuses?.[source] || []).forEach((option) => {
                    if (seen.has(option.name)) return;
                    seen.add(option.name);
                    columns.push(make(option.name, option.name, NOTION[option.color] ?? NOTION.default, (i) => i.status === option.name));
                });
            });
            const other = make('', 'No status', NOTION.gray, (i) => !seen.has(i.status));
            return other.items.length ? [other, ...columns] : columns;
        },
        get tableRows() { return this.monthItems.slice().sort(this.byDate); },

        editorsOf(i) {
            const working = (i.editing || []).map((e) => e.user?.name).filter(Boolean);
            return working.length ? working : names(i.editor);
        },
        fmtDay(day) {
            return new Date(day).toLocaleDateString('en-IN', { weekday: 'short', day: 'numeric', month: 'short' });
        },
        fmtAt(at) {
            return new Date(at).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' });
        },
        color(i) { return NOTION[i.status_color] ?? NOTION.default; },
        hue(name) { return NOTION[name] ?? NOTION.default; },
        label(source) { return this.meta.sources.find((s) => s.key === source)?.label ?? source; },
        toggleSource(key) {
            this.sources = this.sources.includes(key) ? this.sources.filter((s) => s !== key) : [...this.sources, key];
        },
        setView(v) {
            this.view = v;
            this.remember();
        },
        remember() {
            store.set('planner-filter', { sources: this.sources, client: this.client, view: this.view, group: this.group });
        },

        // ——— Saved views: a filter worth coming back to ———
        saveView() {
            const name = (prompt('Name this view (for example "Janet reels")') || '').trim();
            if (!name) return;
            this.views = [...this.views.filter((v) => v.name !== name), { name, sources: this.sources, client: this.client, q: this.q, view: this.view, group: this.group }];
            store.set('planner-views', this.views);
        },
        useView(v) {
            Object.assign(this, { sources: v.sources || [], client: v.client || '', q: v.q || '', view: v.view || 'calendar', group: v.group || 'status' });
        },
        dropView(name) {
            this.views = this.views.filter((v) => v.name !== name);
            store.set('planner-views', this.views);
        },

        // ——— Moving through months ———
        async go(month) {
            this.month = month;
            this.expanded = null;
            await this.loadMonth(month, !this.loadedMonths[monthKey(month)]);
            this.prefetch();
        },
        shift(n) { this.go(new Date(this.month.getFullYear(), this.month.getMonth() + n, 1)); },
        thisMonth() { const d = new Date(); this.go(new Date(d.getFullYear(), d.getMonth(), 1)); },

        // ——— What a planner lets you edit ———
        field(source, name) { return this.meta.fields?.[source]?.[name] ?? null; },
        options(source, name) {
            return name === 'status' ? (this.meta.statuses?.[source] || []) : (this.field(source, name)?.options || []);
        },
        /** A select's options, with the current value kept even if Notion no longer lists it. */
        optionsWith(source, name, current) {
            const list = this.options(source, name);
            return current && !list.some((o) => o.name === current) ? [{ name: current, color: 'default' }, ...list] : list;
        },
        isSaving(id) { return !!this.saving[id]; },

        // ——— One video, as a side peek ———
        async show(i, push = true) {
            const row = this.byId[i.id] ?? i;
            this.peek = { ...row, loading: true };
            this.peekTab = 'details';
            if (push) this.url(i.id);
            try {
                const d = await this.get(urls.item.replace('__ID__', i.id));
                if (this.peek?.id === i.id) this.peek = d.item;
                this.merge([this.rowOf(d.item)]);
            } catch {
                if (this.peek?.id === i.id) {
                    if (this.peek.title) this.peek.loading = false; else { this.peek = null; this.url(null); }
                }
            }
        },
        close() {
            this.peek = null;
            this.url(null);
        },
        url(id) {
            const u = new URL(location.href);
            if (id) u.searchParams.set('item', id); else u.searchParams.delete('item');
            history.pushState({}, '', u);
        },
        /** The list-row part of a detail, so the store never grows detail-only fields. */
        rowOf(item) {
            const { script, show_notes, history: _h, timeline: _t, portal_script_text: _p, caption_options: _o, caption_budget: _b, ...row } = item;
            return row;
        },

        // ——— Changing a video (Notion first, shown at once) ———
        async save(id, changes) {
            if (!this.canEdit) return;
            const it = this.byId[id] ?? (this.peek?.id === id ? this.peek : null);
            if (!it) return;

            const before = {};
            Object.keys(changes).forEach((k) => { before[k] = k === 'editor' ? it.editor : it[k]; });
            const same = Object.keys(changes).every((k) => (k === 'editor'
                ? names(before[k]).join(',') === [].concat(changes[k] || []).join(',')
                : (before[k] ?? null) === (changes[k] ?? null)));
            if (same) return;

            const shown = { ...changes };
            if ('editor' in shown) shown.editor = [].concat(shown.editor || []).join(', ') || null;
            if ('status' in shown) shown.status_color = this.options(it.source, 'status').find((o) => o.name === shown.status)?.color ?? 'default';
            const undo = { ...before, status_color: it.status_color };
            this.paint(id, shown);

            const body = { ...changes };
            const expected = {};
            ['status', 'client', 'tier', 'ssd', 'title'].forEach((k) => { if (k in changes) expected[k] = before[k] ?? null; });
            if (Object.keys(expected).length) body.expected = expected;
            if ('published_date' in changes) body.from = before.published_date ?? null;

            this.saving[id] = (this.saving[id] || 0) + 1;
            const r = await this.send('PATCH', urls.move.replace('__ID__', id), body).catch(() => ({ ok: false, data: {} }));
            this.saving[id]--;
            if (!this.saving[id]) delete this.saving[id];

            if (r.ok) {
                this.take(r.data.item);
            } else if (r.status === 409 && r.data.item) {
                this.take(r.data.item);
                this.say(r.data.message, true);
            } else {
                this.paint(id, undo);
                this.say(r.data.message || 'Notion did not take the change. Nothing was saved.', true);
            }
        },
        /** Show values on the row and on the open peek. */
        paint(id, values) {
            if (this.byId[id]) Object.assign(this.byId[id], values);
            if (this.peek?.id === id) Object.assign(this.peek, values);
        },
        /** A fresh item from the server replaces what is shown. */
        take(item) {
            const row = this.rowOf(item);
            if (this.byId[item.id]) Object.assign(this.byId[item.id], row); else this.byId[item.id] = row;
            if (this.peek?.id === item.id) this.peek = item;
        },
        toggleEditor(i, name) {
            const now = names(i.editor);
            this.save(i.id, { editor: now.includes(name) ? now.filter((n) => n !== name) : [...now, name] });
        },

        // ——— Drag: a card to another day, or to another column ———
        drag(i, e) {
            if (!this.canEdit) return;
            this.dragId = i.id;
            if (e?.dataTransfer) { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', String(i.id)); }
        },
        endDrag() { this.dragId = null; this.overKey = null; },
        dropDay(dayKey) {
            const id = this.dragId;
            this.endDrag();
            if (id) this.save(id, { published_date: dayKey });
        },
        dropColumn(col) {
            const id = this.dragId;
            this.endDrag();
            const it = id && this.byId[id];
            if (!it) return;
            if (this.group === 'status') {
                if (!col.key) return;
                if (!this.options(it.source, 'status').some((o) => o.name === col.key)) {
                    this.say(`The ${this.label(it.source)} planner has no "${col.key}" status.`, true);
                    return;
                }
                this.save(id, { status: col.key });
            } else if (this.group === 'client') {
                this.save(id, { client: col.key || null });
            } else {
                this.save(id, { editor: col.key ? [col.key] : [] });
            }
        },

        // ——— Captions (Gemini, inside the monthly limit) ———
        cap: { note: '', language: 'english', busy: false, text: '', saved: '', dirty: false, copied: false, cost: null, reused: false },
        openCaption() {
            this.peekTab = 'caption';
            const c = this.cap;
            c.text = this.peek?.caption || '';
            c.saved = c.text;
            c.dirty = false;
            c.cost = null;
        },
        async writeCaptions(fresh = false) {
            const id = this.peek?.id;
            if (!id || this.cap.busy) return;
            this.cap.busy = true;
            const r = await this.send('POST', urls.captions.replace('__ID__', id), { note: this.cap.note || null, language: this.cap.language, fresh })
                .catch(() => ({ ok: false, data: {} }));
            this.cap.busy = false;
            if (!r.ok) { this.say(r.data.message || 'Could not write captions. Try again.', true); return; }
            if (this.peek?.id !== id) return;
            this.peek.caption_options = r.data.options;
            this.peek.caption_budget = r.data.budget;
            this.cap.cost = r.data.cost_usd;
            this.cap.reused = r.data.reused;
        },
        useOption(o) {
            this.cap.text = o.text;
            this.cap.dirty = this.cap.text !== this.cap.saved;
        },
        hashtags(text) { return ((text || '').match(/(^|\s)#[\p{L}\p{N}_]+/gu) || []).length; },
        async saveCaption() {
            const id = this.peek?.id;
            if (!id) return;
            const r = await this.send('PUT', urls.caption.replace('__ID__', id), { caption: this.cap.text.trim() || null })
                .catch(() => ({ ok: false, data: {} }));
            if (!r.ok) { this.say(r.data.message || 'Could not save the caption.', true); return; }
            this.cap.saved = r.data.caption || '';
            this.cap.text = this.cap.saved;
            this.cap.dirty = false;
            this.paint(id, { caption: r.data.caption, has_caption: !!r.data.caption });
            this.say(r.data.message);
        },
        async copyCaption(text) {
            try { await navigator.clipboard.writeText(text); this.cap.copied = true; setTimeout(() => { this.cap.copied = false; }, 1500); } catch { this.say('Copy did not work in this browser. Select the text instead.', true); }
        },

        // ——— A new video ———
        startNew(preset = {}) {
            if (!this.canEdit) return;
            const source = this.sources.length === 1 ? this.sources[0] : (this.meta.sources[0]?.key ?? 'reel');
            this.draft = { source, title: '', published_date: null, status: '', client: this.client || '', ...preset };
            this.$nextTick(() => this.$refs.newTitle?.focus());
        },
        async create() {
            if (!this.draft?.title.trim() || this.creating) return;
            this.creating = true;
            const body = Object.fromEntries(Object.entries(this.draft).filter(([, v]) => v !== '' && v !== null));
            const r = await this.send('POST', urls.store, body).catch(() => ({ ok: false, data: {} }));
            this.creating = false;
            if (!r.ok) {
                this.say(r.data.message || 'Notion did not create it. Nothing was saved.', true);
                return;
            }
            this.draft = null;
            this.take(r.data.item);
            this.say('Created in Notion.');
            this.show(r.data.item);
        },

        // ——— Keys ———
        key(e) {
            const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName) || e.target.isContentEditable;
            if (e.key === 'Escape') {
                if (typing) e.target.blur();
                else if (this.draft) this.draft = null;
                else if (this.peek) this.close();
                else if (this.expanded) this.expanded = null;
                return;
            }
            if (typing || e.metaKey || e.ctrlKey || e.altKey) return;
            const act = {
                '/': () => this.$refs.search?.focus(),
                ArrowLeft: () => this.shift(-1),
                ArrowRight: () => this.shift(1),
                t: () => this.thisMonth(),
                n: () => this.startNew(),
                1: () => this.setView('calendar'),
                2: () => this.setView('board'),
                3: () => this.setView('table'),
            }[e.key];
            if (act) { e.preventDefault(); act(); }
        },

        // ——— Small things ———
        say(text, bad = false) {
            clearTimeout(this.toastTimer);
            this.toast = { text, bad };
            this.toastTimer = setTimeout(() => { this.toast = null; }, bad ? 7000 : 3000);
        },
        async refresh() {
            this.refreshing = true;
            const r = await this.send('POST', urls.refresh, { background: true }).catch(() => ({ ok: false, data: {} }));
            this.say(r.ok ? 'Refreshing from Notion. Changes appear here as they arrive.' : 'Refresh failed. Try again.', !r.ok);
            // The sync runs after the response; look for what it brought a few times.
            [6000, 14000, 24000].forEach((ms, n, all) => setTimeout(async () => {
                await this.poll();
                if (n === all.length - 1) {
                    this.refreshing = false;
                    const meta = await this.get(urls.meta).catch(() => null);
                    if (meta) this.meta = meta;
                }
            }, ms));
        },
        ago(at) {
            if (!at) return 'never';
            const m = Math.round((Date.now() - new Date(at)) / 60000);
            return m < 1 ? 'just now' : m < 60 ? `${m} min ago` : `${Math.round(m / 60)} h ago`;
        },
    };
}
