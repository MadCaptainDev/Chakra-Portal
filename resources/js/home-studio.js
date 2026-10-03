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

        // pick(i): the visitor chose. show(): either of us did.
        pick(i) {
            this.auto = false;
            clearInterval(this.timer);
            this.show(i, true);
        },

        show(i) {
            this.active = i;
        },
    };
}

export function serviceExplorer(count) {
    return {
        ...autoTabs(count, 6000),

        init() {
            whenSeen(this.$el, () => this.startAuto());
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
            return Math.max(1, ...this.points.map((p) => p.value));
        },

        x(i) {
            return this.pad + (i / Math.max(1, this.points.length - 1)) * (this.w - this.pad * 2);
        },

        y(value) {
            return this.h - this.pad - (value / this.max) * (this.h - this.pad * 2);
        },

        get line() {
            return this.points.map((p, i) => `${i ? 'L' : 'M'}${this.x(i).toFixed(1)} ${this.y(p.value).toFixed(1)}`).join(' ');
        },

        get area() {
            if (!this.points.length) return '';
            return `${this.line} L${this.x(this.points.length - 1).toFixed(1)} ${this.h} L${this.x(0).toFixed(1)} ${this.h} Z`;
        },

        get current() {
            return this.points[this.cursor] ?? { date: '', value: 0 };
        },

        // Pointer or finger anywhere over the chart: the nearest reading.
        touch(event) {
            if (!this.points.length) return;
            const rect = event.currentTarget.getBoundingClientRect();
            const clientX = event.touches ? event.touches[0].clientX : event.clientX;
            const fraction = Math.min(1, Math.max(0, (clientX - rect.left) / rect.width));
            this.cursor = Math.round(fraction * (this.points.length - 1));
            if (this.auto) {
                this.auto = false;
                clearInterval(this.timer);
            }
        },

        views(n) {
            return Math.floor(n).toLocaleString('en-US');
        },
    };
}
