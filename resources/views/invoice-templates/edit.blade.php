<x-settings-layout title="PDF template">
    <x-slot name="header">
        <x-page-header title="Invoice PDF Template">
            <x-slot name="actions">
                <form method="POST" action="{{ route('invoice-template.reset') }}" onsubmit="return confirm('Reset to the classic Chakra layout? Your custom blocks/HTML will be replaced.');">
                    @csrf
                    <x-secondary-button type="submit">Reset to classic</x-secondary-button>
                </form>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div
        class="space-y-4"
        x-data="pdfTemplateEditor(@js([
            'name' => old('name', $template->name),
            'mode' => old('mode', $template->mode),
            'blocks' => old('blocks') ? json_decode(old('blocks'), true) : ($template->blocks ?: \App\Models\InvoiceTemplate::defaultBlocks()),
            'html' => old('html', $template->html ?? ''),
            'customCss' => old('custom_css', $template->custom_css ?? ''),
            'catalog' => $catalog,
            'placeholders' => $placeholders,
            'previewUrl' => route('invoice-template.preview'),
            'generateHtmlUrl' => route('invoice-template.generate-html'),
            'sampleInvoiceId' => $sampleInvoiceId,
            'csrf' => csrf_token(),
        ]))"
        x-init="init()"
    >
        @if (session('status'))
            <div class="rounded-md bg-white/5 border border-brand-200 px-4 py-3 text-sm text-white">
                {{ session('status') }}
            </div>
        @endif

        <x-input-error :messages="$errors->all()" class="mb-2" />

        <form method="POST" action="{{ route('invoice-template.update') }}" @submit="beforeSubmit">
            @csrf
            @method('PUT')

            <input type="hidden" name="mode" :value="mode">
            <input type="hidden" name="blocks" :value="JSON.stringify(blocks)">
            <input type="hidden" name="html" :value="html">
            <input type="hidden" name="custom_css" :value="customCss">

            <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
                {{-- Editor pane --}}
                <div class="space-y-4">
                    <x-card class="p-4 sm:p-5 space-y-4">
                        <div>
                            <x-input-label for="template_name" value="Template name" />
                            <x-text-input id="template_name" name="name" type="text" class="mt-1" x-model="name" required />
                        </div>

                        <div class="flex flex-wrap gap-2 border-b border-white/10 pb-3">
                            <button
                                type="button"
                                @click="setMode('blocks')"
                                :class="mode === 'blocks' ? 'bg-brand-400 text-brand-900' : 'bg-white/10 text-brand-100/80 hover:bg-white/[0.16]'"
                                class="inline-flex items-center min-h-[40px] px-4 rounded-md text-xs font-semibold uppercase tracking-widest transition"
                            >
                                Drag blocks
                            </button>
                            <button
                                type="button"
                                @click="setMode('html')"
                                :class="mode === 'html' ? 'bg-brand-400 text-brand-900' : 'bg-white/10 text-brand-100/80 hover:bg-white/[0.16]'"
                                class="inline-flex items-center min-h-[40px] px-4 rounded-md text-xs font-semibold uppercase tracking-widest transition"
                            >
                                HTML editor
                            </button>
                            <button
                                type="button"
                                @click="refreshPreview()"
                                class="ml-auto inline-flex items-center min-h-[40px] px-3 rounded-md text-xs font-semibold uppercase tracking-widest bg-white/5 border border-white/15 text-brand-100/80 hover:bg-white/[0.09]"
                            >
                                Refresh preview
                            </button>
                        </div>

                        {{-- Blocks mode --}}
                        <div x-show="mode === 'blocks'" x-cloak class="space-y-4">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wider text-brand-100/60 mb-2">Pinned (page chrome)</p>
                                <div class="space-y-2">
                                    <template x-for="block in fixedBlocks" :key="block.id">
                                        <div class="flex items-start gap-3 rounded-md border border-white/10 bg-brand-900/40 px-3 py-2.5">
                                            <label class="flex items-center gap-2 pt-0.5">
                                                <input type="checkbox" class="rounded border-white/15 text-brand-400 focus:ring-brand-400" x-model="block.enabled" @change="queuePreview()">
                                                <span class="text-sm font-semibold text-white" x-text="labelFor(block.type)"></span>
                                            </label>
                                            <p class="text-xs text-brand-100/60 ml-auto" x-text="descFor(block.type)"></p>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <div>
                                <div class="flex items-center justify-between mb-2">
                                    <p class="text-xs font-semibold uppercase tracking-wider text-brand-100/60">Content blocks (drag to reorder)</p>
                                    <div class="relative" x-data="{ open: false }">
                                        <button type="button" @click="open = !open" class="text-xs font-semibold text-brand-300 hover:text-brand-200">+ Add block</button>
                                        <div
                                            x-show="open"
                                            @click.outside="open = false"
                                            x-cloak
                                            class="absolute right-0 z-20 mt-1 w-56 rounded-md border border-white/10 bg-white/5 shadow-lg py-1"
                                        >
                                            <template x-for="item in addableCatalog" :key="item.type">
                                                <button
                                                    type="button"
                                                    class="w-full text-left px-3 py-2 text-sm hover:bg-white/[0.09]"
                                                    @click="addBlock(item.type); open = false"
                                                >
                                                    <span class="font-medium text-white" x-text="item.label"></span>
                                                    <span class="block text-xs text-brand-100/60" x-text="item.description"></span>
                                                </button>
                                            </template>
                                        </div>
                                    </div>
                                </div>

                                <div x-ref="sortableList" class="space-y-2 min-h-[80px]">
                                    <template x-for="(block, index) in flowBlocks" :key="block.id">
                                        <div
                                            class="rounded-md border border-white/10 bg-white/5 px-3 py-2.5"
                                            :data-id="block.id"
                                        >
                                            <div class="flex items-start gap-2">
                                                <button type="button" class="drag-handle cursor-grab text-brand-100/50 hover:text-brand-100/70 pt-0.5" title="Drag">
                                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M7 4a1 1 0 110 2 1 1 0 010-2zm0 5a1 1 0 110 2 1 1 0 010-2zm0 5a1 1 0 110 2 1 1 0 010-2zm6-10a1 1 0 110 2 1 1 0 010-2zm0 5a1 1 0 110 2 1 1 0 010-2zm0 5a1 1 0 110 2 1 1 0 010-2z"/></svg>
                                                </button>
                                                <div class="flex-1 min-w-0">
                                                    <div class="flex items-center gap-2">
                                                        <label class="flex items-center gap-2">
                                                            <input type="checkbox" class="rounded border-white/15 text-brand-400 focus:ring-brand-400" x-model="block.enabled" @change="queuePreview()">
                                                            <span class="text-sm font-semibold text-white" x-text="labelFor(block.type)"></span>
                                                        </label>
                                                        <button type="button" class="ml-auto text-xs text-red-300 hover:text-red-200" @click="removeBlock(block.id)">Remove</button>
                                                    </div>

                                                    <div class="mt-2 space-y-2" x-show="block.enabled">
                                                        <template x-if="block.type === 'header'">
                                                            <div>
                                                                <label class="text-xs text-brand-100/60">Title</label>
                                                                <input type="text" class="mt-0.5 block w-full rounded-md border-white/15 text-sm focus:border-brand-400 focus:ring-brand-400" x-model="block.settings.title" @input="queuePreview()">
                                                            </div>
                                                        </template>
                                                        <template x-if="block.type === 'client'">
                                                            <div>
                                                                <label class="text-xs text-brand-100/60">Label</label>
                                                                <input type="text" class="mt-0.5 block w-full rounded-md border-white/15 text-sm focus:border-brand-400 focus:ring-brand-400" x-model="block.settings.label" @input="queuePreview()">
                                                            </div>
                                                        </template>
                                                        <template x-if="block.type === 'intro'">
                                                            <div>
                                                                <label class="text-xs text-brand-100/60">Heading</label>
                                                                <input type="text" class="mt-0.5 block w-full rounded-md border-white/15 text-sm focus:border-brand-400 focus:ring-brand-400" x-model="block.settings.heading" @input="queuePreview()">
                                                            </div>
                                                        </template>
                                                        <template x-if="block.type === 'items'">
                                                            <div class="grid grid-cols-3 gap-2">
                                                                <div>
                                                                    <label class="text-xs text-brand-100/60">Items column</label>
                                                                    <input type="text" class="mt-0.5 block w-full rounded-md border-white/15 text-sm focus:border-brand-400 focus:ring-brand-400" x-model="block.settings.items_label" @input="queuePreview()">
                                                                </div>
                                                                <div>
                                                                    <label class="text-xs text-brand-100/60">Qty column</label>
                                                                    <input type="text" class="mt-0.5 block w-full rounded-md border-white/15 text-sm focus:border-brand-400 focus:ring-brand-400" x-model="block.settings.qty_label" @input="queuePreview()">
                                                                </div>
                                                                <div>
                                                                    <label class="text-xs text-brand-100/60">Rate column</label>
                                                                    <input type="text" class="mt-0.5 block w-full rounded-md border-white/15 text-sm focus:border-brand-400 focus:ring-brand-400" x-model="block.settings.rate_label" @input="queuePreview()">
                                                                </div>
                                                            </div>
                                                        </template>
                                                        <template x-if="block.type === 'total'">
                                                            <div>
                                                                <label class="text-xs text-brand-100/60">Label</label>
                                                                <input type="text" class="mt-0.5 block w-full rounded-md border-white/15 text-sm focus:border-brand-400 focus:ring-brand-400" x-model="block.settings.label" @input="queuePreview()">
                                                            </div>
                                                        </template>
                                                        <template x-if="block.type === 'text'">
                                                            <div>
                                                                <label class="text-xs text-brand-100/60">Text</label>
                                                                <textarea rows="3" class="mt-0.5 block w-full rounded-md border-white/15 text-sm focus:border-brand-400 focus:ring-brand-400" x-model="block.settings.content" @input="queuePreview()"></textarea>
                                                            </div>
                                                        </template>
                                                        <template x-if="block.type === 'spacer'">
                                                            <div>
                                                                <label class="text-xs text-brand-100/60">Height (mm)</label>
                                                                <input type="number" min="2" max="40" class="mt-0.5 block w-28 rounded-md border-white/15 text-sm focus:border-brand-400 focus:ring-brand-400" x-model.number="block.settings.height_mm" @input="queuePreview()">
                                                            </div>
                                                        </template>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>

                        {{-- HTML mode --}}
                        <div x-show="mode === 'html'" x-cloak class="space-y-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <button
                                    type="button"
                                    @click="generateFromBlocks()"
                                    class="inline-flex items-center min-h-[36px] px-3 rounded-md text-xs font-semibold uppercase tracking-widest bg-white/5 border border-white/15 text-brand-100/80 hover:bg-white/[0.09]"
                                    :disabled="generating"
                                >
                                    <span x-text="generating ? 'Generating…' : 'Generate from blocks'"></span>
                                </button>
                                <p class="text-xs text-brand-100/60">Use placeholders like <code class="bg-white/10 px-1 rounded">@{{ client_name }}</code> — they fill from invoice data.</p>
                            </div>

                            <div class="flex flex-wrap gap-1.5">
                                <template x-for="ph in placeholders" :key="ph">
                                    <button
                                        type="button"
                                        class="text-[11px] font-mono px-1.5 py-0.5 rounded bg-white/10 text-brand-100/80 hover:bg-white/10 hover:text-brand-200"
                                        @click="insertPlaceholder(ph)"
                                        x-text="'@{{' + ph + '}}'"
                                    ></button>
                                </template>
                            </div>

                            <textarea
                                x-ref="htmlEditor"
                                rows="22"
                                class="w-full font-mono text-xs leading-relaxed rounded-md border-white/15 focus:border-brand-400 focus:ring-brand-400"
                                x-model="html"
                                @input="queuePreview()"
                                spellcheck="false"
                            ></textarea>

                            <div>
                                <x-input-label value="Extra CSS (optional)" />
                                <textarea
                                    rows="4"
                                    class="mt-1 w-full font-mono text-xs rounded-md border-white/15 focus:border-brand-400 focus:ring-brand-400"
                                    x-model="customCss"
                                    @input="queuePreview()"
                                    spellcheck="false"
                                    placeholder=".invoice-heading { color: #ABDAE7; }"
                                ></textarea>
                                <p class="text-xs text-brand-100/60 mt-1">Keep CSS simple — dompdf does not support flexbox or many modern features.</p>
                            </div>
                        </div>

                        <div class="pt-2 flex flex-wrap gap-2">
                            <x-primary-button>Save template</x-primary-button>
                            <p class="text-xs text-brand-100/60 self-center">Applies to all new PDF downloads and invoice previews.</p>
                        </div>
                    </x-card>
                </div>

                {{-- Live preview --}}
                <div class="xl:sticky xl:top-4 self-start">
                    <x-card class="p-3 sm:p-4">
                        <div class="flex items-center justify-between mb-3">
                            <p class="text-sm font-semibold text-white">Live preview</p>
                            <span class="text-xs text-brand-100/60" x-text="previewStatus"></span>
                        </div>
                        <div class="bg-white/10 rounded-md overflow-auto" style="max-height: calc(100vh - 10rem);">
                            <div class="mx-auto my-3 shadow-md bg-white/5" style="width: 210mm; min-height: 297mm; transform-origin: top center;">
                                <iframe
                                    x-ref="previewFrame"
                                    title="Invoice PDF preview"
                                    class="w-full border-0 bg-white/5"
                                    style="height: 297mm;"
                                    sandbox="allow-same-origin"
                                ></iframe>
                            </div>
                        </div>
                    </x-card>
                </div>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
    <script>
        function pdfTemplateEditor(config) {
            const FIXED = ['watermark', 'signature', 'footer'];

            return {
                name: config.name,
                mode: config.mode || 'blocks',
                blocks: Array.isArray(config.blocks) ? config.blocks : [],
                html: config.html || '',
                customCss: config.customCss || '',
                catalog: config.catalog || [],
                placeholders: config.placeholders || [],
                previewUrl: config.previewUrl,
                generateHtmlUrl: config.generateHtmlUrl,
                sampleInvoiceId: config.sampleInvoiceId,
                csrf: config.csrf,
                previewStatus: 'Idle',
                generating: false,
                previewTimer: null,
                sortable: null,

                get fixedBlocks() {
                    return this.blocks.filter(b => FIXED.includes(b.type));
                },

                get flowBlocks() {
                    return this.blocks.filter(b => !FIXED.includes(b.type));
                },

                get addableCatalog() {
                    return this.catalog.filter(c => !c.fixed);
                },

                init() {
                    this.$nextTick(() => {
                        this.initSortable();
                        this.refreshPreview();
                    });
                },

                initSortable() {
                    if (this.sortable) {
                        this.sortable.destroy();
                        this.sortable = null;
                    }
                    if (!this.$refs.sortableList || typeof Sortable === 'undefined') return;

                    this.sortable = Sortable.create(this.$refs.sortableList, {
                        handle: '.drag-handle',
                        animation: 150,
                        onEnd: () => {
                            const ids = [...this.$refs.sortableList.querySelectorAll('[data-id]')].map(el => el.getAttribute('data-id'));
                            const fixed = this.blocks.filter(b => FIXED.includes(b.type));
                            const byId = Object.fromEntries(this.blocks.map(b => [b.id, b]));
                            const flow = ids.map(id => byId[id]).filter(Boolean);
                            const watermark = fixed.filter(b => b.type === 'watermark');
                            const pinned = fixed.filter(b => b.type !== 'watermark');
                            this.blocks = [...watermark, ...flow, ...pinned];
                            this.queuePreview();
                            this.$nextTick(() => this.initSortable());
                        },
                    });
                },

                labelFor(type) {
                    return this.catalog.find(c => c.type === type)?.label || type;
                },

                descFor(type) {
                    return this.catalog.find(c => c.type === type)?.description || '';
                },

                setMode(mode) {
                    this.mode = mode;
                    if (mode === 'blocks') {
                        this.$nextTick(() => this.initSortable());
                    }
                    this.queuePreview();
                },

                addBlock(type) {
                    const defaults = {
                        header: { title: 'INVOICE' },
                        client: { label: 'Quotation to :' },
                        intro: { heading: 'Dear Client' },
                        items: { items_label: 'Items', qty_label: 'Qty', rate_label: 'Rate' },
                        total: { label: 'TOTAL :' },
                        text: { content: '' },
                        spacer: { height_mm: 6 },
                    };
                    const block = {
                        id: 'b_' + Date.now() + '_' + Math.random().toString(36).slice(2, 7),
                        type,
                        enabled: true,
                        settings: defaults[type] || {},
                    };
                    // Insert before signature/footer if present
                    const sigIndex = this.blocks.findIndex(b => b.type === 'signature' || b.type === 'footer');
                    if (sigIndex >= 0) {
                        this.blocks.splice(sigIndex, 0, block);
                    } else {
                        this.blocks.push(block);
                    }
                    this.$nextTick(() => this.initSortable());
                    this.queuePreview();
                },

                removeBlock(id) {
                    this.blocks = this.blocks.filter(b => b.id !== id);
                    this.$nextTick(() => this.initSortable());
                    this.queuePreview();
                },

                queuePreview() {
                    clearTimeout(this.previewTimer);
                    this.previewStatus = 'Updating…';
                    this.previewTimer = setTimeout(() => this.refreshPreview(), 450);
                },

                async refreshPreview() {
                    this.previewStatus = 'Loading…';
                    try {
                        const body = new FormData();
                        body.append('mode', this.mode);
                        body.append('blocks', JSON.stringify(this.blocks));
                        body.append('html', this.html || '');
                        body.append('custom_css', this.customCss || '');
                        if (this.sampleInvoiceId) {
                            body.append('invoice_id', this.sampleInvoiceId);
                        }

                        const res = await fetch(this.previewUrl, {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': this.csrf,
                                'Accept': 'text/html',
                            },
                            body,
                        });

                        if (!res.ok) throw new Error('Preview failed');
                        const html = await res.text();
                        const frame = this.$refs.previewFrame;
                        if (frame) {
                            frame.srcdoc = html;
                        }
                        this.previewStatus = 'Up to date';
                    } catch (e) {
                        this.previewStatus = 'Preview error';
                    }
                },

                async generateFromBlocks() {
                    this.generating = true;
                    try {
                        const body = new FormData();
                        body.append('blocks', JSON.stringify(this.blocks));

                        const res = await fetch(this.generateHtmlUrl, {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': this.csrf,
                                'Accept': 'application/json',
                            },
                            body,
                        });
                        const data = await res.json();
                        if (data.html) {
                            this.html = data.html;
                            this.queuePreview();
                        }
                    } finally {
                        this.generating = false;
                    }
                },

                insertPlaceholder(name) {
                    const token = '{{' + name + '}}';
                    const el = this.$refs.htmlEditor;
                    if (!el) {
                        this.html += token;
                        this.queuePreview();
                        return;
                    }
                    const start = el.selectionStart ?? this.html.length;
                    const end = el.selectionEnd ?? start;
                    this.html = this.html.slice(0, start) + token + this.html.slice(end);
                    this.$nextTick(() => {
                        el.focus();
                        const pos = start + token.length;
                        el.setSelectionRange(pos, pos);
                    });
                    this.queuePreview();
                },

                beforeSubmit() {
                    // Ensure hidden fields are synced (Alpine already bound).
                },
            };
        }
    </script>
</x-settings-layout>
