{{--
    The proposal editor: the A4 sheets themselves, typed into directly, with a
    Word-style toolbar for everything that is not typing. Behaviour lives in
    resources/js/proposal-editor.js; this is the same markup as the
    read-only views under proposals/sections and proposals/blocks, made
    editable, so what is typed here is what the client reads.

    Expects: $proposal, $clients, $settings.
--}}
@php
    use App\Models\Proposal;
    use App\Support\ProposalBlocks;

    // The studio mark for each cover brand, by the same rule the cover
    // itself uses (Proposal::studioLogoPath).
    $studioLogos = collect(['app_studio', 'production'])->mapWithKeys(function ($brand) use ($settings) {
        $probe = new Proposal(['sections' => [['key' => 'cover', 'type' => 'cover', 'data' => ['brand' => $brand]]]]);
        $path = $probe->studioLogoPath($settings);

        return [$brand => $path ? asset($path) : null];
    });

    $config = [
        'exists' => $proposal->exists,
        'sections' => $proposal->normalizedSections(),
        'title' => $proposal->title,
        'clientId' => $proposal->client_id,
        'validUntil' => $proposal->valid_until?->format('Y-m-d'),
        'clients' => $clients->map(fn ($c) => [
            'id' => $c->id,
            'name' => $c->name,
            'logo' => $c->logo_path && is_file(public_path($c->logo_path)) ? asset($c->logo_path) : null,
        ])->values(),
        'studioLogos' => $studioLogos,
        'techLogos' => collect(ProposalBlocks::TECH_LOGOS)->mapWithKeys(fn ($key) => [
            $key => ($path = ProposalBlocks::techLogoPath($key)) ? asset($path) : null,
        ]),
        'blockTypes' => ProposalBlocks::BLOCK_TYPES,
        'assetBase' => rtrim(asset('/'), '/').'/',
        'saveUrl' => $proposal->exists ? route('proposals.update', $proposal) : route('proposals.store'),
        'method' => $proposal->exists ? 'PUT' : 'POST',
        'csrf' => csrf_token(),
        'today' => now()->format('F Y'),
    ];

    $tool = 'inline-flex items-center justify-center gap-1.5 h-9 min-w-[36px] px-2 rounded-md text-sm text-brand-100/80 hover:bg-white/10 hover:text-white disabled:opacity-30 disabled:pointer-events-none';
    $toolOn = 'bg-white/15 text-white';
    $select = 'h-9 rounded-md bg-white/5 border-white/15 text-white text-sm focus:border-brand-400 focus:ring-brand-400 py-0 pl-2 pr-8';
    $group = 'flex items-center gap-0.5 pr-2 mr-1 border-r border-white/10 last:border-0';
    $groupLabel = 'hidden xl:inline text-[10px] uppercase tracking-widest text-brand-100/40 mr-1';
@endphp

@push('styles')
    @vite('resources/css/proposal.css')
@endpush

<div x-data="proposalEditor({{ Illuminate\Support\Js::from($config) }})" class="-mt-2">

    {{-- ================================================= The toolbar --}}
    <div class="sticky top-0 z-30 -mx-4 sm:-mx-6 lg:-mx-8 px-4 sm:px-6 lg:px-8 bg-brand-900/95 backdrop-blur border-b border-white/10">
        {{-- Row 1: the file --}}
        <div class="flex flex-wrap items-center gap-2 py-2">
            <a href="{{ $proposal->exists ? route('proposals.show', $proposal) : route('proposals.index') }}"
               class="{{ $tool }}" title="Back" aria-label="Back">&larr;</a>
            <input type="text" x-model="meta.title" @input="dirty = true" maxlength="255"
                   placeholder="Proposal name (only you see this)"
                   class="flex-1 min-w-[12rem] h-9 bg-transparent border-0 border-b border-transparent hover:border-white/20 focus:border-brand-400 focus:ring-0 text-white font-semibold text-base px-1">
            <select x-model="meta.client_id" @change="dirty = true" class="{{ $select }} max-w-[12rem]" aria-label="Client">
                <option value="">No client linked</option>
                <template x-for="c in clients" :key="c.id">
                    <option :value="c.id" x-text="c.name" :selected="String(c.id) === String(meta.client_id)"></option>
                </template>
            </select>
            <label class="flex items-center gap-1 text-xs text-brand-100/60">
                Valid until
                <input type="date" x-model="meta.valid_until" @change="dirty = true" class="{{ $select }} !pr-2">
            </label>
            <span class="text-xs text-brand-100/60 min-w-[7rem] text-right" x-text="saveLabel"
                  :class="{ 'text-amber-300': dirty && !saving }"></span>
            <x-btn type="button" size="sm" @click="save()" x-bind:disabled="saving" title="Save (Ctrl+S)">Save</x-btn>
            @if ($proposal->exists)
                <x-btn :href="route('proposals.show', $proposal)" variant="secondary" size="sm">Done</x-btn>
            @endif
        </div>

        {{-- Row 2: the ribbon. Buttons keep the caret where it is
             (mousedown.prevent), so formatting applies to the selection. --}}
        <div class="flex flex-wrap items-center gap-x-1 gap-y-1 pb-2">
            <div class="{{ $group }}">
                <button type="button" class="{{ $tool }}" @mousedown.prevent @click="undo()" :disabled="!history.length" title="Undo last structural change">&#8630;</button>
            </div>

            {{-- Text --}}
            <div class="{{ $group }}">
                <select class="{{ $select }} w-44" :disabled="!currentStyle" aria-label="Text style"
                        :value="currentStyle ?? ''" @change="setStyle($event.target.value)">
                    <option value="" disabled x-show="!currentStyle">Style</option>
                    <template x-for="(label, value) in styles" :key="value">
                        <option :value="value" x-text="label" :selected="value === currentStyle"></option>
                    </template>
                </select>
                <button type="button" class="{{ $tool }} font-bold" @mousedown.prevent @click="mark('bold')" title="Bold (Ctrl+B)">B</button>
                <button type="button" class="{{ $tool }} font-bold !text-green-400" @mousedown.prevent @click="mark('ok')" title="Green highlight — for good news">A</button>
                <button type="button" class="{{ $tool }} font-bold !text-amber-400" @mousedown.prevent @click="mark('warn')" title="Amber highlight — for a caution">A</button>
                <button type="button" class="{{ $tool }} text-xs" @mousedown.prevent @click="mark('clear')" title="Clear formatting on the selection">Clear</button>
            </div>

            {{-- Insert --}}
            <div class="{{ $group }} relative" @click.outside="insertOpen = false">
                <button type="button" class="{{ $tool }}" @mousedown.prevent @click="insertOpen = !insertOpen" :class="insertOpen && '{{ $toolOn }}'">
                    <span class="text-base leading-none">+</span> Insert <span class="text-[10px]">&#9662;</span>
                </button>
                <div x-show="insertOpen" x-cloak x-transition.opacity
                     class="absolute left-0 top-full mt-1 w-60 rounded-lg bg-brand-800 ring-1 ring-white/10 shadow-xl py-1 z-40 max-h-[70vh] overflow-y-auto">
                    <template x-for="(label, type) in blockTypes" :key="type">
                        <button type="button" class="w-full text-left px-3 py-2 text-sm text-brand-100/90 hover:bg-white/10" @mousedown.prevent @click="insertBlock(type)" x-text="label"></button>
                    </template>
                </div>
            </div>

            {{-- Section --}}
            <div class="{{ $group }}">
                <span class="{{ $groupLabel }}">Section</span>
                <button type="button" class="{{ $tool }}" @mousedown.prevent @click="addSection(false)" title="New numbered section after this one">+ Section</button>
                <button type="button" class="{{ $tool }}" @mousedown.prevent @click="addSection(true)" title="New section on a new page">+ Page</button>
                <button type="button" class="{{ $tool }}" @mousedown.prevent @click="togglePageBreak()" :disabled="section?.type !== 'section'"
                        :class="section?.data?.new_page && '{{ $toolOn }}'" title="Start this section on a new page">Page break</button>
                <button type="button" class="{{ $tool }}" @mousedown.prevent @click="renumber()" title="Number sections 01, 02, 03… in order">Renumber</button>
                <button type="button" class="{{ $tool }}" @mousedown.prevent @click="moveSection(-1)" :disabled="active.s === null || active.s === 0" title="Move section up">&uarr;</button>
                <button type="button" class="{{ $tool }}" @mousedown.prevent @click="moveSection(1)" :disabled="active.s === null || active.s === sections.length - 1" title="Move section down">&darr;</button>
                <button type="button" class="{{ $tool }} hover:!text-red-300" @mousedown.prevent @click="deleteSection()" :disabled="active.s === null" title="Delete section">&#128465;</button>
                <button type="button" class="{{ $tool }}" x-show="!cover" @mousedown.prevent @click="addCover()">+ Cover</button>
            </div>

            {{-- Block --}}
            <div class="{{ $group }}" x-show="block">
                <span class="{{ $groupLabel }}" x-text="blockTypes[block?.type] ?? 'Block'"></span>
                <button type="button" class="{{ $tool }}" @mousedown.prevent @click="moveBlock(-1)" :disabled="active.b === 0" title="Move up">&uarr;</button>
                <button type="button" class="{{ $tool }}" @mousedown.prevent @click="moveBlock(1)" :disabled="active.b === section?.data?.blocks?.length - 1" title="Move down">&darr;</button>
                <button type="button" class="{{ $tool }}" @mousedown.prevent @click="duplicateBlock()" title="Duplicate">&#10697;</button>
                <button type="button" class="{{ $tool }} hover:!text-red-300" @mousedown.prevent @click="deleteBlock()" title="Delete block">&#128465;</button>
            </div>

            {{-- What the block in hand can do --}}
            <div class="flex flex-wrap items-center gap-0.5 text-sm">
                <template x-if="block?.type === 'table'">
                    <div class="flex items-center gap-0.5">
                        <button type="button" class="{{ $tool }}" @mousedown.prevent @click="addRow(true)">+ Row</button>
                        <button type="button" class="{{ $tool }}" @mousedown.prevent @click="deleteRow()" :disabled="active.r === null || active.r < 0">&minus; Row</button>
                        <button type="button" class="{{ $tool }}" @mousedown.prevent @click="addColumn(true)">+ Column</button>
                        <button type="button" class="{{ $tool }}" @mousedown.prevent @click="deleteColumn()" :disabled="active.c === null">&minus; Column</button>
                        <button type="button" class="{{ $tool }}" @mousedown.prevent @click="toggleHeader()" :class="block.header.length && '{{ $toolOn }}'">Header row</button>
                        <button type="button" class="{{ $tool }}" @mousedown.prevent @click="block.first_col_bold = !block.first_col_bold; dirty = true" :class="block.first_col_bold && '{{ $toolOn }}'" title="Bold first column">B&#8203;|</button>
                        <button type="button" class="{{ $tool }}" @mousedown.prevent @click="block.last_col_right = !block.last_col_right; dirty = true" :class="block.last_col_right && '{{ $toolOn }}'" title="Right-align the last column (prices)">&#8677;</button>
                        <button type="button" class="{{ $tool }}" @mousedown.prevent @click="block.last_row_bold = !block.last_row_bold; dirty = true" :class="block.last_row_bold && '{{ $toolOn }}'" title="Bold the last row (subtotal)">&Sigma;</button>
                    </div>
                </template>

                <template x-if="block?.type === 'cards'">
                    <div class="flex items-center gap-1">
                        <select class="{{ $select }}" x-model="block.variant" @change="dirty = true" aria-label="Card layout">
                            <option value="labelled">Label + text</option>
                            <option value="plain">Plain boxes</option>
                            <option value="stat">Big figure + text</option>
                        </select>
                        <select class="{{ $select }}" x-model.number="block.columns" @change="dirty = true" aria-label="Columns">
                            <option value="1">1 column</option><option value="2">2 columns</option><option value="3">3 columns</option><option value="4">4 columns</option>
                        </select>
                        <button type="button" class="{{ $tool }}" @mousedown.prevent @click="addItem(active.s, active.b, active.i)">+ Card</button>
                        <button type="button" class="{{ $tool }}" @mousedown.prevent @click="removeItem(active.s, active.b, active.i)" :disabled="active.i === null">&minus; Card</button>
                    </div>
                </template>

                <template x-if="block?.type === 'flow'">
                    <div class="flex items-center gap-1">
                        <select class="{{ $select }}" x-model="block.tone" @change="dirty = true" aria-label="Look">
                            <option value="light">Light steps</option>
                            <option value="dark">Dark steps</option>
                            <option value="line">One line with arrows</option>
                        </select>
                        <button type="button" class="{{ $tool }}" @mousedown.prevent @click="block.numbered = !block.numbered; dirty = true" :class="block.numbered && '{{ $toolOn }}'" x-show="block.tone !== 'line'">01 Numbered</button>
                        <select class="{{ $select }}" x-model.number="block.columns" @change="dirty = true" x-show="block.tone !== 'line'" aria-label="Steps per row">
                            <option value="0">All in one row</option>
                            <template x-for="n in 8" :key="n"><option :value="n" x-text="n + ' per row'"></option></template>
                        </select>
                        <button type="button" class="{{ $tool }}" @mousedown.prevent @click="addItem(active.s, active.b, active.i)">+ Step</button>
                        <button type="button" class="{{ $tool }}" @mousedown.prevent @click="removeItem(active.s, active.b, active.i)" :disabled="active.i === null">&minus; Step</button>
                    </div>
                </template>

                <template x-if="block?.type === 'chips'">
                    <div class="flex items-center gap-1">
                        <button type="button" class="{{ $tool }}" @mousedown.prevent @click="addItem(active.s, active.b, active.i)">+ Pill</button>
                        <button type="button" class="{{ $tool }}" @mousedown.prevent @click="removeItem(active.s, active.b, active.i)" :disabled="active.i === null">&minus; Pill</button>
                    </div>
                </template>

                <template x-if="block?.type === 'legend'">
                    <div class="flex items-center gap-1">
                        <select class="{{ $select }}" x-show="active.i !== null && block.items[active.i]" aria-label="Tag colour"
                                :value="block.items[active.i]?.tag" @change="block.items[active.i].tag = $event.target.value; dirty = true">
                            <template x-for="(label, tag) in tags" :key="tag"><option :value="tag" x-text="label"></option></template>
                        </select>
                        <button type="button" class="{{ $tool }}" @mousedown.prevent @click="addItem(active.s, active.b, active.i)">+ Tag</button>
                        <button type="button" class="{{ $tool }}" @mousedown.prevent @click="removeItem(active.s, active.b, active.i)" :disabled="active.i === null">&minus; Tag</button>
                    </div>
                </template>

                <template x-if="block?.type === 'stack'">
                    <div class="flex items-center gap-1">
                        <select class="{{ $select }}" x-show="active.i !== null && block.items[active.i]" aria-label="Logo"
                                :value="block.items[active.i]?.logo" @change="block.items[active.i].logo = $event.target.value; dirty = true">
                            <option value="">No logo</option>
                            @foreach (ProposalBlocks::TECH_LOGOS as $logo)
                                <option value="{{ $logo }}">{{ ucfirst($logo) }}</option>
                            @endforeach
                        </select>
                        <button type="button" class="{{ $tool }}" @mousedown.prevent @click="addItem(active.s, active.b, active.i)">+ Item</button>
                        <button type="button" class="{{ $tool }}" @mousedown.prevent @click="removeItem(active.s, active.b, active.i)" :disabled="active.i === null">&minus; Item</button>
                    </div>
                </template>

                <template x-if="block?.type === 'architecture'">
                    <div class="flex items-center gap-1">
                        <select class="{{ $select }}" x-show="activeLayer" aria-label="Layer look"
                                :value="activeLayer?.style" @change="activeLayer.style = $event.target.value; dirty = true">
                            <option value="light">Light boxes</option>
                            <option value="dark">Dark band</option>
                            <option value="outline">Outlined boxes</option>
                        </select>
                        <select class="{{ $select }}" x-show="activeLayer" aria-label="Boxes per row"
                                :value="activeLayer?.columns" @change="activeLayer.columns = parseInt($event.target.value, 10); dirty = true">
                            <template x-for="n in 6" :key="n"><option :value="n" x-text="n + ' per row'"></option></template>
                        </select>
                        <button type="button" class="{{ $tool }}" @mousedown.prevent @click="addBox()">+ Box</button>
                        <button type="button" class="{{ $tool }}" @mousedown.prevent @click="deleteBox()" :disabled="active.j === null">&minus; Box</button>
                        <button type="button" class="{{ $tool }}" @mousedown.prevent @click="activeBox.dashed = !activeBox.dashed; dirty = true" :disabled="!activeBox" :class="activeBox?.dashed && '{{ $toolOn }}'" title="Dashed: future or optional">Dashed</button>
                        <button type="button" class="{{ $tool }}" @mousedown.prevent @click="addLayer()">+ Layer</button>
                        <button type="button" class="{{ $tool }}" @mousedown.prevent @click="deleteLayer()" :disabled="active.i === null">&minus; Layer</button>
                    </div>
                </template>

                <template x-if="block?.type === 'swimlane'">
                    <div class="flex items-center gap-1">
                        <button type="button" class="{{ $tool }}" @mousedown.prevent @click="addSwimRow()">+ Step</button>
                        <button type="button" class="{{ $tool }}" @mousedown.prevent @click="deleteSwimRow()" :disabled="active.r === null || active.r < 0">&minus; Step</button>
                        <button type="button" class="{{ $tool }}" @mousedown.prevent @click="activeSwimCell.loop = !activeSwimCell.loop; dirty = true" :disabled="!activeSwimCell" :class="activeSwimCell?.loop && '{{ $toolOn }}'" title="Mark as a review / correction loop">Loop</button>
                        <button type="button" class="{{ $tool }}" @mousedown.prevent @click="block.legend = !block.legend; dirty = true" :class="block.legend && '{{ $toolOn }}'">Colour key</button>
                    </div>
                </template>

                <template x-if="section?.type === 'cover'">
                    <div class="flex items-center gap-1">
                        <select class="{{ $select }}" x-model="cover.brand" @change="dirty = true" aria-label="Studio logo">
                            <option value="app_studio">Chakra App Studio logo</option>
                            <option value="production">Chakra Productions logo</option>
                        </select>
                        <button type="button" class="{{ $tool }}" @mousedown.prevent @click="$refs.logo.click()">Client logo&hellip;</button>
                    </div>
                </template>
            </div>
        </div>

        <div x-show="error" x-cloak class="mb-2 rounded-md bg-red-500/15 ring-1 ring-red-400/30 px-3 py-2 text-sm text-red-200" x-text="error"></div>
    </div>

    <input type="file" x-ref="logo" class="hidden" accept="image/png,image/jpeg,image/svg+xml,image/webp" @change="pickLogo($event)">

    {{-- ================================================= The document --}}
    <div class="cp-doc cp-editing py-6">
        <template x-for="sheet in sheets" :key="sheet.key">
            <div class="contents">
                {{-- Cover --}}
                <template x-if="sheet.cover">
                    <article class="cp-sheet cp-cover" :data-section="sections[sheet.items[0]].key"
                             @focusin="setActive(sheet.items[0], null)" :class="active.s === sheet.items[0] && 'is-active'">
                        <div class="cp-cover__top">
                            <img x-show="studioLogo()" :src="studioLogo()" alt="" class="cp-cover__brand">
                            <span x-show="!studioLogo()"></span>
                            <div class="cp-cover__eyebrow" x-edit="cover.eyebrow" data-placeholder="Project Proposal"></div>
                        </div>

                        <div>
                            <div class="cp-cover__for" x-edit="cover.prepared_for_label" data-placeholder="Prepared for"></div>
                            <div class="cp-e-logo">
                                <template x-if="clientLogo">
                                    <img :src="clientLogo" alt="" class="cp-cover__logo">
                                </template>
                                <div class="cp-e-logo__actions">
                                    <button type="button" class="cp-e-chip" @click="$refs.logo.click()" x-text="clientLogo ? 'Change logo' : '+ Client logo'"></button>
                                    <button type="button" class="cp-e-chip" x-show="(cover.client_logo && !removeLogo) || logoFile" @click="dropLogo()">Remove</button>
                                </div>
                            </div>
                            <h1 x-edit="cover.title" :data-placeholder="meta.title || 'Client name'"></h1>
                            <p class="cp-cover__subtitle" x-edit="cover.subtitle" data-placeholder="What this proposal is for"></p>
                        </div>

                        <dl class="cp-cover__meta">
                            <div><dt>Prepared by</dt><dd x-edit="cover.prepared_by" data-placeholder="Chakra App Studio"></dd></div>
                            <div><dt>Client</dt><dd x-edit="cover.client_name" :data-placeholder="clientName || '—'"></dd></div>
                            <div><dt>Date</dt><dd x-edit="cover.date_label" data-placeholder="{{ now()->format('F Y') }}"></dd></div>
                        </dl>
                    </article>
                </template>

                {{-- A page of sections --}}
                <template x-if="!sheet.cover">
                    <article class="cp-sheet">
                        <div class="cp-sheet__body">
                            <template x-for="s in sheet.items" :key="sections[s].key">
                                <section class="cp-section" :data-section="sections[s].key"
                                         :class="{ 'is-active': active.s === s }"
                                         @focusin="setActive(s, null)">
                                    <div class="cp-section__head">
                                        <h2 class="cp-e-head" :class="{ 'cp-e-head--empty': !sections[s].data.number && !sections[s].data.title }">
                                            <span class="cp-e-num" x-edit="sections[s].data.number" data-placeholder="00"></span>
                                            <span class="cp-e-title" x-edit="sections[s].data.title" data-placeholder="Section heading — leave empty for none"></span>
                                        </h2>
                                    </div>

                                    <template x-for="(block, b) in sections[s].data.blocks" :key="block._uid">
                                        <div class="cp-eblock" :data-uid="block._uid" :data-label="blockTypes[block.type]"
                                             :class="{ 'is-active': isActive(s, b) }"
                                             @focusin.stop="setActive(s, b)">
                                            @include('proposals.editor.blocks')
                                        </div>
                                    </template>

                                    <div class="cp-e-tail" @click="appendParagraph(s)" title="Click to add text here"></div>
                                </section>
                            </template>
                        </div>
                        <footer class="cp-sheet__foot">
                            <span x-text="footer"></span>
                            <span x-text="pageLabel(sheet.number)"></span>
                        </footer>
                    </article>
                </template>
            </div>
        </template>

        <div class="cp-e-end">
            <button type="button" class="cp-e-chip" @click="setActive(sections.length - 1, null); addSection(false)">+ Section</button>
            <button type="button" class="cp-e-chip" @click="setActive(sections.length - 1, null); addSection(true)">+ New page</button>
        </div>
    </div>
</div>
