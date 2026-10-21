{{--
    Chakra Planner, web version: a month of the Reel, Post, Story and
    YouTube planners from Notion. Drag a video to another day to move its
    publish date (written to Notion first). Drawn by resources/js/planner-web.js.
--}}
<x-app-layout title="Chakra Planner">
    <div class="mx-auto max-w-[1400px] space-y-4 pb-16"
         x-data="plannerWeb(@js([
             'meta' => route('planner.data.meta'),
             'items' => route('planner.data.items'),
             'item' => route('planner.data.item', ['item' => '__ID__']),
             'move' => route('planner.data.move', ['item' => '__ID__']),
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
                <button type="button" @click="shift(-1)" class="flex h-9 w-9 items-center justify-center rounded-lg text-lg text-brand-100/70 hover:bg-white/10 hover:text-white" aria-label="Previous month">‹</button>
                <button type="button" @click="thisMonth()" class="h-9 rounded-lg px-3 text-sm font-semibold text-white hover:bg-white/10">Today</button>
                <button type="button" @click="shift(1)" class="flex h-9 w-9 items-center justify-center rounded-lg text-lg text-brand-100/70 hover:bg-white/10 hover:text-white" aria-label="Next month">›</button>
            </nav>
            {{-- The same month, three ways (Content Board / Dashboard / Planner in one). --}}
            <div class="inline-flex h-11 items-center gap-1 rounded-xl border border-white/[0.08] bg-[#15303f] p-1" role="group" aria-label="View">
                <template x-for="v in [['calendar', 'Calendar'], ['board', 'Board'], ['table', 'Table']]" :key="v[0]">
                    <button type="button" @click="setView(v[0])" :aria-pressed="view === v[0] ? 'true' : 'false'"
                            class="h-9 rounded-lg px-3 text-sm font-semibold"
                            :class="view === v[0] ? 'bg-white text-brand-900' : 'text-brand-100/70 hover:text-white'" x-text="v[1]"></button>
                </template>
            </div>
            <button type="button" @click="refresh()" :disabled="refreshing"
                    class="inline-flex h-11 items-center rounded-xl bg-brand-400 px-4 text-sm font-bold text-brand-900 hover:bg-brand-300 disabled:opacity-50"
                    x-text="refreshing ? 'Refreshing…' : 'Refresh from Notion'"></button>
            @can('planner.manage')
                <a href="{{ route('planner.setup') }}" class="text-sm text-brand-300 hover:text-white">Desktop app</a>
            @endcan
        </header>

        {{-- Filters --}}
        <div class="flex flex-wrap items-center gap-2">
            <template x-for="s in meta.sources" :key="s.key">
                <button type="button" @click="toggleSource(s.key)"
                        class="h-9 rounded-full px-3.5 text-[13px] font-semibold ring-1"
                        :class="sources.includes(s.key) ? 'bg-brand-400/20 text-white ring-brand-400/60' : 'text-brand-100/70 ring-white/15 hover:text-white'"
                        x-text="s.label"></button>
            </template>
            <select x-model="client" class="h-9 rounded-full border-0 bg-[#15303f] py-0 pl-3.5 pr-8 text-[13px] text-white ring-1 ring-white/15 focus:ring-brand-400" aria-label="Client">
                <option value="">All clients</option>
                <template x-for="c in meta.clients" :key="c.name">
                    <option :value="c.name" x-text="c.name"></option>
                </template>
            </select>
            <input type="search" x-model.debounce.250ms="q" placeholder="Search titles"
                   class="h-9 w-56 rounded-full border-0 bg-[#15303f] px-3.5 text-[13px] text-white ring-1 ring-white/15 placeholder:text-brand-100/40 focus:ring-brand-400">
            <p class="ml-auto text-xs text-brand-100/50" x-show="canEdit">Drag a video to another day to move its publish date.</p>
        </div>

        <p x-show="note" x-cloak x-text="note" class="rounded-lg bg-white/[0.06] px-3 py-2 text-sm text-brand-100/90" role="status"></p>

        <div class="flex flex-col gap-4 xl:flex-row">
            {{-- Board: the month's videos by stage --}}
            <div class="min-w-0 flex-1 overflow-x-auto" x-show="view === 'board'" x-cloak>
                <div class="grid min-w-[1080px] grid-cols-6 gap-3" :class="loading && 'opacity-60'">
                    <template x-for="col in board" :key="col.name">
                        <div class="flex flex-col gap-2 rounded-xl bg-[#0f2230] p-2">
                            <div class="flex items-center gap-2 px-1.5 pt-1">
                                <span class="h-2 w-2 rounded-full" :style="`background:${col.color}`"></span>
                                <span class="text-[13px] font-semibold text-white" x-text="col.name"></span>
                                <span class="text-xs text-brand-100/50" x-text="col.items.length"></span>
                            </div>
                            <template x-for="i in col.items.slice(0, 40)" :key="i.id">
                                <button type="button" @click="show(i)" class="rounded-lg bg-[#1a3a4b] p-2.5 text-left ring-1 ring-white/[0.06] hover:ring-brand-400/40">
                                    <span class="block text-[12.5px] font-medium leading-snug text-white"><span x-text="i.title"></span><span x-show="i.has_script" class="ml-1 text-[10px]" title="Has a script">📝</span></span>
                                    <span class="mt-1.5 flex flex-wrap gap-1 text-[10.5px]">
                                        <span class="rounded-full bg-brand-400/15 px-1.5 py-0.5 text-brand-200" x-text="i.client || '—'"></span>
                                        <span class="rounded-full bg-white/[0.07] px-1.5 py-0.5 text-brand-100/75" x-text="i.published_date ? fmtDay(i.published_date) : 'No date'"></span>
                                        <span class="rounded-full bg-white/[0.07] px-1.5 py-0.5 text-brand-100/75" x-text="label(i.source)"></span>
                                    </span>
                                </button>
                            </template>
                            <p x-show="col.items.length > 40" class="px-1.5 text-[11px] text-brand-100/50" x-text="'+' + (col.items.length - 40) + ' more'"></p>
                        </div>
                    </template>
                </div>
            </div>

            {{-- Table: the month's videos, by date --}}
            <div class="min-w-0 flex-1 overflow-x-auto rounded-xl border border-white/[0.06] bg-[#15303f]" x-show="view === 'table'" x-cloak>
                <table class="w-full min-w-[820px] text-[13px]" :class="loading && 'opacity-60'">
                    <thead class="text-left text-[11px] font-semibold uppercase tracking-wider text-brand-100/45">
                        <tr><th class="px-4 py-3">Video</th><th class="px-3 py-3">Planner</th><th class="px-3 py-3">Client</th><th class="px-3 py-3">Status</th><th class="px-3 py-3">Publish</th><th class="px-4 py-3">Editor</th></tr>
                    </thead>
                    <tbody>
                        <template x-for="i in tableRows" :key="i.id">
                            <tr class="cursor-pointer border-t border-white/[0.05] hover:bg-white/[0.03]" @click="show(i)">
                                <td class="px-4 py-2.5 text-white"><span x-text="i.title"></span><span x-show="i.has_script" class="ml-1 text-[11px]" title="Has a script">📝</span></td>
                                <td class="px-3 py-2.5 text-brand-100/70" x-text="label(i.source)"></td>
                                <td class="px-3 py-2.5 text-brand-100/70" x-text="i.client || '—'"></td>
                                <td class="px-3 py-2.5"><span class="inline-flex items-center gap-1.5 text-white"><span class="h-2 w-2 rounded-full" :style="`background:${color(i)}`"></span><span x-text="i.status"></span></span></td>
                                <td class="px-3 py-2.5 text-brand-100/70" x-text="i.published_date ? fmtDay(i.published_date) : 'No date'"></td>
                                <td class="px-4 py-2.5 text-brand-100/70" x-text="(i.editing && i.editing[0] && i.editing[0].user && i.editing[0].user.name) || i.editor || '—'"></td>
                            </tr>
                        </template>
                        <tr x-show="!tableRows.length"><td colspan="6" class="px-4 py-8 text-center text-brand-100/50">No videos this month.</td></tr>
                    </tbody>
                </table>
            </div>

            {{-- The month --}}
            <div class="min-w-0 flex-1 overflow-x-auto" x-show="view === 'calendar'">
                <div class="min-w-[860px]" :class="loading && 'opacity-60'">
                    <div class="grid grid-cols-7 gap-px pb-1.5 text-[11px] font-semibold uppercase tracking-wider text-brand-100/45">
                        @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d)
                            <span class="px-2">{{ $d }}</span>
                        @endforeach
                    </div>
                    <div class="grid grid-cols-7 gap-1.5">
                        <template x-for="d in days" :key="d.key">
                            <div class="flex min-h-[132px] flex-col gap-1 rounded-xl p-1.5 transition"
                                 :class="[d.inMonth ? 'bg-[#15303f]' : 'bg-[#15303f]/40', overDay === d.key ? 'ring-2 ring-brand-400' : '']"
                                 @dragover.prevent="overDay = d.key" @dragleave="overDay = null" @drop.prevent="drop(d.key)">
                                <span class="px-1 text-xs font-semibold"
                                      :class="d.today ? 'inline-flex h-6 w-6 items-center justify-center self-start rounded-full bg-brand-400 text-brand-900' : (d.inMonth ? 'text-brand-100/70' : 'text-brand-100/30')"
                                      x-text="d.day"></span>
                                <template x-for="i in d.items.slice(0, 6)" :key="i.id">
                                    <button type="button" @click="show(i)" :draggable="canEdit" @dragstart="drag(i)" @dragend="dragId = null"
                                            class="flex items-center gap-1.5 rounded-md bg-white/[0.05] px-1.5 py-1 text-left text-[11.5px] leading-tight text-white hover:bg-white/10"
                                            :title="label(i.source) + ' · ' + i.status + ' · ' + (i.client || '')">
                                        <span class="h-2 w-2 shrink-0 rounded-full" :style="`background:${color(i)}`"></span>
                                        <span class="truncate" x-text="i.title"></span>
                                        <span x-show="i.has_script" class="ml-auto shrink-0 text-[10px] opacity-70" title="Has a script">📝</span>
                                    </button>
                                </template>
                                <span x-show="d.items.length > 6" class="px-1 text-[11px] text-brand-300" x-text="'+' + (d.items.length - 6) + ' more'"></span>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            {{-- No date yet, and the open video --}}
            <aside class="w-full shrink-0 space-y-4 xl:w-80">
                <section x-show="open" x-cloak class="rounded-2xl border border-white/10 bg-[#15303f] p-4">
                    <div class="flex items-start gap-2">
                        <h2 class="flex-1 text-[15px] font-semibold leading-snug text-white" x-text="open?.title"></h2>
                        <button type="button" @click="open = null" class="text-brand-100/50 hover:text-white" aria-label="Close">✕</button>
                    </div>
                    <template x-if="open">
                        <dl class="mt-3 grid grid-cols-[88px_1fr] gap-x-2 gap-y-1.5 text-[13px]">
                            <dt class="text-brand-100/50">Planner</dt><dd class="text-white" x-text="label(open.source)"></dd>
                            <dt class="text-brand-100/50">Status</dt>
                            <dd class="flex items-center gap-1.5 text-white"><span class="h-2 w-2 rounded-full" :style="`background:${color(open)}`"></span><span x-text="open.status"></span></dd>
                            <dt class="text-brand-100/50">Client</dt><dd class="text-white" x-text="open.client || '—'"></dd>
                            <dt class="text-brand-100/50">Publish</dt>
                            <dd>
                                <input type="date" :value="open.published_date" :disabled="!canEdit"
                                       @change="move(open.id, $event.target.value || null)"
                                       class="h-8 rounded-lg border-0 bg-[#0f2230] px-2 text-[13px] text-white [color-scheme:dark] disabled:opacity-60">
                            </dd>
                            <dt class="text-brand-100/50">Shoot</dt><dd class="text-white" x-text="open.shoot ? open.shoot.title + (open.shoot.date ? ' · ' + open.shoot.date : '') : '—'"></dd>
                            <dt class="text-brand-100/50">Editing</dt>
                            <dd class="text-white" x-text="open.editing?.length ? open.editing.map((e) => (e.user?.name || '?') + ' · ' + e.status).join(', ') : (open.editor || '—')"></dd>
                            <dt class="text-brand-100/50">Script</dt>
                            <dd class="text-white">
                                <template x-if="open.portal_script">
                                    <span><a :href="open.portal_script.url" class="font-semibold text-brand-300 hover:text-white" x-text="open.portal_script.title"></a>
                                        <span class="block text-xs text-brand-100/60" x-text="open.portal_script.status + (open.script_shot ? ' · shot' : '')"></span></span>
                                </template>
                                <span x-show="!open.portal_script" x-text="open.has_script ? 'Written in Notion' : 'None'"></span>
                            </dd>
                        </dl>
                    </template>
                    {{-- The script itself, to read without leaving the planner. --}}
                    <template x-if="open && ((open.portal_script_text && open.portal_script_text.length) || open.script)">
                        <details class="mt-3 rounded-xl bg-[#0f2230] p-3" open>
                            <summary class="cursor-pointer text-[13px] font-semibold text-white">Read the script</summary>
                            <div class="mt-2 max-h-72 space-y-2.5 overflow-y-auto text-[13px] leading-relaxed text-brand-100/85">
                                <template x-for="(p, n) in (open.portal_script_text && open.portal_script_text.length ? open.portal_script_text : [{ heading: '', text: open.script }])" :key="n">
                                    <div>
                                        <p x-show="p.heading" class="text-[11px] font-semibold uppercase tracking-wide text-brand-300" x-text="p.heading"></p>
                                        <p class="whitespace-pre-line" x-text="p.text"></p>
                                    </div>
                                </template>
                            </div>
                        </details>
                    </template>
                    <a x-show="open?.notion_url" :href="open?.notion_url" target="_blank" rel="noopener"
                       class="mt-4 inline-flex h-9 items-center rounded-lg bg-white/10 px-3 text-[13px] font-semibold text-white hover:bg-white/15">Open in Notion ↗</a>
                </section>

                <section class="rounded-2xl border border-white/10 bg-[#15303f] p-3"
                         @dragover.prevent="overDay = 'none'" @dragleave="overDay = null" @drop.prevent="drop(null)"
                         :class="overDay === 'none' && 'ring-2 ring-brand-400'">
                    <h2 class="px-1 pb-2 text-sm font-semibold text-white">No date yet <span class="font-normal text-brand-100/50" x-text="'(' + undatedShown.length + ')'"></span></h2>
                    <div class="max-h-[520px] space-y-1 overflow-y-auto">
                        <template x-for="i in undatedShown.slice(0, 80)" :key="i.id">
                            <button type="button" @click="show(i)" :draggable="canEdit" @dragstart="drag(i)" @dragend="dragId = null"
                                    class="flex w-full items-center gap-1.5 rounded-md bg-white/[0.05] px-2 py-1.5 text-left text-[12px] text-white hover:bg-white/10">
                                <span class="h-2 w-2 shrink-0 rounded-full" :style="`background:${color(i)}`"></span>
                                <span class="truncate" x-text="i.title"></span>
                            </button>
                        </template>
                        <p x-show="undatedShown.length === 0" class="px-1 py-3 text-xs text-brand-100/45">Everything has a date.</p>
                    </div>
                </section>
            </aside>
        </div>
    </div>
</x-app-layout>
