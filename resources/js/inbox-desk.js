/*
 * The Inbox Check screen (resources/views/inbox-desk/index.blade.php).
 *
 * One tap per tile, saved the moment it is made -- no form, no Save button.
 * The tile turns green before the server answers (optimistic), and the
 * server's answer is the whole fresh board (App\Services\InboxDesk::board()),
 * which simply replaces ours, so the screen can never drift from the
 * database for longer than one round trip.
 *
 * "Live": while the page is on screen it re-reads the board every 15 seconds,
 * and at once whenever the phone comes back to it. A tile somebody else
 * ticked in the meantime glows for a moment and gets a toast -- that is what
 * an owner watching from their phone is here to see.
 */
const POLL_MS = 15000;

export function inboxDesk(config) {
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content;

    return {
        b: config.board,
        routes: config.routes,
        filter: 'all',
        expanded: null,
        counts: {},
        pending: {},
        flash: {},
        toasts: [],
        syncedAt: Date.now(),
        now: Date.now(),
        offline: false,
        timer: null,

        init() {
            this.schedule();
            setInterval(() => { this.now = Date.now(); }, 5000);
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible') this.refresh();
            });
        },

        // ---- reading ---------------------------------------------------------
        get pct() {
            const { done, total } = this.b.totals;
            return total ? Math.round((done / total) * 100) : 0;
        },
        get allDone() {
            return this.b.totals.total > 0 && this.b.totals.left === 0;
        },
        get ringOffset() {
            const c = 2 * Math.PI * 52;
            return c - (c * this.pct) / 100;
        },
        get syncLabel() {
            if (this.offline) return 'Offline — retrying';
            const s = Math.max(0, Math.round((this.now - this.syncedAt) / 1000));
            if (s < 10) return 'Live · just now';
            if (s < 60) return `Live · ${s}s ago`;
            return `Updated ${Math.round(s / 60)} min ago`;
        },
        accountsFor(routine) {
            return routine.accounts.filter((a) => {
                if (this.filter === 'todo') return a.left > 0;
                if (this.filter === 'done') return a.total > 0 && a.left === 0;
                return true;
            });
        },
        count(which) {
            const all = this.b.routines.flatMap((r) => r.accounts);
            if (which === 'todo') return all.filter((a) => a.left > 0).length;
            if (which === 'done') return all.filter((a) => a.total > 0 && a.left === 0).length;
            return all.length;
        },
        tileKey(routine, account, cp) { return `${routine.id}|${account.key}|${cp.id}`; },
        cell(account, cp) { return account.cells[cp.id] || { state: 'none' }; },
        hint(account, cp) { return (account.activity || {})[cp.id] || null; },
        initials(handle) { return (handle || '?').replace('@', '').slice(0, 2).toUpperCase(); },

        // ---- tapping a tile --------------------------------------------------
        tap(routine, account, cp) {
            const cell = this.cell(account, cp);
            const key = this.tileKey(routine, account, cp);
            if (cell.state === 'none' || this.pending[key]) return;

            // A plain check with nothing to count is one tap, start to end.
            if (cell.state === 'open' && !cp.field) {
                this.check(routine, account, cp, null);
                return;
            }
            if (this.expanded === key) {
                this.expanded = null;
                return;
            }
            if (cell.state === 'open' && this.counts[key] === undefined) {
                const h = this.hint(account, cp);
                this.counts[key] = h && h.count > 0 ? h.count : 0;
            }
            this.expanded = key;
        },
        step(key, by) {
            this.counts[key] = Math.max(0, Math.min(9999, (parseInt(this.counts[key], 10) || 0) + by));
        },

        async check(routine, account, cp, count) {
            const cell = this.cell(account, cp);
            const key = this.tileKey(routine, account, cp);
            if (cell.state !== 'open') return;

            const id = cell.id;
            this.pending[key] = true;
            this.expanded = null;
            // Optimistic: green now, the server's board replaces it in a moment.
            Object.assign(cell, { state: 'done', by: 'You', at_label: 'now', count, can_undo: false });
            account.left = Math.max(0, account.left - 1);
            this.b.totals.done++;
            this.b.totals.left = Math.max(0, this.b.totals.left - 1);
            if (navigator.vibrate) navigator.vibrate(12);

            const data = await this.call(this.routes.check.replace('__ID__', id), { count });
            delete this.pending[key];
            delete this.counts[key];
            if (data) {
                this.toast(`${cp.short} checked · ${account.handle}`, () => this.undoById(id, key));
            }
        },

        // Every open tile on one account, "nothing new" on each.
        async clearAccount(routine, account) {
            for (const cp of routine.checkpoints) {
                if (this.cell(account, cp).state === 'open') {
                    await this.check(routine, account, cp, cp.field ? 0 : null);
                }
            }
        },

        async undo(routine, account, cp) {
            const cell = this.cell(account, cp);
            await this.undoById(cell.id, this.tileKey(routine, account, cp));
        },
        async undoById(id, key) {
            this.pending[key] = true;
            this.expanded = null;
            const data = await this.call(this.routes.undo.replace('__ID__', id), {});
            delete this.pending[key];
            if (data) this.toast('Undone — back on the list.');
        },

        // ---- talking to the server ------------------------------------------
        async call(url, body) {
            try {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrf(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(body),
                });
                const data = await response.json().catch(() => ({}));
                if (response.status === 419) throw new Error('Your session expired. Reload the page.');
                if (!response.ok) throw new Error(data.message || 'That did not save. Try again.');
                this.apply(data.board, false);
                return data;
            } catch (error) {
                this.toast(error.message, null, 'error');
                this.refresh();
                return null;
            }
        },

        async refresh() {
            if (Object.keys(this.pending).length) return;
            try {
                const response = await fetch(this.routes.state, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!response.ok) throw new Error(String(response.status));
                const board = await response.json();
                // A tap made while this was in flight wins over what it read.
                if (Object.keys(this.pending).length) return;
                this.apply(board, true);
                this.offline = false;
            } catch (e) {
                this.offline = true;
            }
        },

        schedule() {
            clearTimeout(this.timer);
            this.timer = setTimeout(async () => {
                if (document.visibilityState === 'visible') await this.refresh();
                this.schedule();
            }, POLL_MS);
        },

        /*
         * Replace the board. From a poll, anything that went from open to
         * done since the last read was somebody else's doing -- our own taps
         * come back through call() -- so it glows and is announced.
         */
        apply(board, fromPoll) {
            if (fromPoll) {
                const before = {};
                this.b.routines.forEach((r) => r.accounts.forEach((a) => r.checkpoints.forEach((cp) => {
                    before[this.tileKey(r, a, cp)] = this.cell(a, cp).state;
                })));

                const news = [];
                board.routines.forEach((r) => r.accounts.forEach((a) => r.checkpoints.forEach((cp) => {
                    const key = this.tileKey(r, a, cp);
                    const now = (a.cells[cp.id] || {}).state;
                    if (before[key] === 'open' && now === 'done') {
                        news.push(`${a.cells[cp.id].by || 'Someone'} checked ${cp.short} · ${a.handle}`);
                        this.flash[key] = true;
                        setTimeout(() => { delete this.flash[key]; }, 2600);
                    }
                })));

                if (news.length === 1) this.toast(news[0]);
                else if (news.length > 1) this.toast(`${news.length} new checks just came in`);
            }

            this.b = board;
            this.syncedAt = Date.now();
            this.now = Date.now();
        },

        // ---- toasts -----------------------------------------------------------
        toast(text, undo = null, tone = 'ok') {
            const id = Date.now() + Math.random();
            this.toasts.push({ id, text, undo, tone });
            setTimeout(() => this.dismiss(id), undo ? 6000 : 3500);
        },
        dismiss(id) { this.toasts = this.toasts.filter((t) => t.id !== id); },
        async runUndo(t) {
            this.dismiss(t.id);
            await t.undo();
        },
    };
}
