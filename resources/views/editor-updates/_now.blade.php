{{--
    The top of Editor Updates, read first: what needs the admin, then every
    editor as a card -- what they are on now and for how long, what is on
    hold and why, their day so far -- then the day's flow in one line.
    Today only, and live: drawn from data.board (EditorBoard), which the
    page's editorTimeline component re-reads every 15 seconds.
--}}
<template x-if="data.board">
    <div class="space-y-4">
        {{-- ═══ Needs you ═══ --}}
        <section class="overflow-hidden rounded-2xl ring-1"
                 :class="data.board.needs.length ? 'bg-amber-400/[0.05] ring-amber-400/25' : 'bg-emerald-400/[0.05] ring-emerald-400/25'">
            <div class="flex items-center justify-between gap-3 px-4 py-3 sm:px-5">
                <p class="text-xs font-bold uppercase tracking-[0.16em]" :class="data.board.needs.length ? 'text-amber-200' : 'text-emerald-200'">
                    <span x-text="data.board.needs.length ? 'Needs you' : 'All clear'"></span>
                    <span class="ml-1.5 rounded-full bg-white/10 px-2 py-0.5 text-[11px] tracking-normal text-white" x-show="data.board.needs.length" x-text="data.board.needs.length"></span>
                </p>
                <p class="text-[11px] text-brand-100/50" x-text="failed ? 'reconnecting…' : 'live · ' + data.generated"></p>
            </div>
            <p class="px-4 pb-4 text-sm text-emerald-100/80 sm:px-5" x-show="!data.board.needs.length">Nothing waiting on you — no reviews, nothing late, nobody stuck.</p>
            <ul class="divide-y divide-white/5 border-t border-white/5" x-show="data.board.needs.length">
                <template x-for="(n, i) in data.board.needs" :key="n.kind + i">
                    <li class="flex items-start gap-3 px-4 py-3 sm:px-5">
                        <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-base" :class="needLook(n).chip" x-text="needLook(n).icon"></span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold" :class="needLook(n).text" x-text="needTitle(n)"></p>
                            <p class="mt-0.5 truncate text-xs text-brand-100/55" x-text="needLine(n)"></p>
                        </div>
                        <a x-show="needLook(n).href" :href="needLook(n).href"
                           class="shrink-0 rounded-lg bg-white/10 px-3 py-1.5 text-xs font-semibold text-white hover:bg-white/15" x-text="needLook(n).cta"></a>
                    </li>
                </template>
            </ul>
        </section>

        {{-- ═══ Each editor ═══ --}}
        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <template x-if="!data.board.editors.length">
                <p class="rounded-2xl bg-white/[0.03] px-4 py-6 text-center text-sm text-brand-100/50 ring-1 ring-white/10 sm:col-span-2 xl:col-span-3">Nobody has started yet today.</p>
            </template>
            <template x-for="ed in data.board.editors" :key="ed.person">
                <article class="rounded-2xl bg-white/[0.04] p-4 ring-1 transition"
                         :class="{ 'ring-amber-400/40': ed.state === 'working', 'ring-violet-400/30': ed.state === 'hold', 'ring-white/10': ed.state === 'idle' }">
                    <header class="flex items-center gap-3" :class="'et-' + (ed.state === 'idle' && ed.out > 0 && ed.idle !== null && ed.idle < 15 ? 'celebrate' : ed.state)">
                        <span class="relative shrink-0">
                            <span class="et-av h-11 w-11 text-base" :style="`background:hsl(${ed.hue} 70% 58%)`" x-text="ed.initial"></span>
                            <span class="et-z" x-show="ed.state === 'hold'">z</span>
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-white" x-text="ed.person"></p>
                            <p class="text-xs font-semibold"
                               :class="{ 'text-amber-200': ed.state === 'working', 'text-violet-200': ed.state === 'hold', 'text-brand-100/50': ed.state === 'idle' }">
                                <span x-text="ed.state === 'working' ? 'Editing' : (ed.state === 'hold' ? 'Everything on hold' : 'Idle' + (ed.idle !== null ? ' ' + span(ed.idle) : ''))"></span><span class="et-dots" x-show="ed.state === 'working'"><i></i><i></i><i></i></span>
                            </p>
                        </div>
                        <div class="text-right text-[11px] leading-tight text-brand-100/50">
                            <p><span class="text-base font-bold text-sky-200" x-text="ed.done"></span> done</p>
                            <p><span class="text-base font-bold text-emerald-200" x-text="ed.out"></span> out</p>
                        </div>
                    </header>

                    {{-- Now --}}
                    <div class="mt-3 rounded-xl bg-amber-400/10 px-3 py-2 ring-1 ring-amber-400/20" x-show="ed.state === 'working'">
                        <p class="flex items-center justify-between gap-2 text-sm">
                            <span class="truncate font-semibold text-white" x-text="ed.title"></span>
                            <span class="shrink-0 tabular-nums text-amber-200" x-text="span(runFor(ed.started_at))"></span>
                        </p>
                    </div>
                    <p class="mt-3 truncate text-xs text-brand-100/55" x-show="ed.state !== 'working' && ed.last" x-text="ed.last ? 'Last: ' + ed.last.label + ' · ' + ed.last.title + ' · ' + ed.last.time : ''"></p>

                    {{-- On hold, and why --}}
                    <ul class="mt-2 space-y-1" x-show="ed.holding.length">
                        <template x-for="h in ed.holding" :key="h.title">
                            <li class="flex items-start gap-2 text-xs">
                                <span class="text-violet-300">⏸</span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-brand-100/85" x-text="h.title"></span>
                                    <span class="block truncate text-brand-100/45" x-text="h.for + (h.note ? ' · “' + h.note + '”' : '')"></span>
                                </span>
                            </li>
                        </template>
                    </ul>

                    {{-- The day, as a strip --}}
                    <div class="relative mt-3 h-2 overflow-hidden rounded-full bg-white/5" :title="'Today, ' + hours[0]?.label + ' to now'">
                        <template x-for="b in laneOf(ed.person)?.blocks ?? []" :key="b.id">
                            <span class="absolute inset-y-0" :class="b.type === 'work' ? '' : 'et-hold-bar'"
                                  :style="`left:${x(b.from)}%;width:${width(b)}%;${b.type === 'work' ? `background:hsl(${ed.hue} 70% 58% / .8)` : ''}`"></span>
                        </template>
                        <span class="absolute inset-y-0 w-0.5 bg-red-400/80" :style="`left:${nowX}%`" x-show="nowX !== null"></span>
                    </div>
                </article>
            </template>
        </section>

        {{-- ═══ The day's flow ═══ --}}
        <section class="flex flex-wrap items-center gap-x-5 gap-y-2 rounded-2xl bg-white/[0.03] px-4 py-3 text-sm ring-1 ring-white/10 sm:px-5">
            <p class="font-semibold text-white">
                <span x-text="data.board.flow.added"></span> added
                <span class="text-brand-100/40">→</span> <span class="text-sky-200"><span x-text="data.board.flow.done"></span> done</span>
                <span class="text-brand-100/40">→</span> <span class="text-emerald-200"><span x-text="data.board.flow.out"></span> out</span>
            </p>
            <div class="h-2 w-28 overflow-hidden rounded-full bg-white/10" :title="data.board.flow.out + ' of ' + data.board.flow.done + ' finished videos are out'">
                <div class="h-full rounded-full bg-emerald-400 transition-all duration-700" :style="`width:${data.board.flow.done ? Math.min(100, data.board.flow.out / data.board.flow.done * 100) : 0}%`"></div>
            </div>
            <p class="text-xs text-brand-100/55">
                <span x-text="data.board.flow.review"></span> in review ·
                <span x-text="data.board.flow.ready"></span> ready to publish ·
                <span x-text="data.board.flow.fixes"></span> corrections
            </p>
            <p class="w-full text-xs text-brand-100/55" x-show="data.board.next.length">
                Next to publish:
                <template x-for="n in data.board.next" :key="n.title">
                    <span class="ml-1 rounded-full bg-violet-400/15 px-2 py-0.5 text-violet-200" x-text="n.at + ' · ' + n.title + ' (in ' + n.in + ')'"></span>
                </template>
            </p>
        </section>
    </div>
</template>
