{{--
    One script on its live link (set/{token}), for the shoot: no login, read
    and edit on a phone, every phone holding the link kept in step by polling
    (this host cannot hold a websocket open). Standalone like the review
    pages -- no portal chrome for someone who is not in the portal.

    Same rules as the portal's block editor (scripts/_editor): a body is set
    once and never re-rendered while someone is typing in it, saves are per
    section with a version check, and a clash is shown, never merged.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $script->title }} — Chakra</title>
    <meta name="robots" content="noindex, nofollow">
    @include('partials.favicon')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        [x-cloak] { display: none !important; }
        .live-body { font-size: var(--live-size, 18px); line-height: 1.6; }
        .live-body:empty::before { content: 'Write here…'; color: rgba(228, 242, 247, .35); }
        .live-body ul { list-style: disc; padding-left: 1.4rem; margin: .4rem 0; }
        .live-body ol { list-style: decimal; padding-left: 1.4rem; margin: .4rem 0; }
        .live-body p { margin: 0 0 .5rem; }
        .live-body a { color: #8ACCE0; text-decoration: underline; }
    </style>
    <script>
        function liveScript(config) {
            const NAME_KEY = 'chakra.live-script.name';
            const SIZE_KEY = 'chakra.live-script.size';
            const read = (k, d) => { try { return localStorage.getItem(k) ?? d; } catch (e) { return d; } };
            const write = (k, v) => { try { localStorage.setItem(k, v); } catch (e) {} };

            return {
                sections: config.state.sections.map((s) => ({ ...s, conflict: null })),
                editedBy: config.state.edited_by,
                editedAt: config.state.edited_at,
                name: read(NAME_KEY, ''),
                size: Number(read(SIZE_KEY, 18)),
                state: 'idle',
                online: true,
                newHeading: '',
                focusedId: null,
                timers: {},
                inFlight: {},
                held: {}, // newer copies from someone else, waiting for this phone to stop typing in that section

                init() {
                    try { document.execCommand('styleWithCSS', false, false); } catch (e) {}
                    setInterval(() => this.poll(), 4000);
                    document.addEventListener('visibilitychange', () => {
                        if (document.visibilityState === 'hidden') this.flushAll();
                        else this.poll();
                    });
                },

                get statusLine() {
                    if (!this.online) return 'Offline — retrying';
                    if (this.state === 'saving') return 'Saving…';
                    if (this.state === 'error') return 'Could not save — retrying';
                    if (this.state === 'saved') return 'Saved';
                    return 'Live';
                },
                get editedLine() {
                    if (!this.editedBy || !this.editedAt) return '';
                    const mins = Math.round((Date.now() - new Date(this.editedAt)) / 60000);
                    const ago = mins < 1 ? 'just now' : mins < 60 ? mins + ' min ago' : Math.round(mins / 60) + ' h ago';
                    return 'Last edit: ' + this.editedBy + ', ' + ago;
                },

                rememberName() { write(NAME_KEY, (this.name || '').trim()); },
                bigger() { this.size = Math.min(32, this.size + 2); write(SIZE_KEY, this.size); },
                smaller() { this.size = Math.max(14, this.size - 2); write(SIZE_KEY, this.size); },

                el(id) { return this.$root.querySelector('[data-body="' + id + '"]'); },
                busy(id) { return !!this.timers[id] || !!this.inFlight[id]; },

                run(command) {
                    document.execCommand(command, false, null);
                    const s = this.sections.find((x) => x.id === this.focusedId);
                    if (s) { s.body = this.el(s.id).innerHTML; this.queue(s); }
                },
                paste(event) {
                    document.execCommand('insertText', false, (event.clipboardData || window.clipboardData).getData('text/plain'));
                },
                blurred(section) {
                    this.focusedId = null;
                    if (this.held[section.id] && !this.busy(section.id)) this.take(section, this.held[section.id]);
                },

                queue(section) {
                    clearTimeout(this.timers[section.id]);
                    this.state = 'saving';
                    this.timers[section.id] = setTimeout(() => this.save(section), 800);
                },
                flushAll() {
                    Object.keys(this.timers).forEach((id) => {
                        clearTimeout(this.timers[id]);
                        const s = this.sections.find((x) => String(x.id) === id);
                        if (s) this.save(s);
                    });
                },

                async request(url, method, payload) {
                    return fetch(url, {
                        method,
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: payload ? JSON.stringify({ ...payload, name: (this.name || '').trim() || null }) : undefined,
                    });
                },

                async save(section) {
                    delete this.timers[section.id];
                    if (this.inFlight[section.id]) { this.inFlight[section.id] = 'again'; return; }
                    this.inFlight[section.id] = true;
                    try {
                        const res = await this.request(config.urls.section.replace(/0$/, section.id), 'PATCH',
                            { version: section.version, heading: section.heading, body: section.body });
                        if (res.status === 409) {
                            section.conflict = await res.json();
                            this.state = 'idle';
                            return;
                        }
                        if (!res.ok) throw new Error('save');
                        const data = await res.json();
                        section.version = data.version;
                        section.conflict = null;
                        delete this.held[section.id];
                        this.editedBy = (this.name || '').trim() || 'Someone on the live link';
                        this.editedAt = new Date().toISOString();
                        this.state = Object.keys(this.timers).length ? 'saving' : 'saved';
                        this.online = true;
                    } catch (e) {
                        this.state = 'error';
                        setTimeout(() => this.save(section), 4000);
                    } finally {
                        const again = this.inFlight[section.id] === 'again';
                        delete this.inFlight[section.id];
                        if (again) this.save(section);
                    }
                },

                resolve(section, choice) {
                    const theirs = section.conflict.current;
                    if (choice === 'theirs') {
                        section.body = theirs.body || '';
                        section.heading = theirs.heading;
                        this.el(section.id).innerHTML = section.body;
                        section.version = theirs.version;
                        section.conflict = null;
                        return;
                    }
                    section.version = theirs.version; // keep mine: save on top of theirs, knowingly
                    section.conflict = null;
                    this.save(section);
                },

                async addSection() {
                    const heading = (this.newHeading || '').trim();
                    if (!heading) return;
                    const res = await this.request(config.urls.store, 'POST', { heading });
                    if (!res.ok) { this.state = 'error'; return; }
                    const s = await res.json();
                    if (!this.sections.some((x) => x.id === s.id)) this.sections.push({ ...s, conflict: null });
                    this.newHeading = '';
                    this.$nextTick(() => this.el(s.id)?.focus());
                },

                take(section, fresh) {
                    section.heading = fresh.heading;
                    section.version = fresh.version;
                    section.body = fresh.body;
                    const el = this.el(section.id);
                    if (el && el.innerHTML !== fresh.body) el.innerHTML = fresh.body;
                    delete this.held[section.id];
                },

                // Someone else's edits: applied straight away, except in the
                // section this phone is typing in -- that one waits for blur.
                async poll() {
                    if (document.visibilityState === 'hidden') return;
                    try {
                        const res = await fetch(config.urls.state, { headers: { 'Accept': 'application/json' } });
                        if (!res.ok) throw new Error('poll');
                        const data = await res.json();
                        this.online = true;
                        this.editedBy = data.edited_by;
                        this.editedAt = data.edited_at;

                        const mine = new Map(this.sections.map((s) => [s.id, s]));
                        data.sections.forEach((fresh) => {
                            const s = mine.get(fresh.id);
                            if (!s || fresh.version <= s.version) return;
                            if (this.busy(s.id) || this.focusedId === s.id || s.conflict) this.held[s.id] = fresh;
                            else this.take(s, fresh);
                        });
                        const ids = data.sections.map((s) => s.id);
                        const kept = this.sections.filter((s) => ids.includes(s.id) || this.busy(s.id));
                        data.sections.forEach((fresh) => { if (!mine.has(fresh.id)) kept.push({ ...fresh, conflict: null }); });
                        kept.sort((a, b) => {
                            const ia = ids.indexOf(a.id), ib = ids.indexOf(b.id);
                            return (ia === -1 ? 1e9 : ia) - (ib === -1 ? 1e9 : ib);
                        });
                        if (kept.map((s) => s.id).join() !== this.sections.map((s) => s.id).join()) this.sections = kept;
                    } catch (e) {
                        this.online = false;
                    }
                },
            };
        }
    </script>
</head>
<body class="antialiased bg-brand-900 text-white">
<div x-data="liveScript({{ Illuminate\Support\Js::from([
        'state' => $state,
        'urls' => [
            'state' => route('scripts.live.state', $script->share_token),
            'section' => route('scripts.live.update', [$script->share_token, 0]),
            'store' => route('scripts.live.store', $script->share_token),
        ],
    ]) }})" :style="'--live-size:' + size + 'px'">

    <header class="sticky top-0 z-20 border-b border-white/10 bg-brand-900/90 backdrop-blur">
        <div class="mx-auto flex max-w-3xl items-center gap-3 px-4 py-2.5">
            <x-application-logo class="h-7 w-auto shrink-0" />
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold">{{ $script->title }}</p>
                <p class="truncate text-[11px] text-brand-100/50" x-text="editedLine"></p>
            </div>
            <span class="flex shrink-0 items-center gap-1.5 text-xs"
                  :class="!online || state === 'error' ? 'text-red-300' : (state === 'saving' ? 'text-brand-300' : 'text-emerald-300')">
                <span class="h-2 w-2 rounded-full" :class="!online || state === 'error' ? 'bg-red-400' : (state === 'saving' ? 'bg-brand-400 animate-pulse' : 'bg-emerald-400')"></span>
                <span x-text="statusLine"></span>
            </span>
        </div>
        <div class="mx-auto flex max-w-3xl items-center gap-1 px-4 pb-2">
            <button type="button" @mousedown.prevent @click="run('bold')" class="h-9 min-w-[36px] rounded-md px-2 text-sm font-bold text-brand-100/80 hover:bg-white/10" aria-label="Bold">B</button>
            <button type="button" @mousedown.prevent @click="run('italic')" class="h-9 min-w-[36px] rounded-md px-2 text-sm italic text-brand-100/80 hover:bg-white/10" aria-label="Italic">I</button>
            <button type="button" @mousedown.prevent @click="run('insertUnorderedList')" class="h-9 min-w-[36px] rounded-md px-2 text-sm text-brand-100/80 hover:bg-white/10" aria-label="Bullet list">•</button>
            <span class="ml-auto"></span>
            <button type="button" @click="smaller()" class="h-9 w-9 rounded-md bg-white/10 text-xs font-bold" aria-label="Smaller text">A−</button>
            <button type="button" @click="bigger()" class="h-9 w-9 rounded-md bg-white/10 text-sm font-bold" aria-label="Bigger text">A+</button>
        </div>
    </header>

    <main class="mx-auto max-w-3xl space-y-3 px-4 pb-24 pt-4">
        <label class="flex items-center gap-2 rounded-xl bg-white/5 px-3 py-2 ring-1 ring-white/10">
            <span class="shrink-0 text-xs text-brand-100/50">Your name</span>
            <input type="text" x-model="name" @change="rememberName()" maxlength="80" placeholder="So others see who changed what"
                   class="min-w-0 flex-1 border-0 bg-transparent p-0 text-sm text-white placeholder:text-brand-100/30 focus:ring-0">
        </label>

        <template x-for="section in sections" :key="section.id">
            <section class="rounded-xl bg-white/5 p-4 ring-1 transition" :class="section.conflict ? 'ring-amber-400/40 bg-amber-400/10' : 'ring-white/10'">
                <input type="text" x-model="section.heading" @input="queue(section)" maxlength="160" aria-label="Section name"
                       class="w-full border-0 border-b border-transparent bg-transparent px-0 text-xs font-semibold uppercase tracking-[0.16em] text-brand-300 focus:border-brand-400 focus:ring-0">
                <div contenteditable="true" :data-body="section.id"
                     x-init="$el.innerHTML = section.body"
                     @focus="focusedId = section.id"
                     @blur="blurred(section)"
                     @input="section.body = $event.target.innerHTML; queue(section)"
                     @paste.prevent="paste($event)"
                     class="live-body mt-2 min-h-[56px] text-white focus:outline-none"></div>

                <div x-show="section.conflict" x-cloak class="mt-3 rounded-lg border border-amber-400/30 bg-amber-400/15 p-3">
                    <p class="text-sm font-semibold text-amber-200" x-text="section.conflict?.message"></p>
                    <p class="mt-1 text-xs text-amber-200/80">Your words are still on screen. Nothing was saved over.</p>
                    <div class="mt-2 flex gap-2">
                        <button type="button" @click="resolve(section, 'mine')" class="rounded-md bg-white px-3 py-1.5 text-sm font-semibold text-brand-900">Keep mine</button>
                        <button type="button" @click="resolve(section, 'theirs')" class="rounded-md bg-white/10 px-3 py-1.5 text-sm font-semibold">Use theirs</button>
                    </div>
                </div>
            </section>
        </template>

        <template x-if="sections.length === 0">
            <p class="rounded-xl bg-white/5 px-4 py-6 text-center text-sm text-brand-100/50">Nothing written yet. Add the first section below.</p>
        </template>

        <div class="flex items-center gap-2 pt-1">
            <input type="text" x-model="newHeading" @keydown.enter.prevent="addSection()" maxlength="160" placeholder="New section, e.g. Hook"
                   class="min-h-[44px] min-w-0 flex-1 rounded-lg border-white/15 bg-white/5 text-sm text-white placeholder:text-brand-100/30 focus:border-brand-400 focus:ring-brand-400">
            <button type="button" @click="addSection()" class="min-h-[44px] shrink-0 rounded-lg bg-white px-4 text-sm font-semibold text-brand-900">Add section</button>
        </div>
    </main>
</div>
</body>
</html>
