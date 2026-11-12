{{--
    Chakra Planner, web version: the Reel, Post, Story and YouTube planners
    from Notion as a calendar, a board and a table, edited in place. Every
    change is written to Notion first. Drawn by resources/js/planner-web.js.
--}}
@php
    $pill = 'inline-flex max-w-full items-center gap-1.5 rounded-md px-1.5 py-0.5 text-[12px] font-medium';
    $selectReset = 'cursor-pointer appearance-none border-0 bg-transparent bg-none p-0 text-inherit focus:ring-0';
    $propRow = 'grid grid-cols-[104px_1fr] items-center gap-x-2 rounded-lg px-2 py-1.5 hover:bg-white/[0.04]';
    $propInput = 'h-8 w-full rounded-md border-0 bg-transparent px-1.5 text-[13px] text-white hover:bg-white/[0.06] focus:bg-[#0f2230] focus:ring-1 focus:ring-brand-400 disabled:opacity-60';
@endphp
<x-app-layout title="Chakra Planner">
    <div class="mx-auto max-w-[1400px] space-y-4 pb-16"
         x-data="plannerWeb(@js([
             'meta' => route('planner.data.meta'),
             'items' => route('planner.data.items'),
             'item' => route('planner.data.item', ['item' => '__ID__']),
             'move' => route('planner.data.move', ['item' => '__ID__']),
             'store' => route('planner.data.store'),
             'captions' => route('planner.data.captions', ['item' => '__ID__']),
             'caption' => route('planner.data.caption', ['item' => '__ID__']),
             'refresh' => route('planner.data.refresh'),
         ]), @js($canEdit))">

        @include('planner._tabs')

        {{-- Month and actions --}}
        <header class="flex flex-wrap items-center gap-3">
            <div class="mr-auto">
                <h1 class="text-[28px] font-extrabold tracking-tight text-white" x-text="monthLabel"></h1>
                <p class="text-sm text-brand-100/60">
                    <span x-text="counts.total"></span> videos planned · <span x-text="counts.out"></span> published ·
                    Notion synced <span x-text="ago(meta.notion.last_synced_at)"></span>
                </p>
            </div>
            <nav class="inline-flex h-11 items-center gap-1 rounded-xl border border-white/[0.08] bg-[#15303f] px-1" aria-label="Month">
                <button type="button" @click="shift(-1)" class="flex h-9 w-9 items-center justify-center rounded-lg text-lg text-brand-100/70 transition hover:bg-white/10 hover:text-white" aria-label="Previous month" title="Previous month (←)">‹</button>
                <button type="button" @click="thisMonth()" class="h-9 rounded-lg px-3 text-sm font-semibold text-white transition hover:bg-white/10" title="This month (T)">Today</button>
                <button type="button" @click="shift(1)" class="flex h-9 w-9 items-center justify-center rounded-lg text-lg text-brand-100/70 transition hover:bg-white/10 hover:text-white" aria-label="Next month" title="Next month (→)">›</button>
            </nav>
            {{-- The same month, three ways. --}}
            <div class="inline-flex h-11 items-center gap-1 rounded-xl border border-white/[0.08] bg-[#15303f] p-1" role="group" aria-label="View">
                <template x-for="v in [['calendar', 'Calendar'], ['board', 'Board'], ['table', 'Table']]" :key="v[0]">
                    <button type="button" @click="setView(v[0])" :aria-pressed="view === v[0] ? 'true' : 'false'"
                            class="h-9 rounded-lg px-3 text-sm font-semibold transition"
                            :class="view === v[0] ? 'bg-white text-brand-900' : 'text-brand-100/70 hover:text-white'" x-text="v[1]"></button>
                </template>
            </div>
            <button type="button" x-show="canEdit" x-cloak @click="startNew()" title="New video (N)"
                    class="inline-flex h-11 items-center gap-1.5 rounded-xl bg-brand-400 px-4 text-sm font-bold text-brand-900 transition hover:bg-brand-300">
                <span class="text-base leading-none">+</span> New
            </button>
            <button type="button" @click="refresh()" :disabled="refreshing"
                    class="inline-flex h-11 items-center rounded-xl border border-white/[0.08] bg-[#15303f] px-4 text-sm font-semibold text-white transition hover:bg-white/10 disabled:opacity-50"
                    x-text="refreshing ? 'Refreshing…' : 'Refresh'"></button>
            @can('planner.manage')
                <a href="{{ route('planner.setup') }}" class="text-sm text-brand-300 hover:text-white">Desktop app</a>
            @endcan
        </header>

        {{-- Filters --}}
        <div class="flex flex-wrap items-center gap-2">
            <template x-for="s in meta.sources" :key="s.key">
                <button type="button" @click="toggleSource(s.key)"
                        class="h-9 rounded-full px-3.5 text-[13px] font-semibold ring-1 transition"
                        :class="sources.includes(s.key) ? 'bg-brand-400/20 text-white ring-brand-400/60' : 'text-brand-100/70 ring-white/15 hover:text-white'"
                        x-text="s.label"></button>
            </template>
            <select x-model="client" class="h-9 rounded-full border-0 bg-[#15303f] py-0 pl-3.5 pr-8 text-[13px] text-white ring-1 ring-white/15 focus:ring-brand-400" aria-label="Client">
                <option value="">All clients</option>
                <template x-for="c in meta.clients" :key="c.name">
                    <option :value="c.name" x-text="c.name"></option>
                </template>
            </select>
            <input type="search" x-ref="search" x-model.debounce.200ms="q" placeholder="Search  ( / )"
                   class="h-9 w-56 rounded-full border-0 bg-[#15303f] px-3.5 text-[13px] text-white ring-1 ring-white/15 placeholder:text-brand-100/40 focus:ring-brand-400">
            <div x-show="view === 'board'" x-cloak class="inline-flex h-9 items-center gap-1.5 rounded-full bg-[#15303f] pl-3.5 pr-1 text-[13px] text-brand-100/60 ring-1 ring-white/15">
                Group by
                <select x-model="group" class="h-7 rounded-full border-0 bg-transparent py-0 pl-1 pr-7 text-[13px] font-semibold text-white focus:ring-0" aria-label="Group the board by">
                    <option value="status">Status</option>
                    <option value="editor">Editor</option>
                    <option value="client">Client</option>
                </select>
            </div>

            {{-- Saved views --}}
            <template x-for="v in views" :key="v.name">
                <span class="inline-flex h-9 items-center rounded-full bg-white/[0.06] text-[13px] text-white ring-1 ring-white/10">
                    <button type="button" @click="useView(v)" class="h-9 pl-3.5 pr-1.5 font-medium hover:text-brand-200" x-text="v.name"></button>
                    <button type="button" @click="dropView(v.name)" class="h-9 pl-1 pr-3 text-brand-100/40 hover:text-red-300" :aria-label="'Remove the view ' + v.name">✕</button>
                </span>
            </template>
            <button type="button" @click="saveView()" x-show="sources.length || client || q" x-cloak
                    class="h-9 rounded-full px-3 text-[13px] text-brand-300 hover:text-white">Save this view</button>

            <p class="ml-auto hidden text-xs text-brand-100/40 lg:block" x-show="canEdit">Drag a card to move it · click anything to edit</p>
        </div>

        {{-- First load: the shape of the page, not a blank one. --}}
        <div x-show="!ready" class="grid grid-cols-7 gap-1.5">
            @for ($n = 0; $n < 35; $n++)
                <div class="h-[132px] animate-pulse rounded-xl bg-[#15303f]/70"></div>
            @endfor
        </div>

        <div class="flex flex-col gap-4 xl:flex-row" x-show="ready" x-cloak>
            {{-- Board: the month's videos in columns. Drag a card across to change it. --}}
            <div class="min-w-0 flex-1 overflow-x-auto pb-2" x-show="view === 'board'" x-cloak x-transition.opacity.duration.150ms>
                <div class="flex min-h-[420px] gap-3">
                    <template x-for="col in board" :key="group + ':' + col.key">
                        <div class="flex w-[264px] shrink-0 flex-col gap-2 rounded-xl bg-[#0f2230] p-2 transition"
                             :class="overKey === 'col:' + col.key ? 'ring-2 ring-brand-400' : ''"
                             @dragover.prevent="overKey = 'col:' + col.key" @drop.prevent="dropColumn(col)">
                            <div class="flex items-center gap-2 px-1.5 pt-1">
                                <span class="{{ $pill }}" :style="`background:${col.color}26;color:${col.color}`">
                                    <span class="h-1.5 w-1.5 rounded-full" :style="`background:${col.color}`"></span>
                                    <span class="truncate" x-text="col.name"></span>
                                </span>
                                <span class="text-xs text-brand-100/45" x-text="col.items.length"></span>
                                <button type="button" x-show="canEdit && group === 'status' && !!col.key" @click="startNew({ status: col.key })"
                                        class="ml-auto flex h-6 w-6 items-center justify-center rounded-md text-brand-100/40 hover:bg-white/10 hover:text-white" :aria-label="'New video in ' + col.name">+</button>
                            </div>
                            <template x-for="i in col.items" :key="i.id">
                                <button type="button" @click="show(i)" :draggable="canEdit" @dragstart="drag(i, $event)" @dragend="endDrag()"
                                        class="rounded-lg bg-[#1a3a4b] p-2.5 text-left shadow-sm ring-1 ring-white/[0.06] transition hover:-translate-y-px hover:ring-brand-400/40"
                                        :class="[dragId === i.id ? 'opacity-40' : '', peek && peek.id === i.id ? 'ring-brand-400/70' : '']">
                                    <span class="flex items-start gap-1.5">
                                        <span class="flex-1 text-[12.5px] font-medium leading-snug text-white"><span x-text="i.title || 'Untitled'"></span><span x-show="i.has_script" class="ml-1 text-[10px]" title="Has a script">📝</span></span>
                                        <span x-show="isSaving(i.id)" class="mt-1 h-2 w-2 shrink-0 animate-pulse rounded-full bg-brand-300" title="Saving to Notion"></span>
                                    </span>
                                    <span class="mt-1.5 flex flex-wrap gap-1 text-[10.5px]">
                                        <span x-show="group !== 'client' && !!i.client" class="rounded bg-brand-400/15 px-1.5 py-0.5 text-brand-200" x-text="i.client"></span>
                                        <span x-show="group !== 'status'" class="rounded px-1.5 py-0.5" :style="`background:${color(i)}26;color:${color(i)}`" x-text="i.status"></span>
                                        <span class="rounded bg-white/[0.07] px-1.5 py-0.5 text-brand-100/75" x-text="i.published_date ? fmtDay(i.published_date) : 'No date'"></span>
                                        <span class="rounded bg-white/[0.07] px-1.5 py-0.5 text-brand-100/75" x-text="label(i.source)"></span>
                                        <span x-show="group !== 'editor' && editorsOf(i).length > 0" class="rounded bg-white/[0.07] px-1.5 py-0.5 text-brand-100/75" x-text="editorsOf(i).join(', ')"></span>
                                    </span>
                                </button>
                            </template>
                            <p x-show="col.items.length === 0" class="px-1.5 py-6 text-center text-[11px] text-brand-100/30">Nothing here</p>
                        </div>
                    </template>
                </div>
            </div>

            {{-- Table: the month's videos by date. Status and date change in the row. --}}
            <div class="min-w-0 flex-1 overflow-x-auto rounded-xl border border-white/[0.06] bg-[#15303f]" x-show="view === 'table'" x-cloak x-transition.opacity.duration.150ms>
                <table class="w-full min-w-[860px] text-[13px]">
                    <thead class="text-left text-[11px] font-semibold uppercase tracking-wider text-brand-100/45">
                        <tr><th class="px-4 py-3">Video</th><th class="px-3 py-3">Planner</th><th class="px-3 py-3">Client</th><th class="px-3 py-3">Status</th><th class="px-3 py-3">Publish</th><th class="px-4 py-3">Editor</th></tr>
                    </thead>
                    <tbody>
                        <template x-for="i in tableRows" :key="i.id">
                            <tr class="cursor-pointer border-t border-white/[0.05] transition hover:bg-white/[0.04]" :class="peek && peek.id === i.id ? 'bg-white/[0.05]' : ''" @click="show(i)">
                                <td class="px-4 py-2 text-white">
                                    <span x-text="i.title || 'Untitled'"></span><span x-show="i.has_script" class="ml-1 text-[11px]" title="Has a script">📝</span>
                                    <span x-show="isSaving(i.id)" class="ml-1.5 inline-block h-2 w-2 animate-pulse rounded-full bg-brand-300" title="Saving to Notion"></span>
                                </td>
                                <td class="px-3 py-2 text-brand-100/70" x-text="label(i.source)"></td>
                                <td class="px-3 py-2 text-brand-100/70" x-text="i.client || '—'"></td>
                                <td class="px-3 py-2" @click.stop>
                                    <span class="{{ $pill }}" :style="`background:${color(i)}26;color:${color(i)}`">
                                        <select class="{{ $selectReset }} text-[12px] font-medium" :disabled="!canEdit" :aria-label="'Status of ' + i.title"
                                                @change="save(i.id, { status: $event.target.value })">
                                            <template x-for="o in optionsWith(i.source, 'status', i.status)" :key="o.name">
                                                <option :value="o.name" :selected="o.name === i.status" x-text="o.name" class="bg-[#15303f] text-white"></option>
                                            </template>
                                        </select>
                                    </span>
                                </td>
                                <td class="px-3 py-2" @click.stop>
                                    <input type="date" :value="i.published_date" :disabled="!canEdit" :aria-label="'Publish date of ' + i.title"
                                           @change="save(i.id, { published_date: $event.target.value || null })"
                                           class="h-7 w-[132px] rounded-md border-0 bg-transparent px-1 text-[13px] text-brand-100/80 [color-scheme:dark] hover:bg-white/[0.06] focus:bg-[#0f2230] focus:ring-1 focus:ring-brand-400">
                                </td>
                                <td class="px-4 py-2 text-brand-100/70" x-text="editorsOf(i).join(', ') || '—'"></td>
                            </tr>
                        </template>
                        <tr x-show="!tableRows.length"><td colspan="6" class="px-4 py-10 text-center text-brand-100/50">No videos this month.</td></tr>
                    </tbody>
                </table>
            </div>

            {{-- Calendar --}}
            <div class="min-w-0 flex-1 overflow-x-auto" x-show="view === 'calendar'" x-transition.opacity.duration.150ms>
                <div class="min-w-[860px] transition-opacity" :class="loading ? 'opacity-70' : ''">
                    <div class="grid grid-cols-7 gap-px pb-1.5 text-[11px] font-semibold uppercase tracking-wider text-brand-100/45">
                        @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d)
                            <span class="px-2">{{ $d }}</span>
                        @endforeach
                    </div>
                    <div class="grid grid-cols-7 gap-1.5">
                        <template x-for="d in days" :key="d.key">
                            <div class="group/day flex min-h-[132px] flex-col gap-1 rounded-xl p-1.5 transition"
                                 :class="[d.inMonth ? 'bg-[#15303f]' : 'bg-[#15303f]/40', overKey === d.key ? 'ring-2 ring-brand-400 bg-brand-400/10' : '']"
                                 @dragover.prevent="overKey = d.key" @drop.prevent="dropDay(d.key)">
                                <div class="flex items-center">
                                    <span class="px-1 text-xs font-semibold"
                                          :class="d.today ? 'inline-flex h-6 w-6 items-center justify-center rounded-full bg-brand-400 text-brand-900' : (d.inMonth ? 'text-brand-100/70' : 'text-brand-100/30')"
                                          x-text="d.day"></span>
                                    <button type="button" x-show="canEdit" @click="startNew({ published_date: d.key })"
                                            class="ml-auto flex h-5 w-5 items-center justify-center rounded text-brand-100/40 opacity-0 transition hover:bg-white/10 hover:text-white focus:opacity-100 group-hover/day:opacity-100"
                                            :aria-label="'New video on ' + fmtDay(d.key)">+</button>
                                </div>
                                <template x-for="i in (expanded === d.key ? d.items : d.items.slice(0, 6))" :key="i.id">
                                    <button type="button" @click="show(i)" :draggable="canEdit" @dragstart="drag(i, $event)" @dragend="endDrag()"
                                            class="flex items-center gap-1.5 rounded-md px-1.5 py-1 text-left text-[11.5px] leading-tight text-white transition hover:brightness-125"
                                            :class="[dragId === i.id ? 'opacity-40' : '', peek && peek.id === i.id ? 'ring-1 ring-brand-400' : '']"
                                            :style="`background:${color(i)}1f`"
                                            :title="label(i.source) + ' · ' + i.status + ' · ' + (i.client || '')">
                                        <span class="h-2 w-2 shrink-0 rounded-full" :class="isSaving(i.id) ? 'animate-pulse' : ''" :style="`background:${color(i)}`"></span>
                                        <span class="truncate" x-text="i.title || 'Untitled'"></span>
                                        <span x-show="i.has_script" class="ml-auto shrink-0 text-[10px] opacity-70" title="Has a script">📝</span>
                                    </button>
                                </template>
                                <button type="button" x-show="d.items.length > 6" @click="expanded = expanded === d.key ? null : d.key"
                                        class="px-1 text-left text-[11px] text-brand-300 hover:text-white"
                                        x-text="expanded === d.key ? 'Show less' : '+' + (d.items.length - 6) + ' more'"></button>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            {{-- No date yet: drag onto a day to plan it, or drop a card here to clear its date. --}}
            <aside class="w-full shrink-0 xl:w-72" x-show="view === 'calendar'">
                <section class="rounded-2xl border border-white/10 bg-[#15303f] p-3 transition"
                         @dragover.prevent="overKey = 'none'" @drop.prevent="dropDay(null)"
                         :class="overKey === 'none' ? 'ring-2 ring-brand-400' : ''">
                    <h2 class="px-1 pb-2 text-sm font-semibold text-white">No date yet <span class="font-normal text-brand-100/50" x-text="'(' + undatedShown.length + ')'"></span></h2>
                    <div class="max-h-[640px] space-y-1 overflow-y-auto">
                        <template x-for="i in undatedShown" :key="i.id">
                            <button type="button" @click="show(i)" :draggable="canEdit" @dragstart="drag(i, $event)" @dragend="endDrag()"
                                    class="flex w-full items-center gap-1.5 rounded-md bg-white/[0.05] px-2 py-1.5 text-left text-[12px] text-white transition hover:bg-white/10"
                                    :class="dragId === i.id ? 'opacity-40' : ''">
                                <span class="h-2 w-2 shrink-0 rounded-full" :style="`background:${color(i)}`"></span>
                                <span class="truncate" x-text="i.title || 'Untitled'"></span>
                            </button>
                        </template>
                        <p x-show="undatedShown.length === 0" class="px-1 py-3 text-xs text-brand-100/45">Everything has a date.</p>
                    </div>
                </section>
            </aside>
        </div>

        {{-- ——— Side peek: one video, like a Notion page ——— --}}
        <template x-teleport="body">
            <div x-show="!!peek" x-cloak class="fixed inset-0 z-[60]" role="dialog" aria-modal="true" :aria-label="peek ? peek.title : ''">
                <div class="absolute inset-0 bg-black/30" x-show="!!peek" x-transition.opacity.duration.200ms @click="close()"></div>
                <aside x-show="!!peek"
                       x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
                       x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
                       class="theme-dark absolute inset-y-0 right-0 flex w-full max-w-[520px] flex-col border-l border-white/10 bg-[#132a38] text-white shadow-2xl">
                    <template x-if="peek">
                        <div class="flex min-h-0 flex-1 flex-col">
                            <div class="flex shrink-0 items-center gap-2 border-b border-white/[0.06] px-4 py-2.5">
                                <button type="button" @click="close()" class="flex h-8 w-8 items-center justify-center rounded-lg text-lg text-brand-100/60 hover:bg-white/10 hover:text-white" aria-label="Close (Esc)" title="Close (Esc)">»</button>
                                <span class="text-xs text-brand-100/50" x-text="label(peek.source) + ' planner'"></span>
                                <span x-show="isSaving(peek.id)" class="flex items-center gap-1.5 text-xs text-brand-200"><span class="h-1.5 w-1.5 animate-pulse rounded-full bg-brand-300"></span>Saving to Notion</span>
                                <a x-show="!!peek.notion_url" :href="peek.notion_url" target="_blank" rel="noopener" class="ml-auto rounded-lg px-2.5 py-1.5 text-xs font-semibold text-brand-100/70 hover:bg-white/10 hover:text-white">Open in Notion ↗</a>
                            </div>

                            <div class="min-h-0 flex-1 overflow-y-auto px-4 pb-10 pt-4">
                                <div class="flex items-start gap-3">
                                    <template x-if="!!peek.thumbnail_url">
                                        <img :src="peek.thumbnail_url" alt="" class="w-14 shrink-0 rounded-lg bg-white/5 object-cover ring-1 ring-white/10">
                                    </template>
                                    <textarea rows="1" :disabled="!canEdit" :value="peek.title" aria-label="Title"
                                              x-effect="peek.title; $nextTick(() => { $el.style.height = 'auto'; $el.style.height = $el.scrollHeight + 'px'; })"
                                              @input="$el.style.height = 'auto'; $el.style.height = $el.scrollHeight + 'px'"
                                              @keydown.enter.prevent="$el.blur()"
                                              @blur="$el.value.trim() ? save(peek.id, { title: $el.value.trim() }) : ($el.value = peek.title)"
                                              class="min-w-0 flex-1 resize-none overflow-hidden rounded-lg border-0 bg-transparent px-1.5 py-1 text-[22px] font-bold leading-tight text-white hover:bg-white/[0.04] focus:bg-[#0f2230] focus:ring-1 focus:ring-brand-400"></textarea>
                                </div>

                                {{-- Properties --}}
                                <div class="mt-3 text-[13px]">
                                    <div class="{{ $propRow }}">
                                        <span class="text-brand-100/50">Status</span>
                                        <span>
                                            <span class="{{ $pill }}" :style="`background:${color(peek)}26;color:${color(peek)}`">
                                                <span class="h-1.5 w-1.5 rounded-full" :style="`background:${color(peek)}`"></span>
                                                <select class="{{ $selectReset }} text-[12.5px] font-medium" :disabled="!canEdit" aria-label="Status" @change="save(peek.id, { status: $event.target.value })">
                                                    <template x-for="o in optionsWith(peek.source, 'status', peek.status)" :key="o.name">
                                                        <option :value="o.name" :selected="o.name === peek.status" x-text="o.name" class="bg-[#15303f] text-white"></option>
                                                    </template>
                                                </select>
                                            </span>
                                        </span>
                                    </div>

                                    <div class="{{ $propRow }}">
                                        <span class="text-brand-100/50">Publish date</span>
                                        <input type="date" :value="peek.published_date" :disabled="!canEdit" aria-label="Publish date"
                                               @change="save(peek.id, { published_date: $event.target.value || null })"
                                               class="{{ $propInput }} w-[150px] [color-scheme:dark]">
                                    </div>

                                    <div class="{{ $propRow }}" x-show="!!field(peek.source, 'client')">
                                        <span class="text-brand-100/50">Client</span>
                                        <select class="{{ $propInput }} py-0 pr-8" :disabled="!canEdit" aria-label="Client" @change="save(peek.id, { client: $event.target.value || null })">
                                            <option value="" :selected="!peek.client" class="bg-[#15303f]">Empty</option>
                                            <template x-for="o in optionsWith(peek.source, 'client', peek.client)" :key="o.name">
                                                <option :value="o.name" :selected="o.name === peek.client" x-text="o.name" class="bg-[#15303f]"></option>
                                            </template>
                                        </select>
                                    </div>

                                    <div class="{{ $propRow }} items-start" x-show="!!field(peek.source, 'editor')">
                                        <span class="pt-1 text-brand-100/50">Editor</span>
                                        <span class="flex flex-wrap gap-1 px-1.5 py-0.5">
                                            <template x-if="field(peek.source, 'editor') && field(peek.source, 'editor').type === 'multi_select'">
                                                <template x-for="o in optionsWith(peek.source, 'editor', null)" :key="o.name">
                                                    <button type="button" :disabled="!canEdit" @click="toggleEditor(peek, o.name)"
                                                            class="rounded-md px-2 py-0.5 text-[12px] font-medium transition"
                                                            :class="(peek.editor || '').split(',').map((s) => s.trim()).includes(o.name) ? '' : 'opacity-35 hover:opacity-80'"
                                                            :style="`background:${hue(o.color)}26;color:${hue(o.color)}`" x-text="o.name"></button>
                                                </template>
                                            </template>
                                            <template x-if="field(peek.source, 'editor') && field(peek.source, 'editor').type === 'select'">
                                                <select class="{{ $propInput }} -mx-1.5 py-0 pr-8" :disabled="!canEdit" aria-label="Editor" @change="save(peek.id, { editor: $event.target.value || null })">
                                                    <option value="" :selected="!peek.editor" class="bg-[#15303f]">Empty</option>
                                                    <template x-for="o in optionsWith(peek.source, 'editor', peek.editor)" :key="o.name">
                                                        <option :value="o.name" :selected="o.name === peek.editor" x-text="o.name" class="bg-[#15303f]"></option>
                                                    </template>
                                                </select>
                                            </template>
                                            <template x-if="field(peek.source, 'editor') && !['select', 'multi_select'].includes(field(peek.source, 'editor').type)">
                                                <input type="text" :value="peek.editor" :disabled="!canEdit" aria-label="Editor" placeholder="Empty"
                                                       @keydown.enter.prevent="$el.blur()" @blur="save(peek.id, { editor: $el.value.trim() || null })" class="{{ $propInput }}">
                                            </template>
                                        </span>
                                    </div>

                                    <template x-for="f in [['tier', 'Tier'], ['ssd', 'Drive'], ['post_type', 'Post type']]" :key="f[0]">
                                        <div class="{{ $propRow }}" x-show="!!field(peek.source, f[0])">
                                            <span class="text-brand-100/50" x-text="f[1]"></span>
                                            <select class="{{ $propInput }} py-0 pr-8" :disabled="!canEdit" :aria-label="f[1]" @change="save(peek.id, { [f[0]]: $event.target.value || null })">
                                                <option value="" :selected="!peek[f[0]]" class="bg-[#15303f]">Empty</option>
                                                <template x-for="o in optionsWith(peek.source, f[0], peek[f[0]])" :key="o.name">
                                                    <option :value="o.name" :selected="o.name === peek[f[0]]" x-text="o.name" class="bg-[#15303f]"></option>
                                                </template>
                                            </select>
                                        </div>
                                    </template>

                                    <div class="{{ $propRow }}" x-show="!!field(peek.source, 'effort_hours')">
                                        <span class="text-brand-100/50">Effort hours</span>
                                        <input type="text" :value="peek.effort_hours" :disabled="!canEdit" aria-label="Effort hours" placeholder="Empty"
                                               @keydown.enter.prevent="$el.blur()" @blur="save(peek.id, { effort_hours: $el.value.trim() || null })" class="{{ $propInput }} w-28">
                                    </div>

                                    <div class="{{ $propRow }}">
                                        <span class="text-brand-100/50">Shoot</span>
                                        <span class="px-1.5 text-white" x-text="peek.shoot ? peek.shoot.title + (peek.shoot.date ? ' · ' + fmtDay(peek.shoot.date) : '') : '—'"></span>
                                    </div>
                                    <div class="{{ $propRow }}">
                                        <span class="text-brand-100/50">Editing now</span>
                                        <span class="px-1.5 text-white" x-text="peek.editing && peek.editing.length ? peek.editing.map((e) => ((e.user && e.user.name) || '?') + ' · ' + e.status).join(', ') : '—'"></span>
                                    </div>
                                </div>

                                {{-- Details | Timeline --}}
                                <div class="mt-5 flex gap-1 border-b border-white/[0.06]">
                                    <template x-for="t in [['details', 'Script'], ['caption', 'Caption'], ['timeline', 'Timeline']]" :key="t[0]">
                                        <button type="button" @click="t[0] === 'caption' ? openCaption() : (peekTab = t[0])" class="-mb-px border-b-2 px-3 py-2 text-[13px] font-semibold transition"
                                                :class="peekTab === t[0] ? 'border-brand-400 text-white' : 'border-transparent text-brand-100/50 hover:text-white'">
                                            <span x-text="t[1]"></span>
                                            <span x-show="t[0] === 'timeline' && peek.timeline && peek.timeline.length > 0" class="ml-1 text-xs font-normal text-brand-100/40" x-text="peek.timeline ? peek.timeline.length : ''"></span>
                                        </button>
                                    </template>
                                </div>

                                <div x-show="peekTab === 'details'" class="pt-3">
                                    <p x-show="!!peek.loading" class="text-xs text-brand-100/40">Loading…</p>
                                    <template x-if="peek.portal_script">
                                        <p class="mb-2 text-[13px]">
                                            <a :href="peek.portal_script.url" class="font-semibold text-brand-300 hover:text-white" x-text="peek.portal_script.title"></a>
                                            <span class="text-xs text-brand-100/60" x-text="' · ' + peek.portal_script.status + (peek.script_shot ? ' · shot' : '')"></span>
                                        </p>
                                    </template>
                                    <template x-if="peek.portal_script_text && peek.portal_script_text.length">
                                        <div class="space-y-2.5 rounded-xl bg-[#0f2230] p-3 text-[13px] leading-relaxed text-brand-100/85">
                                            <template x-for="(p, n) in peek.portal_script_text" :key="n">
                                                <div>
                                                    <p x-show="!!p.heading" class="text-[11px] font-semibold uppercase tracking-wide text-brand-300" x-text="p.heading"></p>
                                                    <p class="whitespace-pre-line" x-text="p.text"></p>
                                                </div>
                                            </template>
                                        </div>
                                    </template>
                                    <template x-if="!peek.loading && !(peek.portal_script_text && peek.portal_script_text.length) && !!field(peek.source, 'script')">
                                        <textarea :value="peek.script" :disabled="!canEdit" rows="10" aria-label="Script" placeholder="Write the script here. It is saved to Notion when you click away."
                                                  @blur="save(peek.id, { script: $el.value.trim() || null })"
                                                  class="w-full rounded-xl border-0 bg-[#0f2230] p-3 text-[13px] leading-relaxed text-brand-100/90 placeholder:text-brand-100/30 focus:ring-1 focus:ring-brand-400"></textarea>
                                    </template>
                                    <template x-if="!peek.loading && !(peek.portal_script_text && peek.portal_script_text.length) && !field(peek.source, 'script')">
                                        <p class="whitespace-pre-line text-[13px] text-brand-100/60" x-text="peek.script || 'No script.'"></p>
                                    </template>
                                </div>

                                {{-- The video's whole story: shoot, editing, planner changes, post. --}}
                                {{-- Caption: written by Gemini in the studio's format, kept here. --}}
                                <div x-show="peekTab === 'caption'" x-cloak class="space-y-3 pt-3">
                                    <template x-if="canEdit">
                                        <div class="space-y-2 rounded-xl bg-[#0f2230] p-3">
                                            <textarea x-model="cap.note" rows="2" maxlength="1000"
                                                      placeholder="Note for the caption (optional): the offer, the occasion, who it is for, words to use or avoid…"
                                                      class="w-full rounded-lg border-0 bg-white/[0.04] p-2.5 text-[13px] text-white placeholder:text-brand-100/35 focus:ring-1 focus:ring-brand-400"></textarea>
                                            <div class="flex flex-wrap items-center gap-2">
                                                <select x-model="cap.language" class="h-9 rounded-lg border-0 bg-white/[0.06] py-0 pl-2.5 pr-8 text-[13px] text-white focus:ring-1 focus:ring-brand-400" aria-label="Caption language">
                                                    <option value="english" class="bg-[#15303f]">English</option>
                                                    <option value="tanglish" class="bg-[#15303f]">Tanglish</option>
                                                    <option value="tamil" class="bg-[#15303f]">Tamil + English</option>
                                                </select>
                                                <button type="button" @click="writeCaptions(!!(peek.caption_options && peek.caption_options.length))" :disabled="cap.busy || (peek.caption_budget && peek.caption_budget.blocked)"
                                                        class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-brand-400 px-3.5 text-[13px] font-bold text-brand-900 hover:bg-brand-300 disabled:opacity-50">
                                                    <span x-show="cap.busy" class="h-3 w-3 animate-spin rounded-full border-2 border-brand-900/30 border-t-brand-900"></span>
                                                    <span x-text="cap.busy ? 'Writing…' : (peek.caption_options && peek.caption_options.length ? 'Write new options' : 'Write caption')"></span>
                                                </button>
                                                <span class="ml-auto text-[11px] text-brand-100/45" x-show="!!peek.caption_budget"
                                                      x-text="peek.caption_budget ? ('Gemini: $' + peek.caption_budget.spent.toFixed(2) + ' of $' + Number(peek.caption_budget.cap).toFixed(2) + ' this month') : ''"></span>
                                            </div>
                                            <p x-show="peek.caption_budget && peek.caption_budget.blocked" class="text-xs text-red-300">This month's Gemini limit is used up. It resets on the 1st, or an admin can raise it under AI settings.</p>
                                            <p x-show="!peek.caption_handle" class="text-xs text-amber-200/80">No Instagram handle is connected for this client, so the @username line is left out.</p>
                                            <p x-show="cap.cost !== null" class="text-[11px] text-brand-100/45"
                                               x-text="cap.reused ? 'Same ask as last time: shown again, no new cost.' : ('This cost about $' + Number(cap.cost).toFixed(4) + '.')"></p>
                                        </div>
                                    </template>

                                    {{-- The options --}}
                                    <template x-for="(o, n) in (peek.caption_options || [])" :key="n">
                                        <div class="rounded-xl border border-white/[0.06] bg-[#0f2230]/60 p-3">
                                            <div class="mb-1.5 flex items-center gap-2">
                                                <span class="text-[11px] font-semibold uppercase tracking-wider text-brand-300" x-text="'Option ' + (n + 1) + (o.angle ? ' · ' + o.angle : '')"></span>
                                                <span class="text-[11px] text-brand-100/40" x-text="o.text.length + ' chars · ' + hashtags(o.text) + ' hashtags'"></span>
                                                <button type="button" x-show="canEdit" @click="useOption(o)" class="ml-auto rounded-md bg-white/10 px-2.5 py-1 text-[12px] font-semibold text-white hover:bg-white/15">Use this</button>
                                            </div>
                                            <p class="whitespace-pre-line text-[13px] leading-relaxed text-brand-100/85" x-text="o.text"></p>
                                        </div>
                                    </template>

                                    {{-- The one kept --}}
                                    <div class="rounded-xl border border-brand-400/25 bg-brand-400/[0.05] p-3">
                                        <div class="mb-1.5 flex items-center gap-2">
                                            <span class="text-[11px] font-semibold uppercase tracking-wider text-brand-200">Caption for this video</span>
                                            <span class="text-[11px]" :class="hashtags(cap.text) > 5 ? 'text-red-300' : 'text-brand-100/40'" x-text="cap.text.length + ' / 2200 · ' + hashtags(cap.text) + ' hashtags'"></span>
                                            <button type="button" x-show="!!cap.text" @click="copyCaption(cap.text)" class="ml-auto rounded-md bg-white/10 px-2.5 py-1 text-[12px] font-semibold text-white hover:bg-white/15" x-text="cap.copied ? 'Copied' : 'Copy'"></button>
                                        </div>
                                        <textarea x-model="cap.text" @input="cap.dirty = cap.text !== cap.saved" :disabled="!canEdit" rows="12" maxlength="2200"
                                                  placeholder="Write one, or pick an option above."
                                                  class="w-full rounded-lg border-0 bg-[#0f2230] p-2.5 text-[13px] leading-relaxed text-white placeholder:text-brand-100/30 focus:ring-1 focus:ring-brand-400"></textarea>
                                        <div class="mt-2 flex items-center gap-2" x-show="canEdit">
                                            <p x-show="hashtags(cap.text) > 5" class="text-xs text-red-300">More than 5 hashtags. The studio's rule is 5 at most.</p>
                                            <button type="button" @click="saveCaption()" :disabled="!cap.dirty || hashtags(cap.text) > 5"
                                                    class="ml-auto inline-flex h-9 items-center rounded-lg bg-brand-400 px-4 text-[13px] font-bold text-brand-900 hover:bg-brand-300 disabled:opacity-40">Save caption</button>
                                        </div>
                                    </div>
                                </div>

                                <div x-show="peekTab === 'timeline'" class="pt-4">
                                    <p x-show="!peek.timeline || peek.timeline.length === 0" class="text-[13px] text-brand-100/50">Nothing recorded for this video yet.</p>
                                    <ol>
                                        <template x-for="(e, n) in (peek.timeline || [])" :key="n">
                                            <li class="relative flex gap-3 pb-4 last:pb-0">
                                                <span x-show="n < peek.timeline.length - 1" class="absolute left-[6px] top-4 h-full w-px bg-white/10"></span>
                                                <span class="relative mt-1 h-[13px] w-[13px] shrink-0 rounded-full bg-[#132a38] ring-2"
                                                      :class="{ plan: 'ring-sky-300', script: 'ring-amber-200', shoot: 'ring-red-300', edit: 'ring-violet-300', post: 'ring-emerald-300' }[e.stage] || 'ring-white/40'"></span>
                                                <div class="min-w-0 flex-1">
                                                    <p class="flex flex-wrap items-baseline gap-x-2 text-[13px]">
                                                        <span class="font-medium text-white" x-text="e.label"></span>
                                                        <span class="text-xs tabular-nums text-brand-100/60" x-text="fmtAt(e.at)"></span>
                                                        <span x-show="!!e.by" class="text-xs text-brand-100/45" x-text="e.by"></span>
                                                        <a x-show="!!e.url" :href="e.url" target="_blank" rel="noopener" class="text-xs text-sky-300 underline underline-offset-4">Open</a>
                                                    </p>
                                                    <p x-show="!!e.note" class="mt-0.5 break-words text-xs text-brand-100/55" x-text="e.note"></p>
                                                </div>
                                            </li>
                                        </template>
                                    </ol>
                                    <a x-show="!!peek.timeline_url" :href="peek.timeline_url" class="mt-4 inline-block text-xs font-semibold text-brand-300 hover:text-white">Open the full timeline page →</a>
                                </div>
                            </div>
                        </div>
                    </template>
                </aside>
            </div>
        </template>

        {{-- ——— New video ——— --}}
        <template x-teleport="body">
            <div x-show="!!draft" x-cloak class="fixed inset-0 z-[70] flex items-start justify-center p-4 pt-[14vh]" role="dialog" aria-modal="true" aria-label="New video">
                <div class="absolute inset-0 bg-black/50" x-show="!!draft" x-transition.opacity @click="draft = null"></div>
                <template x-if="draft">
                    <form @submit.prevent="create()" x-show="!!draft" x-transition.scale.origin.top.duration.150ms
                          class="theme-dark relative w-full max-w-lg rounded-2xl border border-white/10 bg-[#15303f] p-5 text-white shadow-2xl">
                        <input type="text" x-ref="newTitle" x-model="draft.title" required maxlength="300" placeholder="Untitled video"
                               class="w-full border-0 bg-transparent p-0 text-[22px] font-bold text-white placeholder:text-brand-100/30 focus:ring-0">
                        <div class="mt-4 space-y-1 text-[13px]">
                            <div class="{{ $propRow }}">
                                <span class="text-brand-100/50">Planner</span>
                                <span class="flex flex-wrap gap-1">
                                    <template x-for="s in meta.sources" :key="s.key">
                                        <button type="button" @click="draft.source = s.key; draft.status = ''"
                                                class="rounded-md px-2.5 py-1 text-[12.5px] font-semibold ring-1 transition"
                                                :class="draft.source === s.key ? 'bg-white text-brand-900 ring-white' : 'text-brand-100/70 ring-white/15 hover:text-white'" x-text="s.label"></button>
                                    </template>
                                </span>
                            </div>
                            <div class="{{ $propRow }}">
                                <span class="text-brand-100/50">Publish date</span>
                                <input type="date" x-model="draft.published_date" class="{{ $propInput }} w-[150px] [color-scheme:dark]">
                            </div>
                            <div class="{{ $propRow }}">
                                <span class="text-brand-100/50">Status</span>
                                <select x-model="draft.status" class="{{ $propInput }} py-0 pr-8">
                                    <option value="" class="bg-[#15303f]">Notion's default</option>
                                    <template x-for="o in options(draft.source, 'status')" :key="o.name">
                                        <option :value="o.name" x-text="o.name" class="bg-[#15303f]"></option>
                                    </template>
                                </select>
                            </div>
                            <div class="{{ $propRow }}" x-show="!!field(draft.source, 'client')">
                                <span class="text-brand-100/50">Client</span>
                                <select x-model="draft.client" class="{{ $propInput }} py-0 pr-8">
                                    <option value="" class="bg-[#15303f]">Empty</option>
                                    <template x-for="o in optionsWith(draft.source, 'client', draft.client)" :key="o.name">
                                        <option :value="o.name" x-text="o.name" class="bg-[#15303f]"></option>
                                    </template>
                                </select>
                            </div>
                        </div>
                        <div class="mt-5 flex items-center gap-3">
                            <button type="submit" :disabled="creating || !draft.title.trim()"
                                    class="inline-flex h-10 items-center rounded-xl bg-brand-400 px-4 text-sm font-bold text-brand-900 hover:bg-brand-300 disabled:opacity-50"
                                    x-text="creating ? 'Creating in Notion…' : 'Create'"></button>
                            <button type="button" @click="draft = null" class="text-sm text-brand-100/60 hover:text-white">Cancel</button>
                            <span class="ml-auto text-xs text-brand-100/35">Created in Notion first</span>
                        </div>
                    </form>
                </template>
            </div>
        </template>

        {{-- What just happened, out of the way. --}}
        <template x-teleport="body">
            <div x-show="!!toast" x-cloak x-transition.opacity.duration.200ms
                 class="fixed bottom-5 left-1/2 z-[80] max-w-[90vw] -translate-x-1/2 rounded-xl px-4 py-2.5 text-sm font-medium shadow-2xl"
                 :class="toast && toast.bad ? 'bg-red-500 text-white' : 'bg-white text-brand-900'" role="status" @click="toast = null"
                 x-text="toast ? toast.text : ''"></div>
        </template>
    </div>
</x-app-layout>
