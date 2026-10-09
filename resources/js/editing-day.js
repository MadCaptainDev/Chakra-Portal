/*
 * My Editing (my/editing.blade.php): an editor's videos as cards that move
 * To do → Working → Done → Posted with a tap, corrections as branches on a
 * card. Every action is a JSON call to My\EditingController, which answers
 * with the card as it now stands; the card is swapped in place.
 */
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

async function post(url, body = {}) {
    const response = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        if (response.status === 419) throw new Error('Your session expired. Reload the page.');
        throw new Error((data.errors && Object.values(data.errors).flat()[0]) || data.message || 'That did not work. Try again.');
    }
    return data;
}

export function editingDay({ cards, suggestions, hints, kinds, urls }) {
    return {
        cards,
        suggestions,
        hints,
        kinds,
        title: '',
        busy: {},
        error: '',
        flash: '',
        now: Date.now(),
        showPlanner: suggestions.length > 0,
        fixing: null, // card id with the correction box open
        fixText: '',
        links: {},
        refreshing: false,

        init() {
            setInterval(() => { this.now = Date.now(); }, 30000);
        },

        // ——— Lanes ———
        lane(name) {
            return this.cards.filter((c) => {
                if (name === 'todo') return ['waiting', 'blocked'].includes(c.status);
                if (name === 'working') return c.status === 'started';
                if (name === 'done') return c.status === 'completed' && !c.posted_at && c.kind !== 'none';
                if (name === 'posted') return !!c.posted_at || (c.status === 'completed' && c.kind === 'none');
                return false;
            });
        },
        get openCount() {
            return this.cards.filter((c) => !c.posted_at && c.status !== 'completed').length;
        },

        // ——— Time spent ———
        timer(card) {
            let minutes = card.working_minutes;
            if (card.status === 'started' && card.working_since) {
                // working_minutes already counts up to page load; add since then.
                minutes += Math.max(0, Math.floor((this.now - this.loadedAt) / 60000));
            }
            const h = Math.floor(minutes / 60);
            const m = minutes % 60;
            return h ? `${h}h ${m}m` : `${m}m`;
        },
        loadedAt: Date.now(),

        openFixes(card) {
            return card.corrections.filter((c) => c.status !== 'completed').length;
        },

        // ——— Calls ———
        async run(key, fn) {
            this.error = '';
            this.busy[key] = true;
            try {
                await fn();
            } catch (e) {
                this.error = e.message;
                setTimeout(() => { if (this.error === e.message) this.error = ''; }, 6000);
            } finally {
                this.busy[key] = false;
            }
        },
        put(card) {
            const i = this.cards.findIndex((c) => c.id === card.id);
            i >= 0 ? this.cards.splice(i, 1, card) : this.cards.push(card);
        },
        say(text) {
            this.flash = text;
            setTimeout(() => { if (this.flash === text) this.flash = ''; }, 3000);
        },

        add() {
            const title = this.title.trim();
            if (!title) return;
            this.run('add', async () => {
                const { card, existing } = await post(urls.store, { title });
                this.put(card);
                this.title = '';
                this.say(existing ? 'Already on your board.' : 'Added.');
            });
        },
        addFromPlanner(s) {
            this.run(`s${s.id}`, async () => {
                const { card } = await post(urls.store, { content_item_id: s.id });
                this.put(card);
                this.suggestions = this.suggestions.filter((x) => x.id !== s.id);
                this.say(`Added: ${s.title}`);
            });
        },
        prefill(client) {
            this.title = `${client} - `;
            this.$nextTick(() => this.$refs.add.focus());
        },
        act(card, action, extra = {}) {
            this.run(`${card.id}${action}`, async () => {
                const { card: fresh } = await post(urls.act.replace('__ID__', card.id), { action, ...extra });
                this.put(fresh);
            });
        },
        toggleFix(card, fix) {
            this.run(`f${fix.id}`, async () => {
                const { card: fresh } = await post(urls.act.replace('__ID__', fix.id), { action: fix.status === 'completed' ? 'start' : 'done' });
                this.put(fresh);
            });
        },
        addFix(card) {
            const title = this.fixText.trim();
            if (!title) return;
            this.run(`fix${card.id}`, async () => {
                const { card: fresh } = await post(urls.correct.replace('__ID__', card.id), { title });
                this.put(fresh);
                this.fixText = '';
                this.fixing = null;
            });
        },
        refreshNotion() {
            this.refreshing = true;
            post(urls.refresh)
                .then(() => window.location.reload())
                .catch((e) => {
                    this.error = e.message;
                    this.refreshing = false;
                });
        },
    };
}
