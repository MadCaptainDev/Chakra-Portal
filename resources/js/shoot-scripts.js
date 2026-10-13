/**
 * Scripts on set: the client's scripts beside the shoot runner, a full-screen
 * reader (big text, auto-scroll, screen kept awake), and "Shot it", which
 * names the next video after the script and links the two.
 *
 * Everything arrives with the page -- signal on location is not to be
 * counted on -- so opening a script is instant and works offline.
 */
const SIZE_KEY = 'shoot-script-size';
const SPEED_KEY = 'shoot-script-speed';

function stored(key, fallback) {
    try {
        const v = Number(localStorage.getItem(key));
        return v > 0 ? v : fallback;
    } catch {
        return fallback;
    }
}

function store(key, value) {
    try {
        localStorage.setItem(key, String(value));
    } catch {
        // Private mode: the setting just isn't remembered.
    }
}

export function shootScripts({ scripts, oldName = '', oldScriptId = null }) {
    return {
        scripts,
        name: oldName,
        scriptId: oldScriptId ? Number(oldScriptId) : null,
        reading: null, // the script open in the reader
        size: stored(SIZE_KEY, 26),
        speed: stored(SPEED_KEY, 30), // pixels a second
        scrolling: false,
        showShot: false,
        wake: null,
        frame: null,

        get todo() {
            return this.scripts.filter((s) => !s.shot);
        },
        get done() {
            return this.scripts.filter((s) => !!s.shot);
        },
        get linked() {
            return this.scripts.find((s) => s.id === this.scriptId) || null;
        },

        open(script) {
            this.reading = script;
            this.$nextTick(() => { this.$refs.page.scrollTop = 0; });
            this.keepAwake();
        },
        close() {
            this.stop();
            this.reading = null;
            this.release();
        },

        bigger() {
            this.size = Math.min(56, this.size + 4);
            store(SIZE_KEY, this.size);
        },
        smaller() {
            this.size = Math.max(16, this.size - 4);
            store(SIZE_KEY, this.size);
        },
        faster() {
            this.speed = Math.min(150, this.speed + 10);
            store(SPEED_KEY, this.speed);
        },
        slower() {
            this.speed = Math.max(10, this.speed - 10);
            store(SPEED_KEY, this.speed);
        },

        // ——— Teleprompter ———
        toggle() {
            this.scrolling ? this.stop() : this.play();
        },
        play() {
            const page = this.$refs.page;
            this.scrolling = true;
            let last = performance.now();
            let carry = 0;
            const step = (now) => {
                if (!this.scrolling) return;
                carry += ((now - last) / 1000) * this.speed;
                last = now;
                const whole = Math.floor(carry);
                if (whole > 0) {
                    page.scrollTop += whole;
                    carry -= whole;
                }
                if (page.scrollTop + page.clientHeight >= page.scrollHeight - 1) {
                    this.scrolling = false;
                    return;
                }
                this.frame = requestAnimationFrame(step);
            };
            this.frame = requestAnimationFrame(step);
        },
        stop() {
            this.scrolling = false;
            if (this.frame) cancelAnimationFrame(this.frame);
            this.frame = null;
        },

        // ——— Keep the phone from sleeping mid-take ———
        async keepAwake() {
            try {
                if ('wakeLock' in navigator) this.wake = await navigator.wakeLock.request('screen');
            } catch {
                // Battery saver or an old browser: the reader still works.
            }
        },
        release() {
            try {
                this.wake?.release();
            } catch {
                // Already released.
            }
            this.wake = null;
        },

        // ——— Into the video form ———
        shotIt() {
            const script = this.reading;
            this.close();
            this.use(script);
            this.$nextTick(() => {
                this.$refs.form?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                this.$refs.photo?.focus();
            });
        },
        use(script) {
            this.scriptId = script.id;
            this.name = script.title.slice(0, 160);
        },
        unlink() {
            this.scriptId = null;
        },
    };
}
