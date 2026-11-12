/*
 * Video review (reviews/show.blade.php): the player, comments pinned to
 * moments, Gemini's flags on the same timeline, and the verdict. Polls the
 * page's state so a comment from another screen, or the AI report landing,
 * shows up on its own.
 */
const csrf = () => document.querySelector('meta[name=csrf-token]').content;
const clock = (s) => (s == null ? '' : `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`);

export function videoReview(urls, initial) {
    const draftKey = `review-draft-${initial.id}`;
    const seenKey = `review-seen-${initial.id}`;
    const readDraft = () => { try { return localStorage.getItem(draftKey) || ''; } catch { return ''; } };
    const reduced = typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches;

    return {
        s: initial,
        t: 0,
        duration: initial.duration || 0,
        buffered: 0,
        playing: false,
        muted: false,
        rate: 1,
        tab: 'comments',
        filter: 'open',
        body: readDraft(),
        pin: true,
        at: null,              // the moment the comment is about, locked when you start writing
        replies: {},           // comment id => reply draft
        replyOpen: {},         // comment id => reply box open
        editing: null,         // comment id being edited
        editBody: '',
        quick: ['Cut this part', 'Audio too low', 'Spelling mistake', 'Looks dark', 'Text too small', 'Too slow here', 'Add music', 'Looks great ✓'],
        cache: { state: 'idle', pct: 0, mb: 0 },   // this device's copy of the video
        aiAt: Date.now(),      // when the AI state last arrived, to count seconds between polls
        clockNow: Date.now(),
        busy: {},
        flash: null,           // big play/pause icon in the middle
        ripple: null,          // double-tap skip
        hover: null,           // timeline preview { x, t }
        dragging: false,
        glow: {},              // comment ids to glow (new, or jumped to)
        known: new Set(initial.comments.flatMap((c) => [c.id, ...c.replies.map((r) => r.id)])),
        lastSeen: (() => { try { return localStorage.getItem(seenKey); } catch { return null; } })(),
        toasts: [],
        pendingDelete: {},     // id => timer (undo window)
        sheet: null,           // 'approve' | 'changes'
        note: '',
        hold: 0,               // hold-to-confirm progress 0..1
        holdTimer: null,
        done: null,            // 'approved' | 'changes' after a verdict, for the animation

        init() {
            const v = this.$refs.video;
            if (v) {
                v.addEventListener('timeupdate', () => { if (!this.dragging) this.t = v.currentTime; });
                v.addEventListener('loadedmetadata', () => { this.duration = v.duration || this.duration; });
                v.addEventListener('progress', () => { try { this.buffered = v.buffered.length ? v.buffered.end(v.buffered.length - 1) : 0; } catch { /* */ } });
                v.addEventListener('play', () => { this.playing = true; });
                v.addEventListener('pause', () => { this.playing = false; });
                v.addEventListener('ended', () => { this.playing = false; });
            }
            this.$watch('body', (b) => { try { b ? localStorage.setItem(draftKey, b) : localStorage.removeItem(draftKey); } catch { /* */ } });
            window.addEventListener('beforeunload', (e) => {
                if (this.body.trim() || Object.values(this.replies).some((r) => (r || '').trim()) || this.editing) { e.preventDefault(); e.returnValue = ''; }
            });
            this.deviceCopy();
            setInterval(() => { this.clockNow = Date.now(); }, 1000);
            window.addEventListener('keydown', (e) => this.key(e));
            try { localStorage.setItem(seenKey, new Date().toISOString()); } catch { /* */ }
            this.loop();
        },

        // ——— Talking to the portal ———
        async call(method, url, body) {
            const r = await fetch(url, {
                method, credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
                body: body ? JSON.stringify(body) : undefined,
            });
            const data = await r.json().catch(() => ({}));
            if (!r.ok) throw new Error(data.message || 'Something went wrong. Try again.');
            return data;
        },
        /** Every few seconds: say where I am, take everyone's comments. Faster while others watch. */
        loop() {
            const tick = async () => {
                if (!document.hidden) await this.refresh();
                setTimeout(tick, this.s.watching.length || ['queued', 'running'].includes(this.s.ai.status) ? 3000 : 6000);
            };
            setTimeout(tick, 2500);
        },
        async refresh() {
            try {
                const q = new URLSearchParams({ t: (this.t || 0).toFixed(1), p: this.playing ? 1 : 0 });
                this.take(await this.call('GET', `${urls.live}?${q}`));
            } catch { /* next time */ }
        },
        /** New state in; announce and light up what others just wrote. */
        take(next, mineToo = false) {
            const fresh = [];
            next.comments.forEach((c) => [c, ...c.replies].forEach((x) => {
                if (!this.known.has(x.id)) { this.known.add(x.id); if (!x.mine || mineToo) fresh.push(x); }
            }));
            // Keep a comment hidden while its delete can still be undone.
            Object.keys(this.pendingDelete).forEach((id) => {
                next.comments = next.comments.filter((c) => c.id !== Number(id)).map((c) => ({ ...c, replies: c.replies.filter((r) => r.id !== Number(id)) }));
            });
            const aiLanded = this.s.ai.status !== 'done' && next.ai.status === 'done';
            const approvedNow = this.s.status !== 'approved' && next.status === 'approved';
            this.s = next;
            this.aiAt = Date.now();
            if (approvedNow && !this.done) { this.done = 'approve'; setTimeout(() => { this.done = null; }, 2600); }
            fresh.forEach((x) => {
                this.light(x.id);
                if (!x.mine) this.toast(`${x.by.split(' ')[0]} ${x.at != null ? 'commented at ' + x.stamp : 'replied'}`, x.body, () => this.goTo(x));
            });
            if (aiLanded) this.toast('The AI check is in', next.ai.verdict_label || '', () => { this.tab = 'ai'; });
        },

        // ——— Player ———
        toggle() {
            const v = this.$refs.video;
            if (!v) return;
            v.paused ? v.play() : v.pause();
            this.pulse(v.paused ? 'pause' : 'play');
        },
        pulse(icon) {
            if (reduced) return;
            this.flash = { icon, k: Date.now() };
            setTimeout(() => { if (this.flash && Date.now() - this.flash.k >= 550) this.flash = null; }, 600);
        },
        seek(sec) {
            const v = this.$refs.video;
            const to = Math.max(0, Math.min(sec, this.duration || sec));
            if (v) v.currentTime = to;
            this.t = to;
        },
        skip(by) { this.seek(this.t + by); },
        jump(sec) { if (sec == null) return; this.seek(sec); this.$refs.video?.pause(); },
        /** Tap the picture: play or pause. Double tap a side: 5 seconds back or on. */
        tapVideo(e) {
            const r = e.currentTarget.getBoundingClientRect();
            const side = e.clientX - r.left < r.width * 0.3 ? -1 : (e.clientX - r.left > r.width * 0.7 ? 1 : 0);
            const now = Date.now();
            if (side && this._lastTap && now - this._lastTap.at < 320 && this._lastTap.side === side) {
                clearTimeout(this._tapTimer);
                this.skip(side * 5);
                this.ripple = { side, k: now };
                setTimeout(() => { if (this.ripple?.k === now) this.ripple = null; }, 600);
                this._lastTap = null;
                return;
            }
            this._lastTap = { at: now, side };
            clearTimeout(this._tapTimer);
            this._tapTimer = setTimeout(() => this.toggle(), side ? 260 : 0);
        },
        setRate() {
            const rates = [1, 1.5, 2, 0.5];
            this.rate = rates[(rates.indexOf(this.rate) + 1) % rates.length];
            if (this.$refs.video) this.$refs.video.playbackRate = this.rate;
        },
        mute() { this.muted = !this.muted; if (this.$refs.video) this.$refs.video.muted = this.muted; },
        full() {
            const el = this.$refs.stage;
            if (document.fullscreenElement) document.exitFullscreen?.(); else (el.requestFullscreen || el.webkitRequestFullscreen)?.call(el);
        },

        // ——— Timeline: drag to scrub, with a preview of the time ———
        fraction(e) {
            const r = this.$refs.track.getBoundingClientRect();
            return Math.max(0, Math.min(1, (e.clientX - r.left) / r.width));
        },
        scrubStart(e) {
            if (e.button > 0) return;
            this.dragging = true;
            this._wasPlaying = this.playing;
            this.$refs.video?.pause();
            e.currentTarget.setPointerCapture?.(e.pointerId);
            this.scrubMove(e);
        },
        scrubMove(e) {
            const f = this.fraction(e);
            this.hover = { x: f * 100, t: f * (this.duration || 0) };
            if (this.dragging) { this.t = this.hover.t; if (this.$refs.video) this.$refs.video.currentTime = this.t; }
        },
        scrubEnd() {
            if (!this.dragging) return;
            this.dragging = false;
            if (this._wasPlaying) this.$refs.video?.play();
        },
        pos(sec) { return this.duration ? `${Math.max(0, Math.min(100, (sec / this.duration) * 100))}%` : '0%'; },
        clock,

        // ——— Keys (never while typing) ———
        key(e) {
            if (['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName) || e.metaKey || e.ctrlKey || e.altKey || this.sheet) return;
            const act = {
                ' ': () => this.toggle(), k: () => this.toggle(),
                ArrowLeft: () => this.skip(e.shiftKey ? -1 / 25 : -2), ArrowRight: () => this.skip(e.shiftKey ? 1 / 25 : 2),
                j: () => this.skip(-5), l: () => this.skip(5), c: () => this.startComment(), m: () => this.mute(), f: () => this.full(),
            }[e.key];
            if (act) { e.preventDefault(); act(); }
        },

        // ——— Comments ———
        get open() { return this.s.comments.filter((c) => !c.resolved); },
        get shown() {
            if (this.filter === 'all') return this.s.comments;
            if (this.filter === 'resolved') return this.s.comments.filter((c) => c.resolved);
            if (this.filter === 'mine') return this.s.comments.filter((c) => c.mine);
            return this.open;
        },
        get timed() { return this.s.comments.filter((c) => c.at != null); },
        isNew(c) { return !!this.lastSeen && !c.mine && c.created > this.lastSeen; },
        /** Start writing about this moment: the video stops and the time is held. */
        startComment(focus = true) {
            this.$refs.video?.pause();
            if (this.at === null) this.at = Math.round(this.t * 100) / 100;
            this.pin = true;
            this.tab = 'comments';
            if (focus) this.$nextTick(() => { this.$refs.comment?.focus(); this.$refs.comment?.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); });
        },
        nudge(by) {
            this.at = Math.max(0, Math.min(this.duration || 99999, Math.round(((this.at ?? this.t) + by) * 100) / 100));
            this.seek(this.at);
        },
        useQuick(q) {
            this.startComment(false);
            this.body = this.body.trim() ? `${this.body.trim()} ${q}` : q;
            this.$nextTick(() => { const el = this.$refs.comment; el?.focus(); el?.setSelectionRange(el.value.length, el.value.length); });
        },
        clearComment() { this.body = ''; this.at = null; },
        async send() {
            const body = this.body.trim();
            if (!body || this.busy.send) return;
            this.busy.send = true;
            const at = this.pin ? (this.at ?? Math.round(this.t * 100) / 100) : null;
            try {
                this.take(await this.call('POST', urls.comment, { body, at_seconds: at }), true);
                this.body = '';
                this.at = null;
                if (this.filter === 'resolved') this.filter = 'open';
            } catch (e) { this.toast('Not sent', e.message, null, true); } finally { this.busy.send = false; }
        },
        openReply(c) {
            this.replyOpen[c.id] = true;
            this.$nextTick(() => document.getElementById(`reply-${c.id}`)?.focus());
        },
        async reply(c) {
            const body = (this.replies[c.id] || '').trim();
            if (!body || this.busy[`r${c.id}`]) return;
            this.busy[`r${c.id}`] = true;
            try {
                this.take(await this.call('POST', urls.comment, { body, parent_id: c.id }), true);
                this.replies[c.id] = '';
                this.replyOpen[c.id] = false;
            } catch (e) { this.toast('Not sent', e.message, null, true); } finally { this.busy[`r${c.id}`] = false; }
        },
        startEdit(c) { this.editing = c.id; this.editBody = c.body; this.$nextTick(() => document.getElementById(`edit-${c.id}`)?.focus()); },
        async saveEdit(c) {
            const body = this.editBody.trim();
            if (!body) return;
            if (body === c.body) { this.editing = null; return; }
            try {
                this.take(await this.call('PUT', urls.edit.replace('__ID__', c.id), { body }));
                this.editing = null;
                this.toast('Saved', body);
            } catch (e) { this.toast('Not saved', e.message, null, true); }
        },
        /** The comment at the moment playing now, lit while the video passes it. */
        isNow(c) { return c.at != null && Math.abs(this.t - c.at) < 1.5 && (this.playing || this.dragging); },
        async like(c) {
            if (this.busy[`l${c.id}`]) return;
            this.busy[`l${c.id}`] = true;
            c.liked = !c.liked; c.likes += c.liked ? 1 : -1;     // shown at once
            try { this.take(await this.call('POST', urls.like.replace('__ID__', c.id))); } catch (e) { c.liked = !c.liked; c.likes += c.liked ? 1 : -1; this.toast('Not saved', e.message, null, true); } finally { this.busy[`l${c.id}`] = false; }
        },
        async resolve(c, resolved = true) {
            try {
                this.take(await this.call('PATCH', urls.resolve.replace('__ID__', c.id), { resolved }));
                if (resolved) this.toast('Resolved', c.body, null, false, () => this.resolve(c, false));
            } catch (e) { this.toast('Not saved', e.message, null, true); }
        },
        /** Deleting waits five seconds for an Undo before it really happens. */
        remove(c) {
            const hide = () => {
                this.s.comments = this.s.comments.filter((x) => x.id !== c.id).map((x) => ({ ...x, replies: x.replies.filter((r) => r.id !== c.id) }));
            };
            const keep = JSON.parse(JSON.stringify(this.s.comments));
            hide();
            this.pendingDelete[c.id] = setTimeout(async () => {
                delete this.pendingDelete[c.id];
                try { this.take(await this.call('DELETE', urls.resolve.replace('__ID__', c.id))); } catch (e) { this.s.comments = keep; this.toast('Not deleted', e.message, null, true); }
            }, 5000);
            this.toast('Comment deleted', c.body, null, false, () => {
                clearTimeout(this.pendingDelete[c.id]);
                delete this.pendingDelete[c.id];
                this.s.comments = keep;
            });
        },
        async adopt(issue) {
            try {
                this.take(await this.call('POST', urls.comment, { body: issue.note, at_seconds: issue.at }), true);
                this.toast('Added as a comment', issue.note);
            } catch (e) { this.toast('Not added', e.message, null, true); }
        },
        /** Jump to a comment's moment and light it up in the list. */
        goTo(c) {
            const top = this.s.comments.find((x) => x.id === c.id || x.replies.some((r) => r.id === c.id));
            if (top?.at != null) this.jump(top.at);
            this.tab = 'comments';
            if (top?.resolved && this.filter === 'open') this.filter = 'all';
            this.$nextTick(() => {
                document.getElementById(`c-${c.id}`)?.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'center' });
                this.light(c.id);
            });
        },
        light(id) {
            this.glow[id] = true;
            setTimeout(() => { delete this.glow[id]; }, 2200);
        },
        follow(w) { if (w.at != null) { this.jump(w.at); this.toast(`At ${w.name.split(' ')[0]}'s spot`, clock(w.at)); } },
        initials(name) { return (name || '?').split(' ').map((w) => w[0]).join('').slice(0, 2).toUpperCase(); },
        hue(id) { return `hsl(${(id * 67) % 360} 70% 62%)`; },

        // ——— Verdict: a sheet, then press and hold, so nothing is decided by a stray tap ———
        decide(kind) {
            this.$refs.video?.pause();
            this.sheet = kind;
            this.hold = 0;
        },
        holdStart() {
            if (this.busy.verdict) return;
            const started = Date.now();
            clearInterval(this.holdTimer);
            this.holdTimer = setInterval(() => {
                this.hold = Math.min(1, (Date.now() - started) / 900);
                if (this.hold >= 1) { clearInterval(this.holdTimer); this.confirm(); }
            }, 16);
        },
        holdEnd() {
            if (this.hold < 1) { clearInterval(this.holdTimer); this.hold = 0; }
        },
        async confirm() {
            if (this.busy.verdict) return;
            const kind = this.sheet;
            if (kind === 'changes' && !this.open.length && !this.note.trim()) {
                this.hold = 0;
                this.toast('Say what to change', 'Add a comment on the video, or a note here.', null, true);
                return;
            }
            this.busy.verdict = true;
            try {
                this.take(await this.call('POST', kind === 'approve' ? urls.approve : urls.changes, { note: this.note.trim() || null }));
                this.sheet = null;
                this.note = '';
                this.done = kind;
                setTimeout(() => { this.done = null; }, 2600);
            } catch (e) {
                this.toast('Not done', e.message, null, true);
            } finally {
                this.busy.verdict = false;
                this.hold = 0;
            }
        },
        /** Take my OK back while the others have not all given theirs. */
        async unapprove() {
            if (this.busy.verdict) return;
            this.busy.verdict = true;
            try { this.take(await this.call('DELETE', urls.approve)); this.toast('Your approval was taken back'); } catch (e) { this.toast('Not done', e.message, null, true); } finally { this.busy.verdict = false; }
        },
        /** Seconds in the current AI step, counting between polls. */
        get aiSecs() { return (this.s.ai.stage_secs || 0) + Math.max(0, Math.round((this.clockNow - this.aiAt) / 1000)); },
        /** One bar across the whole check: sending 0-30, Gemini reading 30-45, watching 45-95, writing 95-100. */
        get aiPct() {
            const a = this.s.ai;
            if (a.status === 'queued') return 2;
            if (a.stage === 'uploading') return Math.round((a.progress || 0) * 0.3);
            if (a.stage === 'processing') return 30 + Math.min(14, Math.round(this.aiSecs * 1.2));
            if (a.stage === 'watching') return 45 + Math.min(49, Math.round((this.aiSecs / Math.max(10, a.expect_secs)) * 50));
            if (a.stage === 'writing') return 98;
            return 5;
        },
        get aiSteps() {
            const order = ['uploading', 'processing', 'watching', 'writing'];
            const at = this.s.ai.status === 'queued' ? -1 : order.indexOf(this.s.ai.stage);
            return [
                ['uploading', 'Sending the video to Gemini', this.s.ai.stage === 'uploading' ? `${this.s.ai.progress || 0}%` : ''],
                ['processing', 'Gemini is reading the file', ''],
                ['watching', 'Watching and listening, frame by frame', this.s.ai.stage === 'watching' ? `about ${Math.max(5, this.s.ai.expect_secs - this.aiSecs)}s left` : ''],
                ['writing', 'Writing the report', ''],
            ].map(([key, label, extra], i) => ({ key, label, extra, state: i < at ? 'done' : (i === at ? 'now' : 'next') }));
        },
        async recheck() {
            try { this.take(await this.call('POST', urls.recheck)); } catch (e) { this.toast('Not started', e.message, null, true); }
        },

        // ——— A copy of the video on this device: instant seeking, no buffering, works offline ———
        async deviceCopy(force = false) {
            const v = this.$refs.video;
            const url = urls.file;
            if (!v || !url || !('caches' in window)) { this.cache.state = 'off'; return; }
            const max = 400 * 1024 * 1024;
            if ((urls.size || 0) > max) { this.cache.state = 'big'; return; }
            const conn = navigator.connection || {};
            try {
                const box = await caches.open('chakra-reviews');
                const hit = await box.match(url);
                if (hit) {
                    this.swap(URL.createObjectURL(await hit.blob()));
                    this.cache.state = 'saved';
                    this.remember(url);
                    return;
                }
                // On a data-saver connection, wait to be asked.
                if (!force && (conn.saveData || /2g/.test(conn.effectiveType || ''))) { this.cache.state = 'ask'; return; }

                this.cache.state = 'saving';
                const res = await fetch(url, { credentials: 'same-origin' });
                if (!res.ok || !res.body) throw new Error(res.status);
                const total = Number(res.headers.get('Content-Length')) || urls.size || 0;
                const reader = res.body.getReader();
                const parts = [];
                let got = 0;
                for (;;) {
                    const { done, value } = await reader.read();
                    if (done) break;
                    parts.push(value);
                    got += value.length;
                    this.cache.pct = total ? Math.min(100, Math.round((got / total) * 100)) : 0;
                    this.cache.mb = Math.round(got / 1048576);
                }
                const blob = new Blob(parts, { type: res.headers.get('Content-Type') || 'video/mp4' });
                await box.put(url, new Response(blob, { headers: { 'Content-Type': blob.type, 'Content-Length': String(blob.size) } }));
                this.swap(URL.createObjectURL(blob));
                this.cache.state = 'saved';
                this.remember(url);
                this.prune(box);
            } catch {
                this.cache.state = 'failed'; // the video still plays from the portal
            }
        },
        /** Play from the saved copy, from the same moment, without a jump. */
        swap(objectUrl) {
            const v = this.$refs.video;
            const at = v.currentTime;
            const was = !v.paused;
            v.src = objectUrl;
            v.addEventListener('loadedmetadata', () => { v.currentTime = at; if (was) v.play(); }, { once: true });
        },
        remember(url) {
            try {
                const list = JSON.parse(localStorage.getItem('chakra-reviews-saved') || '[]').filter((x) => x.url !== url);
                list.unshift({ url, at: Date.now() });
                localStorage.setItem('chakra-reviews-saved', JSON.stringify(list.slice(0, 50)));
            } catch { /* */ }
        },
        /** Keep the last 8 cuts, and nothing older than two weeks. */
        async prune(box) {
            try {
                const list = JSON.parse(localStorage.getItem('chakra-reviews-saved') || '[]');
                const keep = list.filter((x, i) => i < 8 && Date.now() - x.at < 14 * 864e5);
                for (const x of list.filter((x) => !keep.includes(x))) await box.delete(x.url);
                localStorage.setItem('chakra-reviews-saved', JSON.stringify(keep));
            } catch { /* */ }
        },
        async forgetCopy() {
            try {
                const box = await caches.open('chakra-reviews');
                await box.delete(urls.file);
                const list = JSON.parse(localStorage.getItem('chakra-reviews-saved') || '[]').filter((x) => x.url !== urls.file);
                localStorage.setItem('chakra-reviews-saved', JSON.stringify(list));
            } catch { /* */ }
            this.cache.state = 'ask';
            this.toast('Removed from this device', 'It plays from the portal again.');
        },

        // ——— Toasts (with Undo where it helps) ———
        toast(title, body = '', onClick = null, bad = false, undo = null) {
            const id = Date.now() + Math.random();
            this.toasts.push({ id, title, body: (body || '').slice(0, 120), onClick, bad, undo });
            setTimeout(() => { this.toasts = this.toasts.filter((t) => t.id !== id); }, undo ? 5200 : 4200);
        },
        closeToast(t) { this.toasts = this.toasts.filter((x) => x.id !== t.id); },
    };
}

/**
 * Uploading a cut from an editing card: in 8 MB pieces, each retried, so a
 * dropped signal resumes instead of starting over. A frame is taken in the
 * browser as the poster, with the length and size.
 */
export function reviewUploader() {
    return {
        open: false,
        card: null,
        file: null,
        note: '',
        pct: 0,
        stage: '',
        error: '',
        done: null,

        openFor(card) {
            Object.assign(this, { card, file: null, note: '', pct: 0, stage: '', error: '', done: null });
        },
        pick(e) {
            this.file = e.target.files[0] || null;
            this.error = '';
            if (this.file && this.file.size > 1536 * 1024 * 1024) { this.error = 'That file is over 1.5 GB. Export a lighter review copy (1080p H.264 MP4).'; this.file = null; }
        },
        mb(n) { return `${(n / 1024 / 1024).toFixed(n > 100 * 1024 * 1024 ? 0 : 1)} MB`; },

        /** Length, size and a frame from 1 second in, read in the browser. */
        async probe(file) {
            return new Promise((resolve) => {
                const v = document.createElement('video');
                const url = URL.createObjectURL(file);
                const out = { duration: null, width: null, height: null, poster: null };
                const finish = () => { URL.revokeObjectURL(url); resolve(out); };
                setTimeout(finish, 8000);
                v.preload = 'metadata';
                v.muted = true;
                v.playsInline = true;
                v.src = url;
                v.onloadedmetadata = () => {
                    Object.assign(out, { duration: v.duration || null, width: v.videoWidth || null, height: v.videoHeight || null });
                    v.currentTime = Math.min(1, (v.duration || 2) / 2);
                };
                v.onseeked = () => {
                    try {
                        const scale = Math.min(1, 480 / Math.max(v.videoWidth, v.videoHeight));
                        const c = document.createElement('canvas');
                        c.width = Math.round(v.videoWidth * scale);
                        c.height = Math.round(v.videoHeight * scale);
                        c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
                        out.poster = c.toDataURL('image/jpeg', 0.75);
                    } catch { /* a format the browser cannot draw */ }
                    finish();
                };
                v.onerror = finish;
            });
        },

        async send(url, body, isJson = true) {
            const r = await fetch(url, {
                method: 'POST', credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf(), ...(isJson ? { 'Content-Type': 'application/json' } : { 'Content-Type': 'application/octet-stream' }) },
                body: isJson ? JSON.stringify(body) : body,
            });
            const data = await r.json().catch(() => ({}));
            return { ok: r.ok, status: r.status, data };
        },

        async upload() {
            if (!this.file || this.stage) return;
            this.error = '';
            this.stage = 'Reading the video…';
            const meta = await this.probe(this.file);

            this.stage = 'Starting…';
            const start = await this.send(this.card.review_upload, { name: this.file.name, size: this.file.size, type: this.file.type });
            if (!start.ok) { this.fail(start.data.message); return; }
            const { upload, chunk } = start.data;
            const base = this.card.review_upload.replace(/reviews\/cards\/\d+\/uploads$/, `reviews/uploads/${upload}`);

            let offset = 0;
            this.stage = 'Uploading…';
            while (offset < this.file.size) {
                let tries = 0;
                for (;;) {
                    const piece = this.file.slice(offset, offset + chunk);
                    const r = await this.send(`${base}/chunk?offset=${offset}`, piece, false).catch(() => ({ ok: false, status: 0, data: {} }));
                    if (r.ok || r.status === 409) { offset = r.data.received ?? offset; break; }
                    if (++tries > 6 || r.status === 422 || r.status === 404) { this.fail(r.data.message || 'The upload stopped. Check the connection and try again.'); return; }
                    this.stage = `Connection dropped, retrying (${tries})…`;
                    await new Promise((res) => setTimeout(res, 1500 * tries));
                }
                this.pct = Math.round((offset / this.file.size) * 100);
                this.stage = `Uploading… ${this.mb(offset)} of ${this.mb(this.file.size)}`;
            }

            this.stage = 'Finishing…';
            const fin = await this.send(`${base}/finish`, { ...meta, note: this.note || null });
            if (!fin.ok) { this.fail(fin.data.message); return; }
            this.done = fin.data;
            this.stage = '';
            this.$dispatch('review-uploaded', { card: this.card.id, review: fin.data.video });
        },
        fail(message) {
            this.error = message || 'The upload did not finish. Try again.';
            this.stage = '';
        },
    };
}
