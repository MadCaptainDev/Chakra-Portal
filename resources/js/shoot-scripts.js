/**
 * Scripts on set: the client's scripts beside the shoot runner, a full-screen
 * reader (big text, auto-scroll, screen kept awake), Start/Stop per section
 * to time each one and mark it shot or not, and "Done", which names the
 * next video after the script, links the two and files the section times.
 *
 * Section times live in localStorage until the video is saved, so a reload
 * or a dead signal mid-shoot does not lose them.
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

const POLL_MS = 5000;

/**
 * Live updates for the shoot screen. This host cannot hold a websocket open,
 * so each phone asks the portal every few seconds whether anything moved;
 * an unchanged shoot answers with a few bytes. Paused while the phone is
 * locked or the tab is hidden, and asked again the moment it comes back.
 */
function poller(url, version, onChange) {
    let current = version;
    let timer = null;
    let failures = 0;

    const schedule = (ms) => {
        clearTimeout(timer);
        if (!document.hidden) timer = setTimeout(check, ms);
    };
    const check = async () => {
        try {
            const res = await fetch(`${url}?since=${encodeURIComponent(current)}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!res.ok) throw new Error(String(res.status));
            const data = await res.json();
            failures = 0;
            if (data.changed) {
                current = data.version;
                onChange(data);
            }
        } catch {
            failures += 1; // no signal on location: back off, keep trying
        }
        schedule(failures ? Math.min(30000, POLL_MS * (failures + 1)) : POLL_MS);
    };
    document.addEventListener('visibilitychange', () => (document.hidden ? clearTimeout(timer) : check()));
    schedule(POLL_MS);
}

/** Before the shoot is rolling: any change (someone tapped Start) reloads into the new state. */
export function shootLive({ liveUrl, version }) {
    return {
        init() {
            poller(liveUrl, version, () => window.location.reload());
        },
    };
}

function logKey(shootId, scriptId) {
    return `shoot-${shootId}-script-${scriptId}`;
}

export function shootScripts({ shootId, scripts, liveUrl = null, shareUrl = null, liveVersion = '', videoIds = [], me = null, oldName = '', oldScriptId = null, oldSectionLog = '' }) {
    return {
        shootId,
        scripts,
        videoIds,
        notice: '',
        noticeTimer: null,
        newVideos: 0,
        saving: false,
        sectionLog: oldSectionLog || '', // what the form sends
        log: null, // the open script's timings: { sections: [{shot, takes, seconds}], running: {i, since} | null }
        tick: Date.now(),
        ticker: null,
        name: oldName,
        scriptId: oldScriptId ? Number(oldScriptId) : null,
        reading: null, // the script open in the reader
        size: stored(SIZE_KEY, 26),
        speed: stored(SPEED_KEY, 30), // pixels a second
        scrolling: false,
        showShot: false,
        sharing: false,
        wake: null,
        frame: null,

        init() {
            if (liveUrl) poller(liveUrl, liveVersion, (data) => this.apply(data));
        },

        // ——— Live ———
        apply(data) {
            if (data.wrapped) {
                window.location.reload();
                return;
            }

            const before = new Map(this.scripts.map((s) => [s.id, s.rev]));
            const edited = data.scripts.filter((s) => before.has(s.id) && before.get(s.id) !== s.rev);
            const added = data.scripts.filter((s) => !before.has(s.id));
            this.scripts = data.scripts;

            if (this.reading) {
                const fresh = data.scripts.find((s) => s.id === this.reading.id);
                if (fresh) {
                    // Same timings, new words: keep each part's log by position.
                    const blank = { shot: false, takes: 0, seconds: 0 };
                    this.log.sections = fresh.sections.map((_, i) => this.log.sections[i] || { ...blank });
                    if (this.log.running && this.log.running.i >= fresh.sections.length) this.log.running = null;
                    this.reading = fresh;
                    this.saveLog();
                }
            }

            const fresh = data.videos.filter((v) => !this.videoIds.includes(v.id));
            this.videoIds = data.videos.map((v) => v.id);
            const others = fresh.filter((v) => v.by !== me);
            this.newVideos += others.length;

            const lines = [];
            edited.forEach((s) => lines.push(`Script #${s.id} was just updated`));
            added.forEach((s) => lines.push(`New script #${s.id}: ${s.title}`));
            others.forEach((v) => lines.push(`${v.by || 'Someone'} saved “${v.name}”`));
            if (lines.length) this.say(lines.join(' · '));
        },
        say(text) {
            this.notice = text;
            clearTimeout(this.noticeTimer);
            this.noticeTimer = setTimeout(() => { this.notice = ''; }, 8000);
        },

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
            this.log = this.loadLog(script);
            this.ticker = setInterval(() => { this.tick = Date.now(); }, 500);
            this.$nextTick(() => { this.$refs.page.scrollTop = 0; });
            this.keepAwake();
        },
        // A no-login live link to the open script (set/{token}), for the
        // talent or client on set: the phone's share sheet, or the clipboard.
        async shareLive() {
            if (!this.reading || !shareUrl || this.sharing === true) return;
            this.sharing = true;
            try {
                const res = await fetch(shareUrl.replace(/0$/, this.reading.id), {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                });
                if (!res.ok) throw new Error('share');
                const { url } = await res.json();
                const title = `#${this.reading.id} ${this.reading.title}`;
                if (navigator.share) {
                    await navigator.share({ title, text: `${title} (live script, you can edit)`, url }).catch(() => {});
                    this.sharing = false;
                } else {
                    await navigator.clipboard.writeText(url);
                    this.sharing = 'done';
                    setTimeout(() => { this.sharing = false; }, 2000);
                }
            } catch (e) {
                this.sharing = false;
                this.say('Could not make the live link. Check the connection and try again.');
            }
        },
        close() {
            // A running section keeps running: closing to check something
            // is not the same as cutting. Its start is saved.
            this.stop();
            clearInterval(this.ticker);
            this.reading = null;
            this.log = null;
            this.release();
        },

        // ——— Section timings ———
        loadLog(script) {
            let saved = null;
            try {
                saved = JSON.parse(localStorage.getItem(logKey(this.shootId, script.id)) || 'null');
            } catch {
                saved = null;
            }
            const sections = script.sections.map((_, i) => saved?.sections?.[i] || { shot: false, takes: 0, seconds: 0 });
            return { sections, running: saved?.running ?? null };
        },
        saveLog() {
            if (!this.reading) return;
            store(logKey(this.shootId, this.reading.id), JSON.stringify(this.log));
        },
        isRunning(i) {
            return !!this.log?.running && this.log.running.i === i;
        },
        startSection(i) {
            if (this.log.running) this.stopSection();
            this.log.running = { i, since: Date.now() };
            this.saveLog();
        },
        stopSection() {
            const run = this.log.running;
            if (!run) return;
            const sec = this.log.sections[run.i];
            sec.seconds += Math.max(1, Math.round((Date.now() - run.since) / 1000));
            sec.takes += 1;
            sec.shot = true;
            this.log.running = null;
            this.saveLog();
        },
        toggleShot(i) {
            this.log.sections[i].shot = !this.log.sections[i].shot;
            this.saveLog();
        },
        secondsFor(i) {
            const sec = this.log?.sections[i];
            if (!sec) return 0;
            const live = this.isRunning(i) ? Math.floor((this.tick - this.log.running.since) / 1000) : 0;
            return sec.seconds + Math.max(0, live);
        },
        get shotCount() {
            return this.log ? this.log.sections.filter((s) => s.shot).length : 0;
        },
        get totalSeconds() {
            return this.log ? this.log.sections.reduce((n, _, i) => n + this.secondsFor(i), 0) : 0;
        },
        clock(seconds) {
            const m = Math.floor(seconds / 60);
            const s = String(seconds % 60).padStart(2, '0');
            return `${m}:${s}`;
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
            if (this.log.running) this.stopSection();
            this.sectionLog = JSON.stringify(script.sections.map((sec, i) => ({
                heading: sec.heading,
                shot: this.log.sections[i].shot,
                takes: this.log.sections[i].takes,
                seconds: this.log.sections[i].seconds,
            })));
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
            this.sectionLog = '';
        },
        // The video is being saved: its timings go with it, so the phone
        // can forget them. A failed save comes back with them in old().
        submitted(event) {
            // One tap, one video: on a slow signal a second tap used to file it twice.
            if (this.saving) {
                event?.preventDefault();
                return;
            }
            this.saving = true;
            if (this.scriptId) {
                try {
                    localStorage.removeItem(logKey(this.shootId, this.scriptId));
                } catch {
                    // Nothing stored.
                }
            }
        },
    };
}
