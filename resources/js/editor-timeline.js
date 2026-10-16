/*
 * The live timeline on Editor Updates (editor-updates/_timeline.blade.php):
 * one lane per editor across the day -- blocks of time Working and On hold,
 * a marker for every move, and the editor's own animated icon standing
 * where they are now. While today is shown it reads editor-updates/live
 * every 15 seconds, the same moves the "Editor Updates" pane in Claude Code
 * polls for: new markers pop in, the icon walks to its new place, and the
 * newest moves run along the ticker.
 */
const EVERY_MS = 15_000;

const ICONS = {
    added: '＋', started: '▶', fix: '⚠', fixed: '✓', hold: '⏸', moved: '→',
    done: '✔', ready: '🕒', posted: '🚀', other: '•',
};

export function editorTimeline(initial, url, editorUrl = '') {
    return {
        editorUrl, // one editor's day, with __ID__ for their user id
        data: initial,
        fresh: {}, // marker ids that arrived on the last read, to pop in
        ticker: [],
        seen: new Set(),
        clock: Date.now(),
        received: Date.now(), // when data.now was true, to move the now line between reads
        loading: false,
        failed: false,

        init() {
            this.data.lanes.forEach((l) => l.marks.forEach((m) => this.seen.add(m.id)));
            if (!this.data.is_today) return;
            setInterval(() => { this.clock = Date.now(); }, 20_000);
            setInterval(() => { if (!document.hidden) this.read(); }, EVERY_MS);
            document.addEventListener('visibilitychange', () => { if (!document.hidden) this.read(); });
        },

        async read() {
            if (this.loading) return;
            this.loading = true;
            try {
                const r = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (!r.ok) throw new Error(r.status);
                const next = await r.json();
                const fresh = {};
                next.lanes.forEach((l) => l.marks.forEach((m) => {
                    if (!this.seen.has(m.id)) {
                        fresh[m.id] = true;
                        this.seen.add(m.id);
                        this.ticker.unshift({ ...m, who: l.name, hue: l.hue, key: `${m.id}-${Date.now()}` });
                    }
                }));
                this.ticker = this.ticker.slice(0, 4);
                this.fresh = fresh;
                this.data = next;
                this.clock = Date.now();
                this.received = this.clock;
                this.failed = false;
                setTimeout(() => { this.fresh = {}; }, 2500);
            } catch {
                this.failed = true;
            } finally {
                this.loading = false;
            }
        },

        // ——— Geometry: a time on the day's axis, as a percentage ———
        x(ms) {
            const { from, to } = this.data;
            return Math.max(0, Math.min(100, ((ms - from) / (to - from)) * 100));
        },
        width(b) {
            return Math.max(0.6, this.x(b.to) - this.x(b.from));
        },
        get hours() {
            const out = [];
            const start = new Date(this.data.from);
            start.setMinutes(0, 0, 0);
            for (let t = start.getTime(); t <= this.data.to; t += 3_600_000) {
                if (t >= this.data.from) {
                    const d = new Date(t);
                    const h = d.getHours();
                    out.push({ at: t, label: `${h % 12 || 12}${h < 12 ? 'a' : 'p'}` });
                }
            }
            return out;
        },
        get nowX() {
            return this.data.is_today ? this.x(this.data.now + (this.clock - this.received)) : null;
        },
        avatarX(lane) {
            // Working or on hold: they stand at now, which moves between reads.
            if (this.data.is_today && ['working', 'hold'].includes(lane.state)) return this.nowX;
            return lane.at ? this.x(lane.at) : 0;
        },

        // ——— Words and looks ———
        icon(m) {
            return m.label === 'Delivered' ? '📦' : (ICONS[m.type] ?? '•');
        },
        tip(m) {
            return `${m.time} · ${m.label}${m.detail ? ` (${m.detail})` : ''} — ${m.title}${m.by ? ` · by ${m.by}` : ''}`;
        },
        stateWord(lane) {
            return {
                working: 'Editing', hold: 'On hold', celebrate: 'Just posted!', active: 'Active', idle: 'Idle',
            }[lane.state] ?? '';
        },
        // ——— The board on top: what needs the admin, and each editor ———
        needLook(n) {
            return {
                late: { icon: '⚠', chip: 'bg-red-500/20 text-red-200', text: 'text-red-200', href: '#ready-queue', cta: 'Open' },
                review: { icon: '◷', chip: 'bg-sky-400/15 text-sky-200', text: 'text-white', href: '#review-queue', cta: 'Review' },
                hold: { icon: '⏸', chip: 'bg-violet-400/15 text-violet-200', text: 'text-white', href: null, cta: '' },
                long: { icon: '⏳', chip: 'bg-amber-400/15 text-amber-200', text: 'text-white', href: null, cta: '' },
                idle: { icon: '💤', chip: 'bg-white/10 text-brand-100/70', text: 'text-white', href: null, cta: '' },
            }[n.kind] ?? { icon: '•', chip: 'bg-white/10', text: 'text-white', href: null, cta: '' };
        },
        needTitle(n) {
            return {
                late: `Due and not out: ${n.text}`,
                review: `${n.count} ${n.count === 1 ? 'video' : 'videos'} waiting for your review`,
                hold: `${n.person}: ${n.count} on hold`,
                long: `${n.person} has been on one video a long time`,
                idle: n.text,
            }[n.kind] ?? n.text;
        },
        needLine(n) {
            return {
                late: `${n.person ?? 'Editor'} · ${n.detail}`,
                review: `Oldest: ${n.text}${n.person ? ` (${n.person})` : ''} · ${n.detail.split(' · ').pop()}`,
                hold: `${n.text} · ${n.detail.split(' · ').slice(1).join(' · ')}`,
                long: `${n.text} · ${n.detail}`,
                idle: n.detail,
            }[n.kind] ?? n.detail;
        },
        span(min) {
            if (min === null || min === undefined) return '';
            return min >= 60 ? `${Math.floor(min / 60)}h ${min % 60}m` : `${min}m`;
        },
        /** Minutes since a moment, counting on between reads. */
        runFor(ms) {
            if (!ms) return null;
            return Math.max(0, Math.floor((this.data.now + (this.clock - this.received) - ms) / 60000));
        },
        // ——— The designed top: links, the day's flow, an editor card's look ———
        needHref(n) {
            if (n.kind === 'late') return '#ready-queue';
            if (n.kind === 'review') return '#review-queue';
            return n.user_id && this.editorUrl ? this.editorUrl.replace('__ID__', n.user_id) : null;
        },
        needCta(n) {
            return { late: 'Open', review: 'Review now', hold: 'See holds', long: 'Open day', idle: 'Open day' }[n.kind] ?? 'Open';
        },
        look(ed) {
            if (ed.state === 'working') {
                return { word: 'Editing', dot: '#F4B740', ring: 'border-amber-400/45', pill: 'bg-amber-400/15 text-amber-200' };
            }
            if (ed.state === 'hold') {
                return { word: 'All on hold', dot: '#A78BFA', ring: 'border-violet-400/35', pill: 'bg-violet-400/15 text-violet-200' };
            }
            if (!ed.last) {
                return { word: 'Not started', dot: '#8ACCE0', ring: 'border-white/[0.08]', pill: 'bg-brand-300/15 text-brand-200' };
            }
            return { word: ed.idle !== null ? `Idle ${this.span(ed.idle)}` : 'Idle', dot: '#7f9aa8', ring: 'border-white/[0.08]', pill: 'bg-white/10 text-brand-100/80' };
        },
        /** An editor's photo by name, from the board or the lanes; null draws their initial. */
        photoOf(name) {
            return this.data.board?.editors.find((e) => e.person === name)?.photo
                ?? this.data.lanes.find((l) => l.name === name)?.photo ?? null;
        },
        hueOf(name) {
            let h = 0;
            for (const c of name || '') h = (h * 31 + c.charCodeAt(0)) >>> 0;
            return h % 360;
        },
        laneOf(name) {
            return this.data.lanes.find((l) => l.name === name);
        },

        hm(min) {
            return min >= 60 ? `${Math.floor(min / 60)}h ${min % 60}m` : `${min}m`;
        },
        color(lane, alpha = 1) {
            return `hsl(${lane.hue} 70% 58% / ${alpha})`;
        },
    };
}
