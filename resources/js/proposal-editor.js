/*
 * The proposal editor: the document itself, typed into like a Word file.
 *
 * resources/views/proposals/_editor.blade.php draws the same A4 sheets the
 * client sees (same .cp-* classes as the read-only Blade views), with every
 * piece of text contenteditable. The state is the stored shape -- the
 * `sections` column, {key, type, data} -- so what is posted is exactly what
 * App\Support\ProposalBlocks::normalizeSections() reads; nothing is
 * translated in between.
 *
 * Two pieces:
 *
 * - x-edit, a directive binding one contenteditable element to one value.
 *   Modifiers pick how the DOM maps to that value:
 *     (none)  plain text -- labels, headings, tags; anything the Blade view
 *             prints with {{ }}.
 *     .rich   text with the three inline marks the views understand:
 *             **bold**, [ok]green[/ok], [warn]amber[/warn]. Typed as real
 *             bold (Ctrl+B or the toolbar) and written back as the marks.
 *     .paras  a paragraph block: Enter starts a new paragraph, stored as the
 *             blank line ProposalBlocks::paragraphs() splits on.
 *     .list   a list block: one <li> per item, Enter adds one.
 *   The element is only re-rendered from state while it does not have focus,
 *   so the caret never jumps while someone types.
 *
 * - proposalEditor, the page: the sections, the toolbar's actions on the
 *   block the caret is in, and saving over fetch so the page never reloads
 *   under the person writing.
 */

const TAG_NAMES = {
    requirement: 'Client Requirement',
    recommendation: 'Proposed Recommendation',
    optional: 'Optional / Future',
};

/* ------------------------------------------------------------------ */
/*  Text <-> DOM                                                        */
/* ------------------------------------------------------------------ */

function escapeHtml(text) {
    return String(text ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/** The JS twin of ProposalBlocks::inline(): escape first, then the marks. */
function inlineHtml(text) {
    return escapeHtml(text)
        .replace(/\*\*(.+?)\*\*/gs, '<strong>$1</strong>')
        .replace(/\[ok\](.+?)\[\/ok\]/gs, '<strong class="cp-ok">$1</strong>')
        .replace(/\[warn\](.+?)\[\/warn\]/gs, '<strong class="cp-warn">$1</strong>');
}

const BLOCK_TAGS = new Set(['P', 'DIV', 'LI', 'H1', 'H2', 'H3', 'UL', 'OL']);

/** Walk an element back into marked-up text. */
function inlineText(node) {
    let out = '';

    node.childNodes.forEach((child) => {
        if (child.nodeType === Node.TEXT_NODE) {
            out += child.nodeValue;

            return;
        }
        if (child.nodeType !== Node.ELEMENT_NODE) return;

        const tag = child.tagName;
        if (tag === 'BR') {
            out += '\n';

            return;
        }

        const inner = inlineText(child);
        const bold = tag === 'STRONG' || tag === 'B'
            || (tag === 'SPAN' && /bold|[6-9]00/.test(child.style.fontWeight));

        if (bold && inner.trim() !== '') {
            // Marks go around the words, not the spaces beside them, so
            // "**word** " never becomes "**word **".
            const [, lead, body, trail] = inner.match(/^(\s*)([\s\S]*?)(\s*)$/);
            const [open, close] = child.classList.contains('cp-ok')
                ? ['[ok]', '[/ok]']
                : child.classList.contains('cp-warn') ? ['[warn]', '[/warn]'] : ['**', '**'];
            out += lead + open + body + close + trail;
        } else if (BLOCK_TAGS.has(tag) && out !== '' && !out.endsWith('\n')) {
            out += '\n' + inner;
        } else {
            out += inner;
        }
    });

    return out.replace(/ /g, ' ');
}

/** Top-level block children (and any loose text between them) as strings. */
function blockTexts(el) {
    const parts = [];
    let loose = '';
    const flush = () => {
        if (loose.trim() !== '') parts.push(loose);
        loose = '';
    };

    el.childNodes.forEach((child) => {
        if (child.nodeType === Node.ELEMENT_NODE && BLOCK_TAGS.has(child.tagName)) {
            flush();
            parts.push(inlineText(child));
        } else if (child.nodeType === Node.ELEMENT_NODE && child.tagName === 'BR') {
            loose += '\n';
        } else {
            const wrap = document.createElement('span');
            wrap.appendChild(child.cloneNode(true));
            loose += inlineText(wrap);
        }
    });
    flush();

    return parts.map((p) => p.replace(/[ \t]*\n[ \t]*/g, '\n').trim());
}

const MODES = {
    plain: {
        render: (el, v) => { el.textContent = v ?? ''; },
        read: (el) => el.innerText.replace(/\s*\n\s*/g, ' ').replace(/ /g, ' '),
    },
    rich: {
        render: (el, v) => { el.innerHTML = inlineHtml(v); },
        read: (el) => inlineText(el).replace(/\s*\n\s*/g, ' '),
    },
    paras: {
        render: (el, v) => {
            const paras = String(v ?? '').split(/\n\s*\n/).map((p) => p.trim()).filter((p) => p !== '');
            el.innerHTML = paras.map((p) => `<p>${inlineHtml(p).replace(/\n/g, '<br>')}</p>`).join('');
        },
        read: (el) => blockTexts(el).filter((p) => p !== '').join('\n\n'),
    },
    list: {
        render: (el, v) => {
            const items = Array.isArray(v) && v.length ? v : [''];
            el.innerHTML = items.map((i) => `<li>${inlineHtml(i) || '<br>'}</li>`).join('');
        },
        read: (el) => blockTexts(el).map((i) => i.replace(/\n/g, ' ')).filter((i) => i !== ''),
    },
};

function placeCaretAtEnd(el) {
    el.focus();
    const selection = window.getSelection();
    if (!selection) return;
    const range = document.createRange();
    range.selectNodeContents(el);
    range.collapse(false);
    selection.removeAllRanges();
    selection.addRange(range);
}

function isEmpty(el) {
    return el.textContent.replace(/ /g, ' ').trim() === '';
}

export function registerEditDirective(Alpine) {
    Alpine.directive('edit', (el, { expression, modifiers }, { evaluateLater, effect, cleanup }) => {
        const modeName = ['rich', 'paras', 'list'].find((m) => modifiers.includes(m)) ?? 'plain';
        const mode = MODES[modeName];
        const multiline = modeName === 'paras' || modeName === 'list';
        const getValue = evaluateLater(expression);
        const setValue = evaluateLater(`${expression} = __value`);
        let current;

        el.setAttribute('contenteditable', modeName === 'plain' ? 'plaintext-only' : 'true');
        if (!el.isContentEditable) el.setAttribute('contenteditable', 'true');
        el.classList.add('cp-edit');
        el.spellcheck = true;

        effect(() => getValue((value) => {
            // Touch every nested value so an in-place change (a toolbar
            // action splicing an array) re-runs this effect too.
            JSON.stringify(value ?? null);
            current = value;
            if (document.activeElement !== el) mode.render(el, value);
        }));

        const onInput = () => {
            current = mode.read(el);
            setValue(() => {}, { scope: { __value: current } });
        };

        const onBlur = () => mode.render(el, current);

        const onKeydown = (event) => {
            if (event.key === 'Enter' && !event.shiftKey && !multiline) {
                event.preventDefault();
                // Handled by whatever the element sits in (a new card, a new
                // table row); otherwise the caret moves on, like Tab.
                const next = el.dispatchEvent(new CustomEvent('edit-enter', { bubbles: true, cancelable: true }));
                if (next) window.proposalEditorFocusNext?.(el);
            } else if (event.key === 'Enter' && event.shiftKey && !multiline) {
                event.preventDefault();
            } else if (event.key === 'Backspace' && isEmpty(el)) {
                const handled = !el.dispatchEvent(new CustomEvent('edit-empty', { bubbles: true, cancelable: true }));
                if (handled) event.preventDefault();
            }
        };

        // Pasted text arrives as text: formatting from Word, WhatsApp or a
        // web page would otherwise come along as markup the document cannot
        // store.
        const onPaste = (event) => {
            event.preventDefault();
            let text = event.clipboardData?.getData('text/plain') ?? '';
            if (!multiline) text = text.replace(/\s*\n\s*/g, ' ');
            document.execCommand('insertText', false, text);
        };

        el.addEventListener('input', onInput);
        el.addEventListener('blur', onBlur);
        el.addEventListener('keydown', onKeydown);
        el.addEventListener('paste', onPaste);

        cleanup(() => {
            el.removeEventListener('input', onInput);
            el.removeEventListener('blur', onBlur);
            el.removeEventListener('keydown', onKeydown);
            el.removeEventListener('paste', onPaste);
        });
    });
}

/* ------------------------------------------------------------------ */
/*  Block shapes                                                        */
/* ------------------------------------------------------------------ */

const cell = () => ({ text: '', loop: false });

/** A new block of each type -- enough structure to type straight into. */
export const BLANKS = {
    paragraph: () => ({ type: 'paragraph', text: '', tone: 'default' }),
    subheading: () => ({ type: 'subheading', text: '' }),
    list: () => ({ type: 'list', items: [''], ordered: false, boxed: false }),
    table: () => ({
        type: 'table', header: ['', ''], rows: [['', ''], ['', '']],
        first_col_bold: false, last_col_right: false, last_row_bold: false, first_col_width: 0,
    }),
    callout: () => ({ type: 'callout', text: '', tone: 'brand' }),
    cards: () => ({ type: 'cards', items: [{ label: '', text: '' }, { label: '', text: '' }], columns: 2, variant: 'labelled' }),
    flow: () => ({ type: 'flow', steps: ['', '', ''], tone: 'light', numbered: false, columns: 0 }),
    chips: () => ({ type: 'chips', items: ['', ''] }),
    note: () => ({ type: 'note', tag: 'recommendation', text: '' }),
    legend: () => ({
        type: 'legend',
        title: 'How to read this proposal',
        items: Object.entries(TAG_NAMES).map(([tag, label]) => ({ tag, label, note: '' })),
    }),
    total: () => ({ type: 'total', label: 'Total', value: '' }),
    stack: () => ({ type: 'stack', items: [{ category: '', name: '', logo: '' }, { category: '', name: '', logo: '' }] }),
    architecture: () => ({
        type: 'architecture',
        layers: [{ label: '', style: 'light', columns: 3, items: [0, 1, 2].map(() => ({ text: '', dashed: false })) }],
    }),
    swimlane: () => ({
        type: 'swimlane', lanes: ['Customer', 'Platform', 'Team'],
        rows: [[cell(), cell(), cell()], [cell(), cell(), cell()]], legend: true,
    }),
    chart: () => ({
        type: 'chart', title: '', kind: 'bar', suffix: '', caption: '',
        items: [{ label: '', value: 0 }, { label: '', value: 0 }],
    }),
    // No uploader yet: a picture is placed by path (images/proposals/...).
    image: () => ({ type: 'image', path: '', caption: '', size: 'full' }),
};

/** A blank entry for each block's repeatable list. */
const ITEM_BLANKS = {
    list: () => '',
    chips: () => '',
    flow: () => '',
    cards: () => ({ label: '', text: '' }),
    legend: () => ({ tag: 'requirement', label: '', note: '' }),
    stack: () => ({ category: '', name: '', logo: '' }),
    chart: () => ({ label: '', value: 0 }),
};

const ITEM_LIST = { list: 'items', chips: 'items', flow: 'steps', cards: 'items', legend: 'items', stack: 'items', chart: 'items' };

/** The "Style" menu: the text-shaped blocks, each a type plus one setting. */
export const STYLES = {
    'paragraph:default': 'Normal text',
    'paragraph:muted': 'Muted text',
    'paragraph:fine': 'Small print',
    subheading: 'Subheading',
    'list:bullet': 'Bulleted list',
    'list:numbered': 'Numbered list',
    'list:boxed': 'Boxed list',
    'callout:brand': 'Callout — blue',
    'callout:gray': 'Callout — grey',
    'callout:outline': 'Callout — outlined',
    'note:requirement': 'Tag — Client Requirement',
    'note:recommendation': 'Tag — Recommendation',
    'note:optional': 'Tag — Optional / Future',
};

function styleOf(block) {
    switch (block?.type) {
        case 'paragraph': return 'paragraph:' + block.tone;
        case 'subheading': return 'subheading';
        case 'list': return block.boxed ? 'list:boxed' : (block.ordered ? 'list:numbered' : 'list:bullet');
        case 'callout': return 'callout:' + block.tone;
        case 'note': return 'note:' + block.tag;
        default: return null;
    }
}

function textOf(block) {
    if (block.type === 'list') return block.items.filter((i) => i !== '').join('\n\n');

    return block.text ?? '';
}

/** Editor-only padding: somewhere to type in every repeatable list. */
function prepareBlock(block, uid) {
    const b = JSON.parse(JSON.stringify(block));
    b._uid = uid;

    if (b.type === 'list' && !b.items.length) b.items = [''];
    if (b.type === 'chips' && !b.items.length) b.items = [''];
    if (b.type === 'flow' && !b.steps.length) b.steps = [''];
    if (b.type === 'table') {
        const cols = Math.max(1, b.header.length, ...b.rows.map((r) => r.length));
        if (b.header.length) b.header = [...b.header, ...Array(cols - b.header.length).fill('')];
        b.rows = b.rows.map((r) => [...r, ...Array(cols - r.length).fill('')]);
        if (!b.rows.length) b.rows = [Array(cols).fill('')];
    }

    return b;
}

/** Strip what the editor added, and drop entries left completely empty. */
function cleanBlock(block) {
    const { _uid, ...b } = JSON.parse(JSON.stringify(block));
    const filled = (o) => Object.values(o).some((v) => typeof v === 'string' && v.trim() !== '');

    if (b.type === 'cards' || b.type === 'stack' || b.type === 'legend' || b.type === 'chart') b.items = b.items.filter(filled);
    if (b.type === 'table') b.rows = b.rows.filter((r) => r.some((c) => c.trim() !== ''));
    if (b.type === 'table' && b.header.every((c) => c.trim() === '') && b.header.length) b.header = [];
    if (b.type === 'architecture') {
        b.layers = b.layers
            .map((l) => ({ ...l, items: l.items.filter((i) => i.text.trim() !== '') }))
            .filter((l) => l.items.length || l.label.trim() !== '');
    }
    if (b.type === 'swimlane') b.rows = b.rows.filter((r) => r.some((c) => c.text.trim() !== ''));

    return b;
}

function blockIsEmpty(b) {
    if (['paragraph', 'subheading', 'callout', 'note'].includes(b.type)) return (b.text ?? '').trim() === '';
    if (b.type === 'list' || b.type === 'chips') return b.items.every((i) => i.trim() === '');

    return false;
}

/* ------------------------------------------------------------------ */
/*  The page                                                            */
/* ------------------------------------------------------------------ */

export function proposalEditor(config) {
    let uid = 0;
    const nextUid = () => ++uid;

    const prepareSection = (section) => {
        const s = JSON.parse(JSON.stringify(section));
        if (s.type === 'section') {
            s.data.blocks = s.data.blocks.map((b) => prepareBlock(b, nextUid()));
            if (!s.data.blocks.length) s.data.blocks.push(prepareBlock(BLANKS.paragraph(), nextUid()));
        }

        return s;
    };

    const randomKey = () => 's-' + Math.random().toString(36).slice(2, 10);

    return {
        sections: config.sections.map(prepareSection),
        meta: { title: config.title ?? '', client_id: config.clientId ?? '', valid_until: config.validUntil ?? '' },
        clients: config.clients,
        studioLogos: config.studioLogos,
        techLogos: config.techLogos,
        tags: TAG_NAMES,
        styles: STYLES,
        blockTypes: config.blockTypes,

        // Where the caret is: section, block, and inside the block an item
        // (i), a sub-item (j), or a table/swimlane cell (r, c).
        active: { s: null, b: null, i: null, j: null, r: null, c: null },

        logoFile: null,
        logoPreview: null,
        removeLogo: false,

        dirty: false,
        saving: false,
        savedAt: null,
        error: null,
        history: [],
        insertOpen: false,

        init() {
            document.execCommand('defaultParagraphSeparator', false, 'p');
            window.proposalEditorFocusNext = (el) => this.focusNext(el);

            this.$el.addEventListener('input', () => { this.dirty = true; });

            window.addEventListener('beforeunload', (event) => {
                if (this.dirty && !this.saving) event.preventDefault();
            });

            window.addEventListener('keydown', (event) => {
                if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
                    event.preventDefault();
                    this.save();
                }
            });

            // Put the caret somewhere useful on a brand-new proposal.
            if (!config.exists) {
                this.$nextTick(() => this.$el.querySelector('.cp-cover [contenteditable]')?.focus());
            }
        },

        /* ---------------------------------------------- where the caret is */

        setActive(s, b = null, extra = {}) {
            this.active = { s, b, i: null, j: null, r: null, c: null, ...extra };
        },

        get section() {
            return this.sections[this.active.s] ?? null;
        },

        get block() {
            return this.section?.type === 'section' ? (this.section.data.blocks[this.active.b] ?? null) : null;
        },

        isActive(s, b) {
            return this.active.s === s && this.active.b === b;
        },

        get currentStyle() {
            return styleOf(this.block);
        },

        get cover() {
            return this.sections.find((s) => s.type === 'cover')?.data ?? null;
        },

        /* ------------------------------------------------------ the sheets */

        get sheets() {
            const sheets = [];
            this.sections.forEach((section, s) => {
                if (section.type === 'cover') {
                    sheets.push({ key: section.key, cover: true, items: [s] });

                    return;
                }
                const last = sheets[sheets.length - 1];
                if (!last || last.cover || section.data.new_page) {
                    sheets.push({ key: section.key, cover: false, items: [] });
                }
                sheets[sheets.length - 1].items.push(s);
            });

            return sheets.map((sheet, index) => ({ ...sheet, number: index + 1 }));
        },

        pageLabel(number) {
            const pad = (n) => String(n).padStart(2, '0');

            return pad(number) + ' / ' + pad(this.sheets.length);
        },

        get clientName() {
            return this.clients.find((c) => String(c.id) === String(this.meta.client_id))?.name ?? '';
        },

        get footer() {
            const by = this.cover?.prepared_by || 'Chakra Productions';
            const forName = this.cover?.client_name || this.clientName || this.meta.title;

            return by + ' · Proposal for ' + forName;
        },

        studioLogo() {
            const brand = this.cover?.brand ?? 'app_studio';

            return this.studioLogos[brand] ?? this.studioLogos.production ?? null;
        },

        get clientLogo() {
            if (this.logoPreview) return this.logoPreview;
            const own = this.removeLogo ? null : this.cover?.client_logo;
            if (own) return config.assetBase + own;

            return this.clients.find((c) => String(c.id) === String(this.meta.client_id))?.logo ?? null;
        },

        pickLogo(event) {
            const file = event.target.files?.[0];
            if (!file) return;
            this.logoFile = file;
            this.removeLogo = false;
            this.logoPreview = URL.createObjectURL(file);
            this.dirty = true;
        },

        dropLogo() {
            this.logoFile = null;
            this.logoPreview = null;
            this.removeLogo = true;
            if (this.$refs.logo) this.$refs.logo.value = '';
            this.dirty = true;
        },

        /* ------------------------------------------------------------ undo */

        // Typing has the browser's own Ctrl+Z; this is for the structural
        // changes (a deleted block, a moved section) the browser cannot see.
        remember() {
            this.history.push(JSON.stringify(this.sections));
            if (this.history.length > 50) this.history.shift();
            this.dirty = true;
        },

        undo() {
            const last = this.history.pop();
            if (!last) return;
            this.sections = JSON.parse(last);
            this.dirty = true;
        },

        /* ---------------------------------------------------------- focus */

        focusIn(selector, atEnd = true) {
            this.$nextTick(() => {
                const target = this.$el.querySelector(selector);
                const el = target?.isContentEditable ? target : target?.querySelector('[contenteditable]');
                if (!el) return;
                atEnd ? placeCaretAtEnd(el) : el.focus();
            });
        },

        focusBlock(block, item = null) {
            const base = `[data-uid="${block._uid}"]`;
            this.focusIn(item === null ? base : `${base} [data-i="${item}"]`);
        },

        focusNext(el) {
            const all = [...this.$el.querySelectorAll('.cp-doc [contenteditable]')];
            const next = all[all.indexOf(el) + 1];
            if (next) placeCaretAtEnd(next);
        },

        /* --------------------------------------------------------- inline */

        mark(kind) {
            if (kind === 'bold') {
                document.execCommand('bold');

                return;
            }

            const selection = window.getSelection();
            if (!selection || selection.rangeCount === 0 || selection.isCollapsed) return;
            const range = selection.getRangeAt(0);
            const host = range.commonAncestorContainer.parentElement?.closest('[contenteditable="true"]');
            if (!host) return;

            const text = range.toString();
            range.deleteContents();

            if (kind === 'clear') {
                range.insertNode(document.createTextNode(text));
            } else {
                const strong = document.createElement('strong');
                strong.className = kind === 'ok' ? 'cp-ok' : 'cp-warn';
                strong.textContent = text;
                range.insertNode(strong);
            }

            host.normalize();
            host.dispatchEvent(new Event('input', { bubbles: true }));
        },

        /* --------------------------------------------------------- blocks */

        insertBlock(type) {
            this.insertOpen = false;
            this.remember();

            let s = this.active.s;
            if (this.sections[s]?.type !== 'section') {
                s = this.sections.findIndex((x) => x.type === 'section');
                if (s === -1) {
                    this.addSection(false);
                    s = this.sections.length - 1;
                }
            }

            const blocks = this.sections[s].data.blocks;
            const at = this.active.s === s && this.active.b !== null ? this.active.b + 1 : blocks.length;
            const block = prepareBlock(BLANKS[type](), nextUid());

            // Replacing an untouched empty paragraph reads as "turn this line
            // into a table", the way Word's insert behaves on a blank line.
            if (this.active.s === s && this.active.b !== null && blocks[this.active.b]?.type === 'paragraph'
                && blockIsEmpty(blocks[this.active.b])) {
                blocks.splice(this.active.b, 1, block);
                this.setActive(s, this.active.b);
            } else {
                blocks.splice(at, 0, block);
                this.setActive(s, at);
            }

            this.focusBlock(block);
        },

        appendParagraph(s) {
            const blocks = this.sections[s].data.blocks;
            const last = blocks[blocks.length - 1];

            if (last && last.type === 'paragraph' && blockIsEmpty(last)) {
                this.focusBlock(last);

                return;
            }

            const block = prepareBlock(BLANKS.paragraph(), nextUid());
            blocks.push(block);
            this.setActive(s, blocks.length - 1);
            this.dirty = true;
            this.focusBlock(block);
        },

        setStyle(value) {
            const block = this.block;
            if (!block) return;
            this.remember();

            const [type, option] = value.split(':');
            const text = textOf(block);
            const blocks = this.section.data.blocks;
            let next;

            if (type === 'list') {
                const items = text.split(/\n\s*\n|\n/).map((i) => i.trim()).filter((i) => i !== '');
                next = { type: 'list', items: items.length ? items : [''], ordered: option === 'numbered', boxed: option === 'boxed' };
            } else if (type === 'paragraph') {
                next = { type: 'paragraph', text, tone: option };
            } else if (type === 'subheading') {
                next = { type: 'subheading', text: text.replace(/\s*\n\s*/g, ' ') };
            } else if (type === 'callout') {
                next = { type: 'callout', text: text.replace(/\s*\n\s*/g, ' '), tone: option };
            } else {
                next = { type: 'note', text: text.replace(/\s*\n\s*/g, ' '), tag: option };
            }

            next._uid = block._uid;
            blocks.splice(this.active.b, 1, next);
            this.focusBlock(next);
        },

        moveBlock(delta) {
            const blocks = this.section?.data.blocks;
            const b = this.active.b;
            if (!blocks || b === null || b + delta < 0 || b + delta >= blocks.length) return;
            this.remember();
            const [block] = blocks.splice(b, 1);
            blocks.splice(b + delta, 0, block);
            this.active.b = b + delta;
            this.focusBlock(block);
        },

        duplicateBlock() {
            const block = this.block;
            if (!block) return;
            this.remember();
            const copy = prepareBlock(cleanBlock(block), nextUid());
            this.section.data.blocks.splice(this.active.b + 1, 0, copy);
            this.active.b += 1;
            this.focusBlock(copy);
        },

        deleteBlock() {
            const blocks = this.section?.data.blocks;
            if (!blocks || this.active.b === null) return;
            this.remember();
            blocks.splice(this.active.b, 1);
            if (!blocks.length) blocks.push(prepareBlock(BLANKS.paragraph(), nextUid()));
            const b = Math.min(this.active.b, blocks.length - 1);
            this.setActive(this.active.s, b);
            this.focusBlock(blocks[b]);
        },

        /* -------------------------------------------------- repeatable items */

        addItem(s, b, i = null) {
            const block = this.sections[s].data.blocks[b];
            const key = ITEM_LIST[block.type];
            if (!key) return;
            const at = i === null ? block[key].length : i + 1;
            block[key].splice(at, 0, ITEM_BLANKS[block.type]());
            this.setActive(s, b, { i: at });
            this.dirty = true;
            this.focusBlock(block, at);
        },

        removeItem(s, b, i) {
            const block = this.sections[s].data.blocks[b];
            const key = ITEM_LIST[block.type];
            if (!key || i === null || block[key].length <= 1) return false;
            block[key].splice(i, 1);
            const prev = Math.max(0, i - 1);
            this.setActive(s, b, { i: prev });
            this.dirty = true;
            this.focusBlock(block, prev);

            return true;
        },

        // Backspace in an emptied card / step / pill removes it -- only when
        // every field in it is empty, so a label cannot take its text along.
        removeIfEmpty(s, b, i) {
            const block = this.sections[s].data.blocks[b];
            const item = block[ITEM_LIST[block.type]]?.[i];
            const empty = typeof item === 'string'
                ? item.trim() === ''
                : Object.entries(item ?? {}).every(([k, v]) => ['tag', 'logo'].includes(k) || typeof v !== 'string' || v.trim() === '');
            if (empty) this.removeItem(s, b, i);
        },

        /* ----------------------------------------------------------- tables */

        columns(block) {
            return Math.max(1, block.header.length, ...block.rows.map((r) => r.length));
        },

        addRow(below = true) {
            const block = this.block;
            this.remember();
            const at = this.active.r === null || this.active.r < 0 ? (below ? block.rows.length : 0) : this.active.r + (below ? 1 : 0);
            block.rows.splice(at, 0, Array(this.columns(block)).fill(''));
            this.active.r = at;
            this.focusIn(`[data-uid="${block._uid}"] [data-r="${at}"][data-c="0"]`);
        },

        deleteRow() {
            const block = this.block;
            if (this.active.r === null || this.active.r < 0 || block.rows.length <= 1) return;
            this.remember();
            block.rows.splice(this.active.r, 1);
            this.active.r = Math.min(this.active.r, block.rows.length - 1);
        },

        addColumn(right = true) {
            const block = this.block;
            this.remember();
            const at = this.active.c === null ? this.columns(block) : this.active.c + (right ? 1 : 0);
            if (block.header.length) block.header.splice(at, 0, '');
            block.rows.forEach((r) => r.splice(at, 0, ''));
            this.active.c = at;
        },

        deleteColumn() {
            const block = this.block;
            if (this.active.c === null || this.columns(block) <= 1) return;
            this.remember();
            if (block.header.length) block.header.splice(this.active.c, 1);
            block.rows.forEach((r) => r.splice(this.active.c, 1));
            this.active.c = Math.min(this.active.c, this.columns(block) - 1);
        },

        toggleHeader() {
            const block = this.block;
            this.remember();
            block.header = block.header.length ? [] : Array(this.columns(block)).fill('');
        },

        // Tab out of the last cell adds a row, as in Word.
        tableTab(event, s, b, r, c) {
            const block = this.sections[s].data.blocks[b];
            if (event.shiftKey || r !== block.rows.length - 1 || c !== this.columns(block) - 1) return;
            event.preventDefault();
            this.setActive(s, b, { r, c });
            this.addRow(true);
        },

        /* ----------------------------------------------------- architecture */

        addLayer() {
            const block = this.block;
            this.remember();
            const at = this.active.i === null ? block.layers.length : this.active.i + 1;
            block.layers.splice(at, 0, { label: '', style: 'outline', columns: 3, items: [0, 1, 2].map(() => ({ text: '', dashed: false })) });
            this.active.i = at;
            this.focusIn(`[data-uid="${block._uid}"] [data-i="${at}"]`);
        },

        deleteLayer() {
            const block = this.block;
            if (this.active.i === null || block.layers.length <= 1) return;
            this.remember();
            block.layers.splice(this.active.i, 1);
            this.active.i = null;
        },

        addBox() {
            const layer = this.block?.layers[this.active.i ?? 0];
            if (!layer) return;
            const at = this.active.j === null ? layer.items.length : this.active.j + 1;
            layer.items.splice(at, 0, { text: '', dashed: false });
            this.active.j = at;
            this.dirty = true;
            this.focusIn(`[data-uid="${this.block._uid}"] [data-i="${this.active.i ?? 0}"][data-j="${at}"]`);
        },

        deleteBox() {
            const layer = this.block?.layers[this.active.i ?? 0];
            if (!layer || this.active.j === null || layer.items.length <= 1) return;
            this.remember();
            layer.items.splice(this.active.j, 1);
            this.active.j = null;
        },

        get activeBox() {
            return this.block?.type === 'architecture' && this.active.j !== null
                ? this.block.layers[this.active.i]?.items[this.active.j] ?? null
                : null;
        },

        get activeLayer() {
            return this.block?.type === 'architecture' ? this.block.layers[this.active.i ?? 0] ?? null : null;
        },

        /* --------------------------------------------------------- swimlane */

        addSwimRow() {
            const block = this.block;
            const at = this.active.r === null || this.active.r < 0 ? block.rows.length : this.active.r + 1;
            block.rows.splice(at, 0, [cell(), cell(), cell()]);
            this.active.r = at;
            this.dirty = true;
            this.focusIn(`[data-uid="${block._uid}"] [data-r="${at}"][data-c="0"]`);
        },

        deleteSwimRow() {
            const block = this.block;
            if (this.active.r === null || this.active.r < 0 || block.rows.length <= 1) return;
            this.remember();
            block.rows.splice(this.active.r, 1);
            this.active.r = null;
        },

        get activeSwimCell() {
            return this.block?.type === 'swimlane' && this.active.r !== null && this.active.r >= 0
                ? this.block.rows[this.active.r]?.[this.active.c] ?? null
                : null;
        },

        swimClass(cellData, c) {
            if (cellData.text === '') return 'cp-e-swim-empty';

            return 'cp-swim__cell ' + (cellData.loop ? 'cp-swim__cell--loop' : 'cp-swim__cell--' + c);
        },

        /* --------------------------------------------------------- sections */

        nextNumber(before) {
            const numbered = this.sections.slice(0, before).filter((x) => x.type === 'section' && /^\d+$/.test(x.data.number));
            const last = numbered[numbered.length - 1];

            return last ? String(parseInt(last.data.number, 10) + 1).padStart(2, '0') : '01';
        },

        addSection(newPage = false) {
            this.remember();
            const at = this.active.s === null ? this.sections.length : this.active.s + 1;
            const section = prepareSection({
                key: randomKey(),
                type: 'section',
                data: { number: this.nextNumber(at), title: '', new_page: newPage, blocks: [] },
            });
            this.sections.splice(at, 0, section);
            this.setActive(at, null);
            this.focusIn(`[data-section="${section.key}"] h2 [contenteditable]:last-child`);
        },

        addCover() {
            this.remember();
            this.sections.unshift({
                key: 'cover', type: 'cover',
                data: {
                    eyebrow: 'Project Proposal', brand: 'app_studio', prepared_for_label: 'Prepared for', client_logo: null,
                    title: '', subtitle: '', prepared_by: 'Chakra App Studio', client_name: '', date_label: config.today,
                },
            });
            this.setActive(0, null);
        },

        togglePageBreak() {
            if (this.section?.type !== 'section') return;
            this.remember();
            this.section.data.new_page = !this.section.data.new_page;
        },

        moveSection(delta) {
            const s = this.active.s;
            if (s === null || s + delta < 0 || s + delta >= this.sections.length) return;
            this.remember();
            const [section] = this.sections.splice(s, 1);
            this.sections.splice(s + delta, 0, section);
            this.setActive(s + delta, null);
        },

        deleteSection() {
            const section = this.section;
            if (!section) return;
            const name = section.type === 'cover' ? 'the cover' : '"' + ([section.data.number, section.data.title].filter(Boolean).join(' ') || 'this section') + '"';
            if (!confirm('Delete ' + name + '? Client comments on it are kept, shown as general feedback.')) return;
            this.remember();
            this.sections.splice(this.active.s, 1);
            this.setActive(null);
        },

        renumber() {
            this.remember();
            let n = 0;
            this.sections.forEach((section) => {
                if (section.type === 'section' && /^\d*$/.test(section.data.number) && (section.data.number !== '' || section.data.title !== '')) {
                    section.data.number = String(++n).padStart(2, '0');
                }
            });
        },

        /* ------------------------------------------------------------- save */

        serialize() {
            return this.sections.map((section) => {
                if (section.type !== 'section') return section;

                return {
                    ...section,
                    data: {
                        ...section.data,
                        blocks: section.data.blocks
                            .filter((b) => !(b.type === 'paragraph' && blockIsEmpty(b)))
                            .map(cleanBlock),
                    },
                };
            });
        },

        async save() {
            if (this.saving) return;

            // The field being typed in writes on input, but make sure a blur
            // re-render is not pending with stale text.
            if (document.activeElement?.isContentEditable) {
                document.activeElement.dispatchEvent(new Event('input', { bubbles: true }));
            }

            this.saving = true;
            this.error = null;

            const body = new FormData();
            body.append('_token', config.csrf);
            if (config.method !== 'POST') body.append('_method', config.method);
            body.append('title', this.meta.title);
            body.append('client_id', this.meta.client_id ?? '');
            body.append('valid_until', this.meta.valid_until ?? '');
            body.append('sections_json', JSON.stringify(this.serialize()));
            if (this.logoFile) body.append('cover_logo', this.logoFile);
            if (this.removeLogo) body.append('remove_cover_logo', '1');

            try {
                const response = await fetch(config.saveUrl, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body,
                });
                const data = await response.json().catch(() => ({}));

                if (!response.ok) {
                    const first = data.errors ? Object.values(data.errors)[0]?.[0] : null;
                    this.error = first || data.message || 'Could not save. Check your connection and try again.';

                    return;
                }

                this.dirty = false;
                this.savedAt = new Date();

                if (this.cover && 'client_logo' in data) this.cover.client_logo = data.client_logo;
                this.logoFile = null;
                this.removeLogo = false;

                // A new proposal becomes an existing one: carry on editing it
                // at its own address, so the next save updates rather than
                // creating a second copy.
                if (data.edit_url && config.method === 'POST') {
                    window.location.replace(data.edit_url);
                }
            } catch {
                this.error = 'Could not save. Check your connection and try again.';
            } finally {
                this.saving = false;
            }
        },

        get saveLabel() {
            if (this.saving) return 'Saving…';
            if (this.dirty) return 'Unsaved changes';
            if (this.savedAt) return 'Saved ' + this.savedAt.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });

            return config.exists ? 'All changes saved' : 'Not saved yet';
        },
    };
}
