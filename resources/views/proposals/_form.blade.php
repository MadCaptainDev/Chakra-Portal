{{--
    The section editor. The whole document is Alpine state and posts as one
    JSON string (sections_json) -- see ProposalRequest for why, and
    App\Support\ProposalBlocks for the text formats each block is edited in.
--}}
@php
    use App\Support\ProposalBlocks;

    $state = old('sections_json')
        ? json_decode(old('sections_json'), true)
        : ProposalBlocks::toForm($proposal->normalizedSections());

    // A fresh, empty block of every type, in its editor shape -- what "Add
    // block" pushes.
    $blockDefaults = collect(ProposalBlocks::BLOCK_TYPES)
        ->mapWithKeys(fn ($label, $type) => [$type => ProposalBlocks::blockToForm(ProposalBlocks::normalizeBlock(['type' => $type]))])
        ->all();

    $currentLogo = $proposal->exists ? $proposal->clientLogoPath() : null;
    $field = 'bg-white/5 border-white/15 text-white placeholder-brand-100/40 focus:bg-white/[0.07] focus:border-brand-400 focus:ring-brand-400 rounded-md min-h-[44px] w-full text-sm';
    $mono = $field.' font-mono text-xs leading-relaxed';
    $label = 'block font-medium text-xs text-brand-100/70 mb-1';
    $check = 'rounded border-white/20 bg-white/5 text-brand-400 focus:ring-brand-400';
    $iconBtn = 'inline-flex items-center justify-center min-h-[36px] min-w-[36px] rounded-md text-brand-100/60 hover:bg-white/10 hover:text-white disabled:opacity-30';
@endphp

@csrf

<div x-data="proposalEditor({{ Illuminate\Support\Js::from($state) }}, {{ Illuminate\Support\Js::from($blockDefaults) }})"
     x-init="$el.closest('form').addEventListener('submit', () => serialize())"
     class="space-y-6">
    <input type="hidden" name="sections_json" x-ref="json">

    <x-card class="p-4 sm:p-6 space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="sm:col-span-2">
                <x-input-label for="title" value="Proposal name (internal)" />
                <x-text-input id="title" name="title" type="text" class="mt-1"
                              value="{{ old('title', $proposal->title) }}" required maxlength="255"
                              placeholder="e.g. Print Bazzar — E-commerce platform" />
                <x-input-error :messages="$errors->get('title')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="client_id" value="Client (optional)" />
                <x-select id="client_id" name="client_id" class="mt-1 w-full">
                    <option value="">Not linked to a client</option>
                    @foreach ($clients as $client)
                        <option value="{{ $client->id }}" @selected(old('client_id', $proposal->client_id) == $client->id)>{{ $client->name }}</option>
                    @endforeach
                </x-select>
                <x-input-error :messages="$errors->get('client_id')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="valid_until" value="Valid until (optional)" />
                <x-text-input id="valid_until" name="valid_until" type="date" class="mt-1"
                              value="{{ old('valid_until', $proposal->valid_until?->format('Y-m-d')) }}" />
                <x-input-error :messages="$errors->get('valid_until')" class="mt-2" />
            </div>
        </div>
        <x-input-error :messages="$errors->get('sections_json')" />
    </x-card>

    {{-- Sections --}}
    <template x-for="(section, s) in sections" :key="section.key">
        <x-card class="p-4 sm:p-6">
            <div class="flex items-start justify-between gap-3">
                <button type="button" @click="section._open = !section._open" class="flex-1 text-left min-h-[44px]">
                    <p class="text-[10px] font-bold uppercase tracking-widest text-brand-300"
                       x-text="section.type === 'cover' ? 'Cover' : (section.new_page ? 'Section · new page' : 'Section')"></p>
                    <p class="font-semibold text-white"
                       x-text="section.type === 'cover' ? (section.title || 'Cover') : ((section.number ? section.number + ' ' : '') + (section.title || 'Untitled section'))"></p>
                    <p class="text-xs text-brand-100/50" x-show="section.type === 'section'"
                       x-text="(section.blocks || []).length + ((section.blocks || []).length === 1 ? ' block' : ' blocks')"></p>
                </button>
                <div class="flex items-center gap-1 shrink-0">
                    <button type="button" class="{{ $iconBtn }}" @click="move(sections, s, -1)" :disabled="s === 0" title="Move up" aria-label="Move section up">&uarr;</button>
                    <button type="button" class="{{ $iconBtn }}" @click="move(sections, s, 1)" :disabled="s === sections.length - 1" title="Move down" aria-label="Move section down">&darr;</button>
                    <button type="button" class="{{ $iconBtn }} hover:text-red-300" @click="removeSection(s)" title="Remove section" aria-label="Remove section">&times;</button>
                </div>
            </div>

            <div x-show="section._open" x-cloak class="mt-4 space-y-4">
                {{-- Cover --}}
                <template x-if="section.type === 'cover'">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div><label class="{{ $label }}">Eyebrow (top right)</label><input type="text" class="{{ $field }}" x-model="section.eyebrow"></div>
                        <div>
                            <label class="{{ $label }}">Studio logo</label>
                            <select class="{{ $field }}" x-model="section.brand">
                                <option value="app_studio">Chakra App Studio</option>
                                <option value="production">Chakra Productions</option>
                            </select>
                        </div>
                        <div><label class="{{ $label }}">"Prepared for" label</label><input type="text" class="{{ $field }}" x-model="section.prepared_for_label"></div>
                        <div><label class="{{ $label }}">Title (the client's name, large)</label><input type="text" class="{{ $field }}" x-model="section.title"></div>
                        <div class="sm:col-span-2"><label class="{{ $label }}">Subtitle</label><input type="text" class="{{ $field }}" x-model="section.subtitle"></div>
                        <div><label class="{{ $label }}">Prepared by</label><input type="text" class="{{ $field }}" x-model="section.prepared_by"></div>
                        <div><label class="{{ $label }}">Client name (footer and cover)</label><input type="text" class="{{ $field }}" x-model="section.client_name"></div>
                        <div><label class="{{ $label }}">Date</label><input type="text" class="{{ $field }}" x-model="section.date_label" placeholder="September 2026"></div>
                        <div>
                            <label class="{{ $label }}" for="cover_logo">Client logo on the cover</label>
                            <input type="file" id="cover_logo" name="cover_logo" accept="image/png,image/jpeg,image/svg+xml,image/webp"
                                   class="block w-full text-sm text-brand-100/70 file:mr-3 file:min-h-[40px] file:rounded-md file:border-0 file:bg-white/10 file:px-3 file:text-white">
                            @if ($currentLogo)
                                <div class="mt-2 flex items-center gap-3">
                                    <img src="{{ asset($currentLogo) }}" alt="" class="h-8 w-auto rounded bg-brand-900 p-1">
                                    <label class="inline-flex items-center gap-2 text-xs text-brand-100/70">
                                        <input type="checkbox" name="remove_cover_logo" value="1" class="{{ $check }}"> Remove
                                    </label>
                                </div>
                            @else
                                <p class="mt-1 text-xs text-brand-100/40">Falls back to the linked client's own logo.</p>
                            @endif
                            <x-input-error :messages="$errors->get('cover_logo')" class="mt-2" />
                        </div>
                    </div>
                </template>

                {{-- Numbered section --}}
                <template x-if="section.type === 'section'">
                    <div class="space-y-4">
                        <div class="grid grid-cols-[5rem_1fr] gap-3">
                            <div><label class="{{ $label }}">Number</label><input type="text" class="{{ $field }}" x-model="section.number" maxlength="8" placeholder="01"></div>
                            <div><label class="{{ $label }}">Heading</label><input type="text" class="{{ $field }}" x-model="section.title"></div>
                        </div>
                        <label class="inline-flex items-center gap-2 text-sm text-brand-100/80 min-h-[44px]">
                            <input type="checkbox" class="{{ $check }}" x-model="section.new_page">
                            Start a new A4 page with this section
                        </label>

                        <template x-for="(block, b) in section.blocks" :key="block._uid">
                            <div class="rounded-lg bg-white/[0.03] ring-1 ring-white/10 p-3 sm:p-4">
                                <div class="flex items-center justify-between gap-2 mb-3">
                                    <p class="text-xs font-semibold uppercase tracking-wider text-brand-200" x-text="blockLabels[block.type] || block.type"></p>
                                    <div class="flex items-center gap-1">
                                        <button type="button" class="{{ $iconBtn }}" @click="move(section.blocks, b, -1)" :disabled="b === 0" aria-label="Move block up">&uarr;</button>
                                        <button type="button" class="{{ $iconBtn }}" @click="move(section.blocks, b, 1)" :disabled="b === section.blocks.length - 1" aria-label="Move block down">&darr;</button>
                                        <button type="button" class="{{ $iconBtn }} hover:text-red-300" @click="section.blocks.splice(b, 1)" aria-label="Remove block">&times;</button>
                                    </div>
                                </div>

                                <template x-if="block.type === 'paragraph'">
                                    <div class="space-y-2">
                                        <textarea class="{{ $field }}" rows="4" x-model="block.text" placeholder="Text. A blank line starts a new paragraph. **bold**, [ok]green[/ok], [warn]amber[/warn]."></textarea>
                                        <select class="{{ $field }} sm:w-56" x-model="block.tone">
                                            <option value="default">Normal</option>
                                            <option value="muted">Muted</option>
                                            <option value="fine">Small print</option>
                                        </select>
                                    </div>
                                </template>

                                <template x-if="block.type === 'subheading'">
                                    <input type="text" class="{{ $field }}" x-model="block.text">
                                </template>

                                <template x-if="block.type === 'list'">
                                    <div class="space-y-2">
                                        <textarea class="{{ $field }}" rows="5" x-model="block.items" placeholder="One item per line"></textarea>
                                        <div class="flex flex-wrap gap-4 text-sm text-brand-100/80">
                                            <label class="inline-flex items-center gap-2"><input type="checkbox" class="{{ $check }}" x-model="block.ordered"> Numbered</label>
                                            <label class="inline-flex items-center gap-2"><input type="checkbox" class="{{ $check }}" x-model="block.boxed"> In a box</label>
                                        </div>
                                    </div>
                                </template>

                                <template x-if="block.type === 'table'">
                                    <div class="space-y-2">
                                        <div><label class="{{ $label }}">Header row (optional) — cells split by |</label><input type="text" class="{{ $mono }}" x-model="block.header" placeholder="Module | Scope"></div>
                                        <div><label class="{{ $label }}">Rows — one per line, cells split by |</label><textarea class="{{ $mono }}" rows="6" x-model="block.rows"></textarea></div>
                                        <div class="flex flex-wrap items-center gap-4 text-sm text-brand-100/80">
                                            <label class="inline-flex items-center gap-2"><input type="checkbox" class="{{ $check }}" x-model="block.first_col_bold"> Bold first column</label>
                                            <label class="inline-flex items-center gap-2"><input type="checkbox" class="{{ $check }}" x-model="block.last_col_right"> Right-align last column</label>
                                            <label class="inline-flex items-center gap-2"><input type="checkbox" class="{{ $check }}" x-model="block.last_row_bold"> Bold last row (subtotal)</label>
                                            <label class="inline-flex items-center gap-2">First column width % <input type="number" min="0" max="60" class="{{ $field }} !w-20" x-model="block.first_col_width"></label>
                                        </div>
                                    </div>
                                </template>

                                <template x-if="block.type === 'callout'">
                                    <div class="space-y-2">
                                        <textarea class="{{ $field }}" rows="2" x-model="block.text"></textarea>
                                        <select class="{{ $field }} sm:w-56" x-model="block.tone">
                                            <option value="brand">Tinted (blue)</option>
                                            <option value="gray">Grey</option>
                                            <option value="outline">Outlined</option>
                                        </select>
                                    </div>
                                </template>

                                <template x-if="block.type === 'cards'">
                                    <div class="space-y-2">
                                        <div><label class="{{ $label }}">One card per line — "Label | Text" (or just text)</label><textarea class="{{ $mono }}" rows="4" x-model="block.items"></textarea></div>
                                        <div class="grid grid-cols-2 gap-2 sm:w-96">
                                            <select class="{{ $field }}" x-model="block.variant">
                                                <option value="labelled">Label above text</option>
                                                <option value="plain">Plain boxes</option>
                                                <option value="stat">Big figure + text</option>
                                            </select>
                                            <select class="{{ $field }}" x-model.number="block.columns">
                                                <option value="1">1 column</option><option value="2">2 columns</option><option value="3">3 columns</option><option value="4">4 columns</option>
                                            </select>
                                        </div>
                                    </div>
                                </template>

                                <template x-if="block.type === 'flow'">
                                    <div class="space-y-2">
                                        <div><label class="{{ $label }}">One step per line</label><textarea class="{{ $field }}" rows="4" x-model="block.steps"></textarea></div>
                                        <div class="flex flex-wrap items-center gap-3 text-sm text-brand-100/80">
                                            <select class="{{ $field }} !w-auto" x-model="block.tone">
                                                <option value="light">Light chips</option>
                                                <option value="dark">Dark chips</option>
                                                <option value="line">One line with arrows</option>
                                            </select>
                                            <label class="inline-flex items-center gap-2"><input type="checkbox" class="{{ $check }}" x-model="block.numbered"> Numbered</label>
                                            <label class="inline-flex items-center gap-2">Per row <input type="number" min="0" max="10" class="{{ $field }} !w-20" x-model="block.columns" placeholder="all"></label>
                                        </div>
                                    </div>
                                </template>

                                <template x-if="block.type === 'chips'">
                                    <textarea class="{{ $field }}" rows="3" x-model="block.items" placeholder="One pill per line"></textarea>
                                </template>

                                <template x-if="block.type === 'note'">
                                    <div class="space-y-2">
                                        <select class="{{ $field }} sm:w-64" x-model="block.tag">
                                            @foreach (ProposalBlocks::TAGS as $tag => $tagLabel)
                                                <option value="{{ $tag }}">{{ $tagLabel }}</option>
                                            @endforeach
                                        </select>
                                        <textarea class="{{ $field }}" rows="2" x-model="block.text"></textarea>
                                    </div>
                                </template>

                                <template x-if="block.type === 'legend'">
                                    <div class="space-y-2">
                                        <input type="text" class="{{ $field }}" x-model="block.title">
                                        <div><label class="{{ $label }}">One per line — "tag | Label | note". Tags: requirement, recommendation, optional</label><textarea class="{{ $mono }}" rows="3" x-model="block.items"></textarea></div>
                                    </div>
                                </template>

                                <template x-if="block.type === 'total'">
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                        <input type="text" class="{{ $field }}" x-model="block.label" placeholder="Total Development Investment">
                                        <input type="text" class="{{ $field }}" x-model="block.value" placeholder="₹X,XX,XXX">
                                    </div>
                                </template>

                                <template x-if="block.type === 'stack'">
                                    <div><label class="{{ $label }}">One per line — "Category | Name | logo". Logos: {{ implode(', ', ProposalBlocks::TECH_LOGOS) }} (blank = placeholder)</label><textarea class="{{ $mono }}" rows="6" x-model="block.items"></textarea></div>
                                </template>

                                <template x-if="block.type === 'architecture'">
                                    <div><label class="{{ $label }}">"# Layer label | light, dark or outline | columns" starts a layer; each line after is a box. Start a box with ~ for dashed (future).</label><textarea class="{{ $mono }}" rows="10" x-model="block.layers"></textarea></div>
                                </template>

                                <template x-if="block.type === 'swimlane'">
                                    <div class="space-y-2">
                                        <div><label class="{{ $label }}">Lanes — three names split by |</label><input type="text" class="{{ $mono }}" x-model="block.lanes" placeholder="Customer | Platform | Team"></div>
                                        <div><label class="{{ $label }}">One step per line, three cells split by |. Leave a cell empty for the connector line; start with ~ for a review loop.</label><textarea class="{{ $mono }}" rows="10" x-model="block.rows"></textarea></div>
                                        <label class="inline-flex items-center gap-2 text-sm text-brand-100/80"><input type="checkbox" class="{{ $check }}" x-model="block.legend"> Show the colour key</label>
                                    </div>
                                </template>
                            </div>
                        </template>

                        <div class="flex flex-wrap items-center gap-2" x-data="{ type: 'paragraph' }">
                            <select class="{{ $field }} !w-auto" x-model="type" aria-label="Block type">
                                @foreach (ProposalBlocks::BLOCK_TYPES as $type => $typeLabel)
                                    <option value="{{ $type }}">{{ $typeLabel }}</option>
                                @endforeach
                            </select>
                            <x-btn type="button" variant="secondary" size="sm" icon="plus" @click="addBlock(section, type)">Add block</x-btn>
                        </div>
                    </div>
                </template>
            </div>
        </x-card>
    </template>

    <div class="flex flex-wrap gap-2">
        <x-btn type="button" variant="secondary" icon="plus" @click="addSection()">Add section</x-btn>
        <x-btn type="button" variant="ghost" x-show="!sections.some(s => s.type === 'cover')" @click="addCover()">Add cover</x-btn>
    </div>

    <div class="sticky bottom-0 -mx-4 sm:mx-0 px-4 py-3 bg-brand-900/95 backdrop-blur border-t border-white/10 flex items-center justify-end gap-3 z-10">
        <x-btn variant="ghost" :href="$proposal->exists ? route('proposals.show', $proposal) : route('proposals.index')">Cancel</x-btn>
        <x-btn type="submit">Save proposal</x-btn>
    </div>
</div>

<script>
    function proposalEditor(initial, blockDefaults) {
        let uid = 0;
        const withUids = (section) => {
            if (section.type === 'section') {
                section.blocks = (section.blocks || []).map((b) => ({ ...b, _uid: ++uid }));
            }
            section._open = false;
            return section;
        };
        const randomKey = () => 's-' + Math.random().toString(36).slice(2, 10);

        return {
            sections: (initial || []).map(withUids),
            blockLabels: @js(ProposalBlocks::BLOCK_TYPES),

            move(list, index, delta) {
                const target = index + delta;
                if (target < 0 || target >= list.length) return;
                const [item] = list.splice(index, 1);
                list.splice(target, 0, item);
            },

            addSection() {
                const numbered = this.sections.filter((s) => s.type === 'section');
                const last = numbered[numbered.length - 1];
                const next = last && /^\d+$/.test(last.number) ? String(parseInt(last.number, 10) + 1).padStart(2, '0') : '';
                this.sections.push({
                    key: randomKey(), type: 'section', number: next, title: '', new_page: false, _open: true,
                    blocks: [{ ...blockDefaults.paragraph, _uid: ++uid }],
                });
            },

            addCover() {
                this.sections.unshift({
                    key: 'cover', type: 'cover', eyebrow: 'Project Proposal', brand: 'app_studio', prepared_for_label: 'Prepared for',
                    client_logo: null, title: '', subtitle: '', prepared_by: 'Chakra App Studio', client_name: '', date_label: '', _open: true,
                });
            },

            addBlock(section, type) {
                section.blocks.push({ ...JSON.parse(JSON.stringify(blockDefaults[type])), _uid: ++uid });
            },

            removeSection(index) {
                const section = this.sections[index];
                const name = section.type === 'cover' ? 'the cover' : ('"' + (section.title || 'this section') + '"');
                if (confirm('Remove ' + name + '? Comments on it stay, shown as general feedback.')) {
                    this.sections.splice(index, 1);
                }
            },

            // Runs on the form's submit event (see x-init above), so Enter in
            // a field saves the same state the button does.
            serialize() {
                this.$refs.json.value = JSON.stringify(this.sections.map((section) => {
                    const { _open, ...rest } = section;
                    if (rest.blocks) rest.blocks = rest.blocks.map(({ _uid, ...block }) => block);
                    return rest;
                }));
            },
        };
    }
</script>
