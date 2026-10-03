/*
 * The homepage's "What we do" explorer (home/_services) and "Where the hours
 * go" pipeline (home/_hours).
 *
 * Both play themselves -- one tab after another -- once they scroll into
 * view, and stop for good the moment someone taps, clicks or hovers a tab:
 * from then on the visitor is driving. Everything is server-rendered at its
 * finished state first, so without this script (or with reduced motion) the
 * page still reads; the animations are CSS, restarted by Alpine's x-if.
 */

const reduceMotion = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

// Count every [data-count] under root up from zero, keeping the shape the
// server wrote: "2,707 h" counts 0 → 2,707 and keeps " h"; "2,000+" keeps "+".
export function countUp(root, duration = 1400) {
    if (reduceMotion()) return;

    root.querySelectorAll('[data-count]').forEach((el) => {
        const finalText = el.dataset.count || el.textContent.trim();
        el.dataset.count = finalText;
        const match = /^([^\d]*)([\d.,]+)(.*)$/.exec(finalText);
        if (!match) return;

        const [, prefix, digits, suffix] = match;
        const target = parseFloat(digits.replace(/,/g, ''));
        const decimals = digits.includes('.') ? digits.split('.')[1].length : 0;
        const format = (n) => n.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
        const start = performance.now();

        const tick = (now) => {
            const p = Math.min(1, (now - start) / duration);
            const eased = 1 - Math.pow(1 - p, 3);
            el.textContent = p >= 1 ? finalText : prefix + format(target * eased) + suffix;
            if (p < 1) requestAnimationFrame(tick);
        };
        requestAnimationFrame(tick);
    });
}

// Calls fn once, the first time el is a third on screen.
function whenSeen(el, fn) {
    if (!('IntersectionObserver' in window)) {
        fn();
        return;
    }
    const observer = new IntersectionObserver((entries) => {
        if (entries.some((entry) => entry.isIntersecting)) {
            observer.disconnect();
            fn();
        }
    }, { threshold: 0.3 });
    observer.observe(el);
}

// Tabs that advance on their own every `every` ms until someone takes over.
function autoTabs(count, every) {
    return {
        active: 0,
        count,
        every,
        auto: false,
        timer: null,

        startAuto() {
            if (reduceMotion()) return;
            this.auto = true;
            this.timer = setInterval(() => {
                if (!document.hidden) this.show((this.active + 1) % this.count, false);
            }, this.every);
        },

        // The visitor has taken over: no more advancing on its own.
        stopAuto() {
            this.auto = false;
            clearInterval(this.timer);
        },

        // pick(i): the visitor chose. show(): either of us did.
        pick(i) {
            this.stopAuto();
            this.show(i, true);
        },

        show(i) {
            this.active = i;
        },
    };
}

// Indices of the tabs with something to play with (home.blade.php's $services).
const EDIT_TAB = 3;
const REPORT_TAB = 6;

export function serviceExplorer(count, report) {
    return {
        ...autoTabs(count, 6500),

        // Editing: log footage or the grade. Flips by itself until touched.
        graded: false,
        gradeTouched: false,

        // Stills: whose Instagram profile is showing, and whether the
        // account switcher sheet is open.
        account: 0,
        switcher: false,

        // Reports: which metric the monthly bars show.
        metric: 'views',
        months: report?.months ?? [],

        init() {
            whenSeen(this.$el, () => this.startAuto());
            if (!reduceMotion()) {
                setInterval(() => {
                    if (this.active === EDIT_TAB && !this.gradeTouched && !document.hidden) this.graded = !this.graded;
                }, 2200);
            }
        },

        show(i) {
            this.active = i;
            if (i === EDIT_TAB) this.graded = false;
            this.switcher = false;
            if (i === REPORT_TAB) {
                this.metric = 'views';
                this.$nextTick(() => countUp(this.$refs.stage, 1200));
            }
        },

        setGrade(on) {
            this.gradeTouched = true;
            this.graded = on;
            this.stopAuto();
        },

        pickAccount(i) {
            this.account = i;
            this.switcher = false;
            this.stopAuto();
        },

        pickMetric(metric) {
            this.metric = metric;
            this.stopAuto();
        },

        // A month's bar, as a percentage of the best month for this metric.
        bar(month) {
            const max = Math.max(1, ...this.months.map((m) => m[this.metric] ?? 0));
            return Math.max(2, ((month[this.metric] ?? 0) / max) * 100);
        },

        // 7,001,864 → "7M"; 452,054 → "452K". Floored, like the server.
        compact(n) {
            if (n >= 1e6) return `${Math.floor(n / 1e5) / 10}M`;
            if (n >= 1e3) return `${Math.floor(n / 1e3)}K`;
            return String(n);
        },
    };
}

export function hoursPipeline(growth) {
    return {
        ...autoTabs(5, 8000),
        seen: false,
        tip: '',
        points: growth?.points ?? [],
        cursor: Math.max(0, (growth?.points?.length ?? 1) - 1),

        // The chart's drawing box, in SVG units.
        w: 600,
        h: 220,
        pad: 8,

        init() {
            whenSeen(this.$el, () => {
                this.seen = true;
                this.$nextTick(() => countUp(this.$el));
                this.startAuto();
            });
        },

        show(i) {
            this.active = i;
            this.cursor = Math.max(0, this.points.length - 1);
            this.$nextTick(() => countUp(this.$refs.panel ?? this.$el, 1100));
        },

        get max() {
            return Math.max(1, ...this.points.map((p) => p.pct));
        },

        x(i) {
            return this.pad + (i / Math.max(1, this.points.length - 1)) * (this.w - this.pad * 2);
        },

        y(pct) {
            return this.h - this.pad - (pct / this.max) * (this.h - this.pad * 2);
        },

        get line() {
            return this.points.map((p, i) => `${i ? 'L' : 'M'}${this.x(i).toFixed(1)} ${this.y(p.pct).toFixed(1)}`).join(' ');
        },

        get area() {
            if (!this.points.length) return '';
            return `${this.line} L${this.x(this.points.length - 1).toFixed(1)} ${this.h} L${this.x(0).toFixed(1)} ${this.h} Z`;
        },

        get current() {
            return this.points[this.cursor] ?? { date: '', pct: 0 };
        },

        // Pointer or finger anywhere over the chart: the nearest reading.
        touch(event) {
            if (!this.points.length) return;
            const rect = event.currentTarget.getBoundingClientRect();
            const clientX = event.touches ? event.touches[0].clientX : event.clientX;
            const fraction = Math.min(1, Math.max(0, (clientX - rect.left) / rect.width));
            this.cursor = Math.round(fraction * (this.points.length - 1));
            this.stopAuto();
        },

        // A reading as a multiple of the first one: the page has shares, not views.
        times(point) {
            const first = this.points[0]?.pct || 0;
            if (!first) return '';
            const n = point.pct / first;
            return `×${n >= 10 ? Math.floor(n) : Math.floor(n * 10) / 10}`;
        },
    };
}
