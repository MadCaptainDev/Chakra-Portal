/*
 * Video review (reviews/show.blade.php): the player, comments pinned to
 * moments, Gemini's flags on the same timeline, and the verdict. Polls the
 * page's state so a comment from another screen, or the AI report landing,
 * shows up on its own.
 */
const csrf = () => document.querySelector('meta[name=csrf-token]').content;
const clock = (s) => (s == null ? '' : `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`);

export function videoReview(urls, initial) {
    return {
        s: initial,
        t: 0,
        duration: 0,
        playing: false,
        body: '',
        pin: true,
        note: '',
        busy: false,
        showResolved: false,
        error: '',

        init() {
            const v = this.$refs.video;
            v.addEventListener('timeupdate', () => { this.t = v.currentTime; });
            v.addEventListener('loadedmetadata', () => { this.duration = v.duration || 0; });
            v.addEventListener('play', () => { this.playing = true; });
            v.addEventListener('pause', () => { this.playing = false; });
            const poll = () => {
                const waiting = ['queued', 'running'].includes(this.s.ai.status);
                setTimeout(async () => { if (!document.hidden) await this.refresh(); poll(); }, waiting ? 6000 : 20000);
            };
            poll();
            window.addEventListener('keydown', (e) => {
                if (['INPUT', 'TEXTAREA'].includes(e.target.tagName)) return;
                if (e.key === ' ') { e.preventDefault(); this.toggle(); }
                if (e.key === 'ArrowLeft') this.seek(this.t - (e.shiftKey ? 1 / 25 : 2));
                if (e.key === 'ArrowRight') this.seek(this.t + (e.shiftKey ? 1 / 25 : 2));
                if (e.key === 'c') { e.preventDefault(); this.$refs.comment?.focus(); }
            });
        },

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
        async refresh() {
            try { this.s = await this.call('GET', urls.live); } catch { /* next time */ }
        },

        // ——— Player ———
        toggle() { const v = this.$refs.video; v.paused ? v.play() : v.pause(); },
        seek(sec) {
            const v = this.$refs.video;
            v.currentTime = Math.max(0, Math.min(sec, this.duration || sec));
            this.t = v.currentTime;
        },
        jump(sec) { if (sec != null) { this.seek(sec); this.$refs.video.pause(); } },
        scrub(e) {
            const r = e.currentTarget.getBoundingClientRect();
            this.seek(((e.clientX - r.left) / r.width) * (this.duration || 0));
        },
        pos(sec) { return this.duration ? `${Math.min(100, (sec / this.duration) * 100)}%` : '0%'; },
        clock,

        // ——— Comments ———
        get open() { return this.s.comments.filter((c) => !c.resolved); },
        get shown() { return this.showResolved ? this.s.comments : this.open; },
        async addComment() {
            const body = this.body.trim();
            if (!body || this.busy) return;
            this.busy = true;
            try {
                this.s = await this.call('POST', urls.comment, { body, at_seconds: this.pin ? Math.round(this.t * 100) / 100 : null });
                this.body = '';
            } catch (e) { this.error = e.message; } finally { this.busy = false; }
        },
        startComment() {
            this.$refs.video.pause();
            this.pin = true;
            this.$nextTick(() => this.$refs.comment?.focus());
        },
        async resolve(c, resolved = true) {
            try { this.s = await this.call('PATCH', urls.resolve.replace('__ID__', c.id), { resolved }); } catch (e) { this.error = e.message; }
        },
        async remove(c) {
            if (!confirm('Delete this comment?')) return;
            try { this.s = await this.call('DELETE', urls.resolve.replace('__ID__', c.id)); } catch (e) { this.error = e.message; }
        },
        /** Turn an AI flag into a comment the editor will get as a correction. */
        async adopt(issue) {
            try {
                this.s = await this.call('POST', urls.comment, { body: issue.note, at_seconds: issue.at });
            } catch (e) { this.error = e.message; }
        },

        // ——— Verdict ———
        async approve() {
            if (this.open.length && !confirm(`There ${this.open.length === 1 ? 'is 1 open comment' : `are ${this.open.length} open comments`}. Approve anyway?`)) return;
            await this.verdict(urls.approve);
        },
        async changes() {
            if (!this.open.length && !this.note.trim()) { this.error = 'Add a comment on the video, or a note, saying what to change.'; return; }
            await this.verdict(urls.changes);
        },
        async verdict(url) {
            this.busy = true;
            this.error = '';
            try { this.s = await this.call('POST', url, { note: this.note.trim() || null }); this.note = ''; } catch (e) { this.error = e.message; } finally { this.busy = false; }
        },
        async recheck() {
            try { this.s = await this.call('POST', urls.recheck); } catch (e) { this.error = e.message; }
        },
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
