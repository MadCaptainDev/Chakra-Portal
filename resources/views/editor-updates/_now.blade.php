{{--
    The top of Editor Updates, as designed (canvas "Editor Updates redesign"):
    Needs you beside Today's flow, then a card per editor, right now. Today
    only, live: drawn from data.board (App\Support\EditorBoard), which the
    page's editorTimeline component re-reads every 15 seconds.
--}}
<template x-if="data.board">
    <div class="space-y-7">
        {{-- ═══ Needs you · Today's flow ═══ --}}
        <section class="grid gap-5 lg:grid-cols-[minmax(0,1.45fr)_minmax(0,1fr)]">
            <div class="overflow-hidden rounded-[20px] border bg-[#15303f]"
                 :class="data.board.needs.length ? 'border-amber-400/35' : 'border-emerald-400/30'">
                <div class="flex items-center justify-between border-b border-white/[0.06] px-5 py-4 sm:px-6">
                    <h2 class="flex items-center gap-2.5 text-[13px] font-bold uppercase tracking-[0.14em]"
                        :class="data.board.needs.length ? 'text-amber-300' : 'text-emerald-300'">
                        <span x-text="data.board.needs.length ? 'Needs you' : 'All clear'"></span>
                        <span x-show="data.board.needs.length" class="inline-flex h-[26px] min-w-[26px] items-center justify-center rounded-full bg-amber-400 px-1.5 text-[13px] tracking-normal text-[#0f2230]" x-text="data.board.needs.length"></span>
                    </h2>
                    <span class="text-xs text-brand-100/50" x-text="failed ? 'reconnecting…' : 'Most urgent first'"></span>
                </div>
                <p x-show="!data.board.needs.length" class="px-6 py-8 text-sm text-emerald-100/80">Nothing waiting on you — no reviews, nothing late, nobody stuck.</p>
                <template x-for="(n, i) in data.board.needs" :key="n.kind + i">
                    <div class="flex items-center gap-4 border-b border-white/[0.05] px-5 py-4 last:border-b-0 sm:px-6">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-xl font-bold" :class="needLook(n).chip" x-text="needLook(n).icon"></span>
                        <div class="min-w-0 flex-1">
                            <p class="text-[15px] font-semibold sm:text-base" :class="needLook(n).text" x-text="needTitle(n)"></p>
                            <p class="mt-0.5 truncate text-[13px] text-brand-100/65" x-text="needLine(n)"></p>
                        </div>
                        <a x-show="needHref(n)" :href="needHref(n)"
                           class="inline-flex h-10 shrink-0 items-center rounded-[10px] px-4 text-sm font-semibold"
                           :class="['late', 'review'].includes(n.kind) ? 'bg-brand-400 text-brand-900 hover:bg-brand-300' : 'bg-[#1a3a4b] text-white hover:bg-white/15'"
                           x-text="needCta(n)"></a>
                    </div>
                </template>
            </div>

            <div class="flex flex-col gap-4 rounded-[20px] border border-white/[0.08] bg-[#15303f] px-5 py-5 sm:px-6">
                <h2 class="text-[13px] font-bold uppercase tracking-[0.14em] text-brand-300">Today's flow</h2>
                <div class="grid grid-cols-4 gap-2">
                    <template x-for="f in flowTiles" :key="f.label">
                        <div class="rounded-[14px] bg-[#1a3a4b] px-3 py-3">
                            <p class="text-[28px] font-extrabold leading-tight" :style="`color:${f.color}`" x-text="f.n"></p>
                            <p class="text-xs font-semibold text-brand-100/85" x-text="f.label"></p>
                            <p class="text-[11px] text-brand-100/50" x-text="f.hint"></p>
                        </div>
                    </template>
                </div>
                <div class="space-y-2">
                    <div class="flex h-3 overflow-hidden rounded-full bg-[#0f2230]">
                        <template x-for="s in flowBar" :key="s.label">
                            <span class="h-full transition-all duration-700" :style="`width:${s.pct}%;background:${s.color}`"></span>
                        </template>
                    </div>
                    <div class="flex flex-wrap gap-x-3.5 gap-y-1 text-xs text-brand-100/65">
                        <template x-for="s in flowBar" :key="'k' + s.label">
                            <span><span class="mr-1.5 inline-block h-2 w-2 rounded-sm" :style="`background:${s.color}`"></span><span x-text="s.n + ' ' + s.label"></span></span>
                        </template>
                    </div>
                </div>
                <div class="flex items-start gap-2.5 rounded-xl bg-[#1a3a4b] px-3.5 py-3 text-[13px] text-brand-100/70">
                    <span class="text-base text-violet-300">◷</span>
                    <span x-show="!data.board.next.length">Next to publish: <strong class="font-semibold text-white">nothing scheduled yet</strong></span>
                    <span x-show="data.board.next.length">Next to publish:
                        <template x-for="n in data.board.next" :key="n.title">
                            <strong class="block font-semibold text-white" x-text="n.at + ' · ' + n.title + ' (in ' + n.in + ')'"></strong>
                        </template>
                    </span>
                </div>
            </div>
        </section>

        {{-- ═══ Right now: a card per editor ═══ --}}
        <section class="space-y-3.5">
            <h2 class="text-[13px] font-bold uppercase tracking-[0.14em] text-brand-300">Right now</h2>
            <p x-show="!data.board.editors.length" class="rounded-[20px] border border-white/[0.08] bg-[#15303f] px-6 py-8 text-center text-sm text-brand-100/50">Nobody has started yet today.</p>
            <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                <template x-for="ed in data.board.editors" :key="ed.person">
                    <a :href="ed.user_id ? editorUrl.replace('__ID__', ed.user_id) : '#'"
                       class="flex flex-col gap-4 rounded-[20px] border bg-[#15303f] p-5 transition hover:bg-[#183747]"
                       :class="look(ed).ring">
                        <div class="flex items-center gap-3.5" :class="'et-' + (ed.state === 'idle' && ed.out > 0 && ed.idle !== null && ed.idle < 15 ? 'celebrate' : ed.state)">
                            <span class="et-av h-[52px] w-[52px] shrink-0 text-xl" :style="`background:hsl(${ed.hue} 70% 58%);box-shadow:0 0 0 3px #15303f,0 0 0 5px ${look(ed).dot}`" x-text="ed.initial"></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-lg font-bold text-white" x-text="ed.person"></p>
                                <span class="mt-1 inline-flex h-6 items-center gap-1.5 rounded-full px-2.5 text-xs font-semibold" :class="look(ed).pill">
                                    <span class="h-1.5 w-1.5 rounded-full" :style="`background:${look(ed).dot}`"></span>
                                    <span x-text="look(ed).word"></span><span class="et-dots" x-show="ed.state === 'working'"><i></i><i></i><i></i></span>
                                </span>
                            </div>
                        </div>

                        <div class="flex min-h-[72px] flex-col gap-1.5 rounded-[14px] px-3.5 py-3" :class="ed.state === 'working' ? 'bg-amber-400/10' : 'bg-[#1a3a4b]'">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.12em]" :class="ed.state === 'working' ? 'text-amber-200' : 'text-brand-100/50'"
                               x-text="ed.state === 'working' ? 'Now editing' : (ed.last ? 'Last move · ' + ed.last.time : 'On the board')"></p>
                            <div class="flex items-baseline justify-between gap-2.5">
                                <p class="text-[15px] font-semibold leading-snug text-white"
                                   x-text="ed.state === 'working' ? ed.title : (ed.last ? ed.last.label + ' · ' + ed.last.title : (ed.holding.length ? ed.holding.length + ' on hold' : 'Nothing yet'))"></p>
                                <span class="shrink-0 text-[15px] font-bold tabular-nums text-amber-300" x-show="ed.state === 'working'" x-text="span(runFor(ed.started_at))"></span>
                            </div>
                        </div>

                        <div class="space-y-2" x-show="ed.holding.length">
                            <p class="text-xs font-semibold text-violet-300" x-text="'On hold · ' + ed.holding.length"></p>
                            <template x-for="h in ed.holding.slice(0, 3)" :key="h.title">
                                <div class="flex gap-2.5 text-[13px]">
                                    <span class="text-violet-400">⏸</span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-brand-100/90" x-text="h.title"></span>
                                        <span class="block truncate text-xs text-brand-100/50" x-text="h.for + (h.note ? ' · “' + h.note + '”' : '')"></span>
                                    </span>
                                </div>
                            </template>
                        </div>

                        <div class="mt-auto space-y-2.5">
                            <div class="relative h-2.5 overflow-hidden rounded-full bg-[#0f2230]">
                                <template x-for="b in laneOf(ed.person)?.blocks ?? []" :key="b.id">
                                    <span class="absolute inset-y-0" :class="b.type === 'work' ? '' : 'et-hold-bar'"
                                          :style="`left:${x(b.from)}%;width:${width(b)}%;${b.type === 'work' ? 'background:#F4B740' : ''}`"></span>
                                </template>
                                <span class="absolute inset-y-0 w-0.5 bg-red-400" :style="`left:${nowX}%`" x-show="nowX !== null"></span>
                            </div>
                            <div class="flex justify-between text-[11px] text-brand-100/40">
                                <span x-text="hours[0]?.label"></span><span x-text="hours[Math.floor(hours.length / 2)]?.label"></span><span x-text="hours[hours.length - 1]?.label"></span>
                            </div>
                            <div class="grid grid-cols-3 gap-2 text-center">
                                <div class="rounded-[10px] bg-[#1a3a4b] px-1 py-2"><p class="text-xl font-extrabold text-sky-300" x-text="ed.done"></p><p class="text-[11px] text-brand-100/65">done</p></div>
                                <div class="rounded-[10px] bg-[#1a3a4b] px-1 py-2"><p class="text-xl font-extrabold text-emerald-300" x-text="ed.out"></p><p class="text-[11px] text-brand-100/65">out</p></div>
                                <div class="rounded-[10px] bg-[#1a3a4b] px-1 py-2"><p class="text-xl font-extrabold text-white" x-text="span(laneOf(ed.person)?.minutes ?? 0) || '0m'"></p><p class="text-[11px] text-brand-100/65">editing</p></div>
                            </div>
                        </div>
                    </a>
                </template>
            </div>
        </section>
    </div>
</template>
