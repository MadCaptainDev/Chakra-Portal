/*
 * My Editing (my/editing.blade.php): an editor's videos as cards that move
 * To do → Working → Done → Posted with a tap, corrections as branches on a
 * card. Every action is a JSON call to My\EditingController, which answers
 * with the card as it now stands; the card is swapped in place. A video can
 * be put on hold (with a reason) or moved on to the next day, which lifts it
 * into a strip under the board until that day comes.
 *
 * The add box is a typeahead: as the editor types it offers Reel Planner
 * items (theirs first, anyone's by name), their usual client prefixes and
 * titles they have used before. On a phone the four lanes become tabs, and
 * the tab follows a card when it moves so it never vanishes from view.
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

/** Which lane a card sits in; "later" is the moved-on strip under the board; null for cancelled. */
function laneOf(c) {
    if (c.next_on) return 'later';
    if (c.posted_at) return 'posted';
    if (c.status === 'completed') return 'done'; // under review
    if (c.status === 'started') return 'working';
    if (['waiting', 'blocked'].includes(c.status)) return 'todo';
    return null;
}

export function editingDay({ cards, planner, hints, kinds, urls }) {
    // Lower-cased text to search each planner item by, worked out once.
    planner.forEach((p) => { p.hay = `${p.title} ${p.client ?? ''} ${p.editor ?? ''}`.toLowerCase(); });

    return {
        cards,
        planner,
        hints,
        kinds,
        title: '',
        busy: {},
        error: '',
        flash: '',
        now: Date.now(),
        loadedAt: Date.now(),
        showPlanner: false,
        tab: 'todo',
        open: false, // the typeahead list
        hi: -1, // highlighted row; -1 is the typed text itself
        fixing: null, // card id with the correction box open
        fixText: '',
        holding: null, // card id with the hold box open
        holdText: '',
        links: {},
        refreshing: false,

        init() {
            setInterval(() => { this.now = Date.now(); }, 30000);
            this.tab = this.lane('working').length ? 'working' : 'todo';
            this.showPlanner = this.mine.length > 0 && this.openCount === 0;
        },

        // ——— Lanes ———
        lane(name) {
            return this.cards.filter((c) => laneOf(c) === name);
        },
        get openCount() {
            return this.cards.filter((c) => !c.posted_at && c.status !== 'completed' && !c.next_on).length;
        },
        get mine() {
            return this.planner.filter((p) => p.mine);
        },

        // ——— Typeahead ———
        get matches() {
            const q = this.title.trim().toLowerCase();
            if (!q) {
                return this.mine.slice(0, 6).map((p) => ({ type: 'planner', key: `p${p.id}`, item: p }));
            }
            const words = q.split(/\s+/);
            const hit = (text) => words.every((w) => text.includes(w));
            const out = [];

            if (!q.includes(' - ')) {
                this.hints.clients
                    .filter((c) => c.toLowerCase().startsWith(q) && c.toLowerCase() !== q)
                    .slice(0, 3)
                    .forEach((c) => out.push({ type: 'client', key: `c${c}`, text: c }));
            }

            const found = this.planner.filter((p) => hit(p.hay));
            const starts = (p) => (p.title.toLowerCase().startsWith(q) ? 1 : 0);
            found.sort((a, b) => (b.mine - a.mine) || (starts(b) - starts(a)));
            const shown = found.slice(0, 6);
            shown.forEach((p) => out.push({ type: 'planner', key: `p${p.id}`, item: p }));

            const taken = new Set(shown.map((p) => p.title.toLowerCase()));
            this.hints.titles
                .filter((t) => hit(t.toLowerCase()) && !taken.has(t.toLowerCase()) && t.toLowerCase() !== q)
                .slice(0, 4)
                .forEach((t) => out.push({ type: 'title', key: `t${t}`, text: t }));

            return out;
        },
        typed() {
            this.open = true;
            this.hi = -1;
        },
        move(step) {
            const n = this.matches.length;
            if (!n) return;
            this.open = true;
            // Cycle through -1 (what was typed) and every row.
            this.hi = ((this.hi + 1 + step + n + 1) % (n + 1)) - 1;
        },
        enter() {
            const pick = this.open && this.hi >= 0 ? this.matches[this.hi] : null;
            if (pick) this.choose(pick);
            else this.add();
        },
        choose(m) {
            this.hi = -1;
            if (m.type === 'planner') {
                this.open = false;
                this.title = '';
                this.addFromPlanner(m.item);
                return;
            }
            this.title = m.type === 'client' ? `${m.text} - ` : m.text;
            this.open = m.type === 'client';
            this.$nextTick(() => {
                const el = this.$refs.add;
                el.focus();
                el.setSelectionRange(el.value.length, el.value.length);
            });
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
            if (i >= 0) this.cards.splice(i, 1, card);
            else this.cards.push(card);
            // On a phone only one lane shows: follow the card to where it went.
            const lane = laneOf(card);
            if (lane && lane !== 'later') this.tab = lane;
        },
        say(text) {
            this.flash = text;
            setTimeout(() => { if (this.flash === text) this.flash = ''; }, 3000);
        },

        add() {
            const title = this.title.trim();
            if (!title) return;
            this.open = false;
            // Typed a planner title out in full? Add it linked, as if picked.
            const same = this.planner.find((p) => p.title.toLowerCase() === title.toLowerCase());
            if (same) {
                this.title = '';
                this.addFromPlanner(same);
                return;
            }
            this.run('add', async () => {
                const { card, existing } = await post(urls.store, { title });
                this.put(card);
                this.title = '';
                this.say(existing ? 'Already on your board.' : 'Added.');
            });
        },
        addFromPlanner(s) {
            this.run(`s${s.id}`, async () => {
                const { card, existing } = await post(urls.store, { content_item_id: s.id });
                this.put(card);
                this.planner = this.planner.filter((x) => x.id !== s.id);
                this.say(existing ? 'Already on your board.' : `Added: ${s.title}`);
            });
        },
        act(card, action, extra = {}) {
            this.run(`${card.id}${action}`, async () => {
                const { card: fresh } = await post(urls.act.replace('__ID__', card.id), { action, ...extra });
                this.put(fresh);
                const said = { done: 'Sent for review.', post: 'Posted ✓', deliver: 'Delivered ✓' }[action];
                if (said) this.say(said);
            });
        },
        hold(card, note = this.holdText) {
            this.run(`${card.id}hold`, async () => {
                const { card: fresh } = await post(urls.act.replace('__ID__', card.id), { action: 'hold', note: note.trim() || null });
                this.put(fresh);
                this.holding = null;
                this.holdText = '';
                this.say('On hold.');
            });
        },
        // "next" moves it off today's lanes to the next day; "today" brings it back.
        moveOn(card, action) {
            this.run(`${card.id}${action}`, async () => {
                const { card: fresh } = await post(urls.act.replace('__ID__', card.id), { action });
                this.put(fresh);
                this.say(action === 'next' ? `Moved to ${(fresh.next_on ?? 'the next day').toLowerCase()}.` : 'Back on today’s board.');
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
