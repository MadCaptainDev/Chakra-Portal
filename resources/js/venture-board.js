/*
 * "My ventures" on the admin dashboard (dashboard/_ventures.blade.php): how
 * many videos each chosen venture has at every stage, refreshed in place.
 * Numbers come from VentureBoardController::data; Refresh asks for a Notion
 * sync after the response and then watches for it to land.
 */
const csrf = () => document.querySelector('meta[name=csrf-token]').content;

export function ventureBoard(urls, initial) {
    return {
        board: initial,
        busy: false,
        refreshing: false,
        stamp: Date.now(),
        picker: false,
        settingsOpen: false,
        all: [],
        chosen: [],
        search: '',
        settings: null,
        saving: false,
        notice: '',
        canNotify: 'Notification' in window ? Notification.permission : 'unsupported',

        init() {
            setInterval(() => { if (!document.hidden) this.load(true); }, 60_000);
            setInterval(() => { this.stamp = Date.now(); }, 30_000);
        },

        async send(method, url, body) {
            const r = await fetch(url, {
                method,
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
                body: body ? JSON.stringify(body) : undefined,
            });
            const data = await r.json().catch(() => ({}));
            if (!r.ok) throw new Error(data.message || 'Something went wrong. Try again.');
            return data;
        },

        async load(quiet = false) {
            if (!quiet) this.busy = true;
            try {
                this.board = await this.send('GET', urls.data);
            } catch { /* keep what is shown */ } finally {
                this.busy = false;
            }
        },

        /** Sync from Notion, then watch the numbers until the new sync lands. */
        async refresh() {
            if (this.refreshing) return;
            this.refreshing = true;
            const before = this.board.last_synced_at;
            try {
                await this.send('POST', urls.refresh);
            } catch (e) {
                this.flash(e.message);
                this.refreshing = false;
                return;
            }
            for (let n = 0; n < 15; n++) {
                await new Promise((r) => setTimeout(r, n < 3 ? 3000 : 5000));
                await this.load(true);
                if (this.board.last_synced_at !== before && !this.board.refreshing) break;
            }
            this.refreshing = false;
            this.flash(this.board.last_synced_at !== before ? 'Up to date with Notion.' : 'Notion was already up to date.');
        },

        // ——— Choosing ventures ———
        async openPicker() {
            this.picker = true;
            if (!this.all.length) {
                const d = await this.send('GET', urls.all).catch(() => null);
                if (d) { this.all = d.ventures; this.chosen = d.chosen.length ? d.chosen : this.board.rows.map((r) => r.venture); this.settings = d.settings; }
            } else {
                this.chosen = this.board.chosen ? this.board.rows.map((r) => r.venture) : this.chosen;
            }
            this.$nextTick(() => this.$refs.ventureSearch?.focus());
        },
        get filtered() {
            const q = this.search.trim().toLowerCase();
            return this.all.filter((v) => !q || v.name.toLowerCase().includes(q));
        },
        isChosen(name) { return this.chosen.includes(name); },
        toggle(name) {
            this.chosen = this.isChosen(name) ? this.chosen.filter((v) => v !== name) : [...this.chosen, name];
        },
        move(name, by) {
            const i = this.chosen.indexOf(name);
            const j = i + by;
            if (i < 0 || j < 0 || j >= this.chosen.length) return;
            const list = [...this.chosen];
            [list[i], list[j]] = [list[j], list[i]];
            this.chosen = list;
        },
        async saveChoice() {
            this.saving = true;
            try {
                this.board = await this.send('PUT', urls.choose, { ventures: this.chosen });
                this.picker = false;
                this.flash(this.chosen.length ? `Watching ${this.chosen.length} ${this.chosen.length === 1 ? 'venture' : 'ventures'}.` : 'Showing the busiest ventures.');
            } catch (e) {
                this.flash(e.message);
            } finally {
                this.saving = false;
            }
        },

        // ——— Notifications ———
        async openSettings() {
            this.settingsOpen = true;
            if (!this.settings) {
                const d = await this.send('GET', urls.all).catch(() => null);
                if (d) { this.all = d.ventures; this.chosen = d.chosen; this.settings = d.settings; }
            }
        },
        async saveSettings() {
            this.saving = true;
            try {
                const d = await this.send('PUT', urls.settings, { ...this.settings, low_stock: Number(this.settings.low_stock) });
                this.settings = d.settings;
                this.settingsOpen = false;
                this.flash('Notification settings saved.');
                this.load(true);
            } catch (e) {
                this.flash(e.message);
            } finally {
                this.saving = false;
            }
        },
        async sendTest() {
            try {
                const d = await this.send('POST', urls.test);
                this.flash(d.message);
            } catch (e) {
                this.flash(e.message);
            }
        },
        async allowDesktop() {
            if (!('Notification' in window)) return;
            this.canNotify = await Notification.requestPermission();
            this.flash(this.canNotify === 'granted' ? 'Desktop notifications are on while the portal is open.' : 'The browser did not allow desktop notifications.');
        },

        // ——— Showing ———
        flash(text) {
            this.notice = text;
            setTimeout(() => { if (this.notice === text) this.notice = ''; }, 5000);
        },
        ago(at) {
            void this.stamp;
            if (!at) return 'never';
            const m = Math.round((Date.now() - new Date(at)) / 60000);
            return m < 1 ? 'just now' : m < 60 ? `${m} min ago` : m < 1440 ? `${Math.round(m / 60)} h ago` : `${Math.round(m / 1440)} d ago`;
        },
        day(date) {
            return date ? new Date(date).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }) : '—';
        },
        initials(name) {
            return (name || '?').replace(/[^A-Za-z0-9 ]/g, '').split(' ').filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase() || '?';
        },
        /** A stage's share of the venture's open videos, for the bar. */
        share(row, key) {
            const open = Object.values(row.stages).reduce((a, b) => a + b, 0);
            return open ? (row.stages[key] / open) * 100 : 0;
        },
        plannerUrl(venture) {
            return `${urls.planner}?view=board&client=${encodeURIComponent(venture)}`;
        },

        // ——— The list of videos on each card ———
        open: {},
        platforms: [
            { key: 'reel', icon: 'Reel', chip: 'bg-pink-400/15 text-pink-200' },
            { key: 'youtube', icon: 'YouTube', chip: 'bg-red-400/15 text-red-200' },
            { key: 'post', icon: 'Post', chip: 'bg-sky-400/15 text-sky-200' },
            { key: 'story', icon: 'Story', chip: 'bg-amber-400/15 text-amber-200' },
        ],
        isOpen(venture) { return !!this.open[venture]; },
        toggleOpen(venture) { this.open[venture] = !this.open[venture]; },
        stageColor(key) {
            if (key === 'out') return '#34d399';
            return (this.board.stages.find((s) => s.key === key) || {}).color || '#94a3b8';
        },
        stageLabel(key) {
            if (key === 'out') return 'Out';
            return (this.board.stages.find((s) => s.key === key) || {}).label || 'Other';
        },
    };
}
