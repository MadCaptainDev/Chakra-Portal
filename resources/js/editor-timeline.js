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

export function editorTimeline(initial, url) {
    return {
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
            setInterval(() => { this.clock = Date.now(); }, 30_000);
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
        hm(min) {
            return min >= 60 ? `${Math.floor(min / 60)}h ${min % 60}m` : `${min}m`;
        },
        color(lane, alpha = 1) {
            return `hsl(${lane.hue} 70% 58% / ${alpha})`;
        },
    };
}
