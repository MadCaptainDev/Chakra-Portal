@php
    /*
    | The block editor.
    |
    | Sections are contenteditable blocks that save themselves. Three things
    | about the implementation are load-bearing and easy to undo by accident:
    |
    | 1. The section body is NEVER bound with x-model and never re-rendered
    |    from Alpine state while focused. Writing innerHTML on every keystroke
    |    resets the caret to the start of the block. It is set once on init and
    |    read one-way afterwards.
    | 2. The x-for carries :key="section.id". Without it Alpine recycles DOM
    |    nodes on reorder and a recycled node keeps the previous block's text --
    |    drag one section and another one's words move.
    | 3. draggable sits on the handle, not the card. A draggable card wrapping
    |    an editable means selecting a sentence starts a drag.
    */
    $sectionData = $script->sections->map(fn ($section) => [
        'id' => $section->id,
        'heading' => $section->heading,
        'body' => $section->body ?? '',
        'version' => $section->version,
    ])->values();
@endphp

<div x-data="scriptEditor({
        scriptId: {{ $script->id }},
        sections: {{ Illuminate\Support\Js::from($sectionData) }},
        urls: {
            store: '{{ route('scripts.sections.store', $script) }}',
            reorder: '{{ route('scripts.sections.reorder', $script) }}',
            section: '{{ route('scripts.sections.update', [$script, 0]) }}',
        },
     })"
     @keydown.escape="linkFor = null">

    {{-- Sticky toolbar. One for the whole editor, acting on whichever block
         has focus -- twenty toolbars is noise, and a bottom-fixed bar hides
         behind the iOS keyboard. --}}
    <div class="sticky top-0 z-20 -mx-4 sm:mx-0 px-4 sm:px-0 py-2 bg-brand-900/85 backdrop-blur border-b border-white/10 sm:rounded-t-xl sm:border sm:border-b-0">
        <div class="flex flex-wrap items-center gap-1">
            <template x-for="cmd in commands" :key="cmd.name">
                <button type="button" @mousedown.prevent @click="run(cmd.name)"
                        :aria-label="cmd.label" :title="cmd.label"
                        class="inline-flex items-center justify-center min-w-[38px] min-h-[38px] px-2 rounded-md text-sm font-semibold text-brand-100/80 hover:bg-white/[0.16] transition">
                    <span x-text="cmd.glyph" :class="cmd.classes"></span>
                </button>
            </template>

            <span class="w-px h-6 bg-white/20 mx-1"></span>

            <button type="button" @mousedown.prevent @click="promptLink()"
                    class="inline-flex items-center justify-center min-h-[38px] px-3 rounded-md text-sm font-semibold text-brand-100/80 hover:bg-white/[0.16] transition">
                Link
            </button>
            <button type="button" @mousedown.prevent @click="run('removeFormat')"
                    class="inline-flex items-center justify-center min-h-[38px] px-3 rounded-md text-sm text-brand-100/70 hover:bg-white/[0.16] transition">
                Clear
            </button>

            {{-- Save state. Always visible, because the whole promise of an
                 autosaving editor is that you can see it keeping that promise. --}}
            <span class="ml-auto flex items-center gap-2 text-xs" :class="{
                    'text-brand-100/50': state === 'idle',
                    'text-brand-300': state === 'saving',
                    'text-green-300': state === 'saved',
                    'text-red-300': state === 'error',
                  }">
                <span x-show="state === 'saving'" class="w-1.5 h-1.5 rounded-full bg-brand-500 animate-pulse"></span>
                <span x-text="statusLine"></span>
            </span>
        </div>

        {{-- Link popover. A real input rather than window.prompt, which is
             blocked in some mobile browsers and cannot be styled. --}}
        <div x-show="linkFor !== null" x-cloak class="mt-2 flex flex-wrap items-center gap-2">
            <input type="url" x-model="linkUrl" x-ref="linkInput" placeholder="https://"
                   @keydown.enter.prevent="applyLink()"
                   class="flex-1 min-w-[200px] min-h-[38px] rounded-md border-white/15 text-sm focus:border-brand-400 focus:ring-brand-400">
            <x-btn type="button" size="sm" @click="applyLink()">Add link</x-btn>
            <button type="button" @click="linkFor = null" class="text-sm text-brand-100/60 hover:text-white">Cancel</button>
        </div>
    </div>

    {{-- Blocks --}}
    <div class="space-y-3 mt-3">
        <template x-for="(section, index) in sections" :key="section.id">
            <div class="group rounded-xl bg-white/5 ring-1 transition"
                 :class="section.conflict ? 'ring-amber-400/30 bg-amber-400/10' : 'ring-white/10'"
                 @dragover.prevent="dragOver(index)"
                 @drop.prevent="drop(index)">

                <div class="flex items-start gap-2 p-3 sm:p-4">
                    {{-- Handle. Only this is draggable. --}}
                    <button type="button" draggable="true"
                            @dragstart="dragStart(index, $event)" @dragend="dragEnd()"
                            :aria-label="'Reorder ' + section.heading"
                            class="shrink-0 mt-1 cursor-grab active:cursor-grabbing text-brand-100/40 hover:text-brand-100/60 min-w-[28px] min-h-[28px] flex items-center justify-center">
                        <x-icon name="grip" class="w-4 h-4" />
                    </button>

                    <div class="min-w-0 flex-1">
                        {{-- Heading is a plain input: it is a label, not prose. --}}
                        <input type="text" x-model="section.heading" @input="queue(section)"
                               :aria-label="'Section name'"
                               class="w-full border-0 border-b border-transparent hover:border-white/10 focus:border-brand-400 focus:ring-0 px-0 text-xs font-semibold uppercase tracking-[0.14em] text-brand-300 bg-transparent">

                        {{-- The writing. Set once on init, read one-way after. --}}
                        <div contenteditable="true"
                             x-ref="body"
                             x-init="$el.innerHTML = section.body"
                             @input="section.body = $event.target.innerHTML; queue(section)"
                             @focus="activeId = section.id"
                             @paste.prevent="pasteAsText($event)"
                             :data-placeholder="'Write the ' + (section.heading || 'section').toLowerCase() + '…'"
                             class="script-block mt-2 w-full min-h-[72px] text-[15px] leading-relaxed text-white focus:outline-none"></div>
                    </div>

                    {{-- Actions. Move up/down are real buttons and always
                         present: HTML5 drag does not exist on touch and cannot
                         be reached from a keyboard, so these are the mobile and
                         accessible implementation, not a nicety. --}}
                    <div class="shrink-0 flex flex-col items-center gap-0.5">
                        <button type="button" @click="move(index, -1)" :disabled="index === 0"
                                :aria-label="'Move ' + section.heading + ' up'"
                                class="min-w-[32px] min-h-[32px] rounded-md text-brand-100/50 hover:bg-white/[0.12] hover:text-brand-100/80 disabled:opacity-30 disabled:hover:bg-transparent transition">↑</button>
                        <button type="button" @click="move(index, 1)" :disabled="index === sections.length - 1"
                                :aria-label="'Move ' + section.heading + ' down'"
                                class="min-w-[32px] min-h-[32px] rounded-md text-brand-100/50 hover:bg-white/[0.12] hover:text-brand-100/80 disabled:opacity-30 disabled:hover:bg-transparent transition">↓</button>
                        <button type="button" @click="remove(section)"
                                :aria-label="'Delete ' + section.heading"
                                class="min-w-[32px] min-h-[32px] rounded-md text-brand-100/40 hover:bg-red-400/10 hover:text-red-300 transition">
                            <x-icon name="trash" class="w-4 h-4 mx-auto" />
                        </button>
                    </div>
                </div>

                {{-- Conflict. Nothing is overwritten and nothing is merged --
                     the writer decides, and "keep both" guarantees no words
                     are lost whichever they pick. --}}
                <div x-show="section.conflict" x-cloak class="px-4 pb-4">
                    <div class="rounded-lg bg-amber-400/15 border border-amber-400/30 p-3">
                        <p class="text-sm font-semibold text-amber-200" x-text="section.conflict.message"></p>
                        <p class="mt-1 text-xs text-amber-200">Your version is still on screen. Nothing has been saved over.</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <x-btn type="button" size="sm" @click="resolve(section, 'mine')">Keep mine</x-btn>
                            <x-btn type="button" size="sm" variant="secondary" @click="resolve(section, 'theirs')">Use theirs</x-btn>
                            <x-btn type="button" size="sm" variant="secondary" @click="resolve(section, 'both')">Keep both</x-btn>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>

    {{-- Add a section. Free text with suggestions -- writers invent names, and
         a closed list only teaches them to type the real one into the body. --}}
    <div class="mt-4 flex flex-wrap items-center gap-2">
        <input type="text" x-model="newHeading" list="script-section-names" placeholder="Section name"
               @keydown.enter.prevent="addSection()"
               class="min-h-[44px] rounded-md border-white/15 text-sm focus:border-brand-400 focus:ring-brand-400">
        <datalist id="script-section-names">
            @foreach ($commonHeadings as $heading)
                <option value="{{ $heading }}"></option>
            @endforeach
        </datalist>
        <x-btn type="button" size="sm" icon="plus" @click="addSection()">Add section</x-btn>
    </div>

    {{-- Reorder announcements, for anyone not watching the screen. --}}
    <p class="sr-only" aria-live="polite" x-text="announcement"></p>
</div>

@push('styles')
<style>
    .script-block:empty::before {
        content: attr(data-placeholder);
        color: rgba(228, 242, 247, 0.4);
        pointer-events: none;
    }
    .script-block ul { list-style: disc; padding-left: 1.4rem; margin: .4rem 0; }
    .script-block ol { list-style: decimal; padding-left: 1.4rem; margin: .4rem 0; }
    .script-block p { margin: 0 0 .5rem; }
    .script-block a { color: #8ACCE0; text-decoration: underline; }
</style>
@endpush

@push('scripts')
<script>
    function scriptEditor(config) {
        return {
            sections: config.sections,
            urls: config.urls,
            activeId: null,
            state: 'idle',
            savedAt: null,
            newHeading: '',
            announcement: '',
            linkFor: null,
            linkUrl: '',
            dragIndex: null,
            timers: {},
            inFlight: {},

            commands: [
                { name: 'bold', label: 'Bold', glyph: 'B', classes: 'font-bold' },
                { name: 'italic', label: 'Italic', glyph: 'I', classes: 'italic' },
                { name: 'insertUnorderedList', label: 'Bullet list', glyph: '•' },
                { name: 'insertOrderedList', label: 'Numbered list', glyph: '1.' },
            ],

            init() {
                // styleWithCSS off makes bold emit <strong> rather than a
                // <span style>, which the server allowlist would strip.
                try {
                    document.execCommand('styleWithCSS', false, false);
                    document.execCommand('defaultParagraphSeparator', false, 'p');
                } catch (e) { /* older browsers: the defaults are close enough */ }

                // Leaving with unsaved work should cost a confirm, not silence.
                window.addEventListener('beforeunload', (e) => {
                    if (this.state === 'saving' || Object.keys(this.timers).length) {
                        e.preventDefault();
                        e.returnValue = '';
                    }
                });

                // Backgrounding an app is the commonest way mobile work is lost.
                document.addEventListener('visibilitychange', () => {
                    if (document.visibilityState === 'hidden') this.flushAll();
                });
            },

            get statusLine() {
                if (this.state === 'saving') return 'Saving…';
                if (this.state === 'error') return 'Could not save — retrying';
                if (this.state === 'saved' && this.savedAt) return 'Saved ' + this.savedAt;
                return 'All changes saved';
            },

            sectionUrl(id) {
                return this.urls.section.replace(/0$/, id);
            },

            run(command) {
                document.execCommand(command, false, null);
                const section = this.sections.find(s => s.id === this.activeId);
                if (section) this.syncFromDom(section);
            },

            promptLink() {
                this.linkFor = this.activeId;
                this.linkUrl = '';
                this.$nextTick(() => this.$refs.linkInput?.focus());
            },

            applyLink() {
                if (!/^https?:\/\//i.test(this.linkUrl)) return;
                document.execCommand('createLink', false, this.linkUrl);
                this.linkFor = null;
                const section = this.sections.find(s => s.id === this.activeId);
                if (section) this.syncFromDom(section);
            },

            /*
             * Paste as plain text. The server sanitises anyway, but pasting
             * Google Docs markup would otherwise flood the block with spans
             * that the allowlist silently eats -- the writer would watch their
             * formatting vanish on save and not know why.
             */
            pasteAsText(event) {
                const text = (event.clipboardData || window.clipboardData).getData('text/plain');
                document.execCommand('insertText', false, text);
            },

            syncFromDom(section) {
                const el = this.blockFor(section.id);
                if (el) {
                    section.body = el.innerHTML;
                    this.queue(section);
                }
            },

            blockFor(id) {
                const index = this.sections.findIndex(s => s.id === id);
                return index === -1 ? null : this.$el.querySelectorAll('[contenteditable]')[index];
            },

            queue(section) {
                clearTimeout(this.timers[section.id]);
                this.state = 'saving';
                this.timers[section.id] = setTimeout(() => this.save(section), 900);
            },

            flushAll() {
                Object.keys(this.timers).forEach(id => {
                    clearTimeout(this.timers[id]);
                    const section = this.sections.find(s => String(s.id) === String(id));
                    if (section) this.save(section);
                });
            },

            async save(section) {
                delete this.timers[section.id];

                // One request per section at a time. Two PATCHes racing on the
                // same version manufacture a conflict that never happened.
                if (this.inFlight[section.id]) {
                    this.inFlight[section.id] = 'queued';
                    return;
                }
                this.inFlight[section.id] = true;

                try {
                    const response = await fetch(this.sectionUrl(section.id), {
                        method: 'PATCH',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify({
                            version: section.version,
                            heading: section.heading,
                            body: section.body,
                        }),
                    });

                    if (response.status === 409) {
                        section.conflict = await response.json();
                        this.state = 'idle';
                        return;
                    }

                    if (!response.ok) throw new Error('save failed');

                    const data = await response.json();
                    section.version = data.version;
                    section.conflict = null;
                    this.savedAt = 'just now';
                    this.state = 'saved';
                } catch (e) {
                    this.state = 'error';
                    setTimeout(() => this.save(section), 4000);
                } finally {
                    const queued = this.inFlight[section.id] === 'queued';
                    delete this.inFlight[section.id];
                    if (queued) this.save(section);
                }
            },

            resolve(section, choice) {
                const theirs = section.conflict.current;

                if (choice === 'theirs') {
                    section.body = theirs.body || '';
                    const el = this.blockFor(section.id);
                    if (el) el.innerHTML = section.body;
                } else if (choice === 'both') {
                    section.body = section.body + '<p>— their version —</p>' + (theirs.body || '');
                    const el = this.blockFor(section.id);
                    if (el) el.innerHTML = section.body;
                }

                // Either way we now save on top of their version deliberately.
                section.version = theirs.version;
                section.conflict = null;
                this.save(section);
            },

            async addSection() {
                const heading = (this.newHeading || '').trim();
                if (!heading) return;

                const response = await this.post(this.urls.store, { heading });
                if (!response) return;

                this.sections.push({ ...response, conflict: null });
                this.newHeading = '';
            },

            async remove(section) {
                if (!confirm('Delete the "' + section.heading + '" section? Its text goes with it.')) return;

                await fetch(this.sectionUrl(section.id), {
                    method: 'DELETE',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                });

                this.sections = this.sections.filter(s => s.id !== section.id);
            },

            move(index, delta) {
                const target = index + delta;
                if (target < 0 || target >= this.sections.length) return;

                const [moved] = this.sections.splice(index, 1);
                this.sections.splice(target, 0, moved);
                this.announcement = moved.heading + ' moved to position ' + (target + 1) + ' of ' + this.sections.length;
                this.persistOrder();
            },

            dragStart(index, event) {
                this.dragIndex = index;
                // Firefox will not start a drag without data on the transfer.
                event.dataTransfer.setData('text/plain', String(index));
                event.dataTransfer.effectAllowed = 'move';
            },

            dragOver(index) {
                if (this.dragIndex === null || this.dragIndex === index) return;
                const [moved] = this.sections.splice(this.dragIndex, 1);
                this.sections.splice(index, 0, moved);
                this.dragIndex = index;
            },

            drop() {
                this.dragEnd();
            },

            dragEnd() {
                if (this.dragIndex === null) return;
                this.dragIndex = null;
                this.persistOrder();
            },

            async persistOrder() {
                await this.post(this.urls.reorder, { order: this.sections.map(s => s.id) });
            },

            async post(url, payload) {
                try {
                    this.state = 'saving';
                    const response = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify(payload),
                    });

                    if (!response.ok) throw new Error('request failed');

                    this.state = 'saved';
                    this.savedAt = 'just now';

                    return await response.json();
                } catch (e) {
                    this.state = 'error';
                    return null;
                }
            },
        };
    }
</script>
@endpush
