{{--
    The top of Editor Updates, today only, live from data.board
    (App\Support\EditorBoard, re-read every 15 seconds): one line per
    editor with their photo -- what they are on, how the day went, what is
    next -- then what needs you beside what just happened.
--}}
<template x-if="data.board">
    <div class="space-y-6">
        {{-- ═══ The team, one line each ═══ --}}
        <section class="overflow-hidden rounded-[20px] border border-white/[0.08] bg-[#15303f]">
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 border-b border-white/[0.06] px-5 py-3.5">
                <h2 class="text-[15px] font-bold text-white">The team</h2>
                <p class="text-[13px] text-brand-100/60">
                    <span class="text-amber-300" x-text="data.board.flow.editing + ' editing'"></span> ·
                    <span x-text="data.board.flow.review + ' in review'"></span> ·
                    <span x-text="data.board.flow.ready + ' ready'"></span> ·
                    <span class="text-emerald-300" x-text="data.board.flow.out + ' out today'"></span>
                </p>
            </div>
            <p x-show="!data.board.editors.length" class="px-6 py-8 text-center text-sm text-brand-100/50">Nobody on the team has used My Editing in the last two weeks.</p>
            <template x-for="ed in data.board.editors" :key="ed.person">
                <a :href="ed.user_id ? editorUrl.replace('__ID__', ed.user_id) : '#'"
                   class="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-4 gap-y-3 border-b border-white/[0.05] px-5 py-4 last:border-b-0 hover:bg-white/[0.03] md:grid-cols-[auto_minmax(0,1.3fr)_auto_minmax(0,1fr)]">
                    {{-- Photo with a ring for their state --}}
                    <span class="relative shrink-0" :class="'et-' + ed.state">
                        <template x-if="ed.photo">
                            <img :src="ed.photo" :alt="ed.person" class="et-av h-14 w-14 object-cover" :style="`box-shadow:0 0 0 3px #15303f,0 0 0 5px ${look(ed).dot}`">
                        </template>
                        <template x-if="!ed.photo">
                            <span class="et-av h-14 w-14 text-xl" :style="`background:hsl(${ed.hue} 70% 58%);box-shadow:0 0 0 3px #15303f,0 0 0 5px ${look(ed).dot}`" x-text="ed.initial"></span>
                        </template>
                    </span>

                    {{-- Who, and what they are on --}}
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="text-base font-bold text-white" x-text="ed.person"></p>
                            <span class="inline-flex h-6 items-center gap-1.5 rounded-full px-2.5 text-xs font-semibold" :class="look(ed).pill">
                                <span x-text="look(ed).word"></span><span class="et-dots" x-show="ed.state === 'working'"><i></i><i></i><i></i></span>
                            </span>
                        </div>
                        <p class="mt-1 truncate text-sm" :class="ed.state === 'working' ? 'text-white' : 'text-brand-100/60'">
                            <template x-if="ed.state === 'working'">
                                <span><span x-text="ed.title"></span> · <span class="font-semibold tabular-nums text-amber-300" x-text="span(runFor(ed.started_at))"></span></span>
                            </template>
                            <template x-if="ed.state !== 'working'">
                                <span x-text="ed.holding.length ? '⏸ ' + ed.holding[0].title + ' · ' + ed.holding[0].for + (ed.holding[0].note ? ' · “' + ed.holding[0].note + '”' : '') : (ed.last ? ed.last.time + ' · ' + ed.last.label + ' · ' + ed.last.title : 'No moves yet today')"></span>
                            </template>
                        </p>
                    </div>

                    {{-- Their day in numbers --}}
                    <div class="col-span-2 flex gap-1.5 md:col-span-1">
                        <span class="flex min-w-[58px] flex-col items-center rounded-[10px] bg-[#1a3a4b] px-2 py-1.5"><b class="text-lg leading-tight text-sky-300" x-text="ed.done"></b><span class="text-[10.5px] text-brand-100/60">done</span></span>
                        <span class="flex min-w-[58px] flex-col items-center rounded-[10px] bg-[#1a3a4b] px-2 py-1.5"><b class="text-lg leading-tight text-emerald-300" x-text="ed.out"></b><span class="text-[10.5px] text-brand-100/60">out</span></span>
                        <span class="flex min-w-[58px] flex-col items-center rounded-[10px] bg-[#1a3a4b] px-2 py-1.5"><b class="text-lg leading-tight" :class="ed.fixes ? 'text-orange-300' : 'text-white'" x-text="ed.fixes"></b><span class="text-[10.5px] text-brand-100/60">fixes</span></span>
                        <span class="flex min-w-[58px] flex-col items-center rounded-[10px] bg-[#1a3a4b] px-2 py-1.5"><b class="text-lg leading-tight text-white" x-text="span(laneOf(ed.person)?.minutes ?? 0) || '0m'"></b><span class="text-[10.5px] text-brand-100/60">editing</span></span>
                    </div>

                    {{-- What is next for them --}}
                    <div class="col-span-2 min-w-0 md:col-span-1">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-100/45" x-text="'Up next' + (ed.waiting ? ' · ' + ed.waiting : '')"></p>
                        <template x-for="t in ed.next" :key="t">
                            <p class="truncate text-[13px] text-brand-100/85" x-text="t"></p>
                        </template>
                        <p x-show="!ed.next.length" class="text-[13px] text-brand-100/45">Nothing on today's board</p>
                    </div>
                </a>
            </template>
        </section>

        {{-- ═══ Needs you · Just now ═══ --}}
        <section class="grid gap-5 lg:grid-cols-2">
            <div class="overflow-hidden rounded-[20px] border bg-[#15303f]" :class="data.board.needs.length ? 'border-amber-400/35' : 'border-emerald-400/30'">
                <h2 class="flex items-center gap-2 border-b border-white/[0.06] px-5 py-3.5 text-[15px] font-bold" :class="data.board.needs.length ? 'text-amber-300' : 'text-emerald-300'">
                    <span x-text="data.board.needs.length ? 'Needs you' : 'All clear'"></span>
                    <span x-show="data.board.needs.length" class="rounded-full bg-amber-400 px-2 text-[12px] text-[#0f2230]" x-text="data.board.needs.length"></span>
                </h2>
                <p x-show="!data.board.needs.length" class="px-5 py-6 text-sm text-emerald-100/80">No reviews waiting, nothing late, nobody stuck.</p>
                <template x-for="(n, i) in data.board.needs" :key="n.kind + i">
                    <div class="flex items-center gap-3 border-b border-white/[0.05] px-5 py-3 last:border-b-0">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-base font-bold" :class="needLook(n).chip" x-text="needLook(n).icon"></span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold" :class="needLook(n).text" x-text="needTitle(n)"></p>
                            <p class="truncate text-xs text-brand-100/60" x-text="needLine(n)"></p>
                        </div>
                        <a x-show="needHref(n)" :href="needHref(n)" class="inline-flex h-9 shrink-0 items-center rounded-lg px-3 text-[13px] font-semibold"
                           :class="['late', 'review'].includes(n.kind) ? 'bg-brand-400 text-brand-900 hover:bg-brand-300' : 'bg-[#1a3a4b] text-white hover:bg-white/15'"
                           x-text="needCta(n)"></a>
                    </div>
                </template>
                <div x-show="data.board.next.length" class="border-t border-white/[0.06] px-5 py-3 text-[13px] text-brand-100/70">
                    <span class="font-semibold text-violet-300">Publishing next:</span>
                    <template x-for="n in data.board.next" :key="n.title">
                        <span class="block truncate text-white" x-text="n.at + ' · ' + n.title + ' (in ' + n.in + ')'"></span>
                    </template>
                </div>
            </div>

            <div class="overflow-hidden rounded-[20px] border border-white/[0.08] bg-[#15303f]">
                <h2 class="border-b border-white/[0.06] px-5 py-3.5 text-[15px] font-bold text-white">Just now</h2>
                <p x-show="!data.board.recent.length" class="px-5 py-6 text-sm text-brand-100/50">No moves yet today.</p>
                <template x-for="(m, i) in data.board.recent" :key="m.time + m.title + i">
                    <div class="flex items-center gap-3 border-b border-white/[0.05] px-5 py-2.5 last:border-b-0">
                        <template x-if="photoOf(m.person)">
                            <img :src="photoOf(m.person)" :alt="m.person" class="h-8 w-8 shrink-0 rounded-full object-cover">
                        </template>
                        <template x-if="!photoOf(m.person)">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-bold text-brand-900" :style="`background:hsl(${hueOf(m.person)} 70% 58%)`" x-text="(m.person || '?').slice(0, 1)"></span>
                        </template>
                        <p class="min-w-0 flex-1 truncate text-[13px] text-brand-100/80">
                            <span class="font-semibold text-white" x-text="m.person"></span>
                            <span x-text="' ' + icon(m) + ' ' + m.label.toLowerCase()"></span>
                            <span class="text-white" x-text="' · ' + m.title"></span>
                        </p>
                        <span class="shrink-0 text-xs tabular-nums text-brand-100/45" x-text="m.time"></span>
                    </div>
                </template>
            </div>
        </section>
    </div>
</template>
