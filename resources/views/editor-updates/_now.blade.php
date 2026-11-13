{{--
    The top of Editor Updates, today only, live from data.board
    (App\Support\EditorBoard, re-read every 15 seconds). Read in three looks:

    1. The verdict -- the day in one sentence, and its flow left to right.
    2. Needs you -- only things a person has to act on, each with its button.
    3. The team -- one card each: on what, for how long, the day so far, next.

    Everything else (queues, timeline, every move) is in the tabs below.
--}}
<template x-if="data.board">
    <div class="space-y-5">
        {{-- ═══ 1. The verdict ═══ --}}
        <section class="relative overflow-hidden rounded-[24px] p-5 ring-1 sm:p-6"
                 :class="{
                     red: 'bg-gradient-to-br from-red-500/20 to-[#15303f] ring-red-400/35',
                     amber: 'bg-gradient-to-br from-amber-400/15 to-[#15303f] ring-amber-400/30',
                     green: 'bg-gradient-to-br from-emerald-400/15 to-[#15303f] ring-emerald-400/30',
                     calm: 'bg-[#15303f] ring-white/[0.08]',
                 }[verdict.tone]">
            <div class="flex flex-wrap items-center gap-3">
                <span class="text-3xl leading-none" x-text="verdict.icon"></span>
                <div class="min-w-0 flex-1">
                    <h2 class="text-xl font-extrabold tracking-tight text-white sm:text-2xl" x-text="verdict.title"></h2>
                    <p class="mt-0.5 text-sm text-brand-100/70" x-text="verdict.sum"></p>
                </div>
            </div>

            {{-- The day's flow, left to right --}}
            <ol class="mt-5 grid grid-cols-3 gap-2 sm:grid-cols-6 sm:gap-0">
                <template x-for="(st, i) in steps" :key="st.key">
                    <li class="relative flex flex-col items-center rounded-2xl bg-black/15 px-2 py-3 sm:rounded-none sm:bg-transparent sm:py-1"
                        :class="st.n ? '' : 'opacity-45'">
                        <span class="text-[28px] font-extrabold leading-none tabular-nums sm:text-[34px]" :class="st.cls" x-text="st.n"></span>
                        <span class="mt-1 text-center text-[11px] font-semibold uppercase tracking-[0.1em] text-brand-100/60" x-text="st.label"></span>
                        <span x-show="i < steps.length - 1" class="absolute right-0 top-3 hidden translate-x-1/2 text-lg text-brand-100/25 sm:block" aria-hidden="true">→</span>
                    </li>
                </template>
            </ol>
        </section>

        {{-- ═══ 2. Needs you ═══ --}}
        <section x-show="data.board.needs.length" class="overflow-hidden rounded-[20px] border border-amber-400/30 bg-[#15303f]">
            <h2 class="flex items-center gap-2 border-b border-white/[0.06] px-5 py-3 text-[15px] font-bold text-amber-300">
                Needs you <span class="rounded-full bg-amber-400 px-2 text-[12px] text-[#0f2230]" x-text="data.board.needs.length"></span>
                <span class="ml-auto text-xs font-normal text-brand-100/50">most urgent first</span>
            </h2>
            <template x-for="(n, i) in data.board.needs" :key="n.kind + i">
                <div class="flex items-center gap-3 border-b border-white/[0.05] px-5 py-3 last:border-b-0">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-lg font-bold" :class="needLook(n).chip" x-text="needLook(n).icon"></span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold" :class="needLook(n).text" x-text="needTitle(n)"></p>
                        <p class="truncate text-xs text-brand-100/60" x-text="needLine(n)"></p>
                    </div>
                    <a x-show="needHref(n)" :href="needHref(n)" @click="goNeed(n, $event)"
                       class="inline-flex h-10 shrink-0 items-center rounded-xl px-4 text-[13px] font-bold"
                       :class="['late', 'review'].includes(n.kind) ? 'bg-brand-400 text-brand-900 hover:bg-brand-300' : 'bg-[#1a3a4b] text-white hover:bg-white/15'"
                       x-text="needCta(n)"></a>
                </div>
            </template>
            <div x-show="data.board.next.length" class="border-t border-white/[0.06] bg-violet-400/[0.05] px-5 py-2.5 text-[13px] text-brand-100/70">
                <span class="font-semibold text-violet-300">🕒 Going out next:</span>
                <template x-for="n in data.board.next" :key="n.title">
                    <span class="ml-2 inline-block text-white" x-text="n.at + ' ' + n.title + ' (in ' + n.in + ')'"></span>
                </template>
            </div>
        </section>

        {{-- ═══ 3. The team, a card each ═══ --}}
        <section>
            <h2 class="mb-2.5 flex items-baseline gap-2 text-[15px] font-bold text-white">
                The team <span class="text-xs font-normal text-brand-100/50">tap a card for their whole day</span>
            </h2>
            <p x-show="!data.board.editors.length" class="rounded-2xl bg-white/[0.04] px-6 py-8 text-center text-sm text-brand-100/50">Nobody on the team has used My Editing in the last two weeks.</p>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <template x-for="ed in data.board.editors" :key="ed.person">
                    <a :href="ed.user_id ? editorUrl.replace('__ID__', ed.user_id) : '#'"
                       class="group flex flex-col rounded-[20px] border bg-[#15303f] p-4 transition hover:-translate-y-0.5 hover:bg-[#183849]" :class="look(ed).ring">
                        <div class="flex items-center gap-3">
                            <span class="relative shrink-0" :class="'et-' + ed.state">
                                <template x-if="ed.photo">
                                    <img :src="ed.photo" :alt="ed.person" class="et-av h-12 w-12 object-cover" :style="`box-shadow:0 0 0 3px #15303f,0 0 0 5px ${look(ed).dot}`">
                                </template>
                                <template x-if="!ed.photo">
                                    <span class="et-av h-12 w-12 text-lg" :style="`background:hsl(${ed.hue} 70% 58%);box-shadow:0 0 0 3px #15303f,0 0 0 5px ${look(ed).dot}`" x-text="ed.initial"></span>
                                </template>
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-base font-bold text-white" x-text="ed.person"></p>
                                <span class="mt-0.5 inline-flex h-6 items-center gap-1.5 rounded-full px-2.5 text-xs font-semibold" :class="look(ed).pill">
                                    <span x-text="look(ed).word"></span><span class="et-dots" x-show="ed.state === 'working'"><i></i><i></i><i></i></span>
                                </span>
                            </div>
                        </div>

                        {{-- What they are on right now --}}
                        <div class="mt-3 min-h-[44px] rounded-xl px-3 py-2"
                             :class="ed.state === 'working' ? 'bg-amber-400/10' : (ed.holding.length ? 'bg-violet-400/10' : 'bg-white/[0.03]')">
                            <template x-if="ed.state === 'working'">
                                <p class="text-sm text-white"><span class="line-clamp-1 font-semibold" x-text="ed.title"></span>
                                    <span class="text-xs text-amber-200">for <b class="tabular-nums" x-text="span(runFor(ed.started_at))"></b></span></p>
                            </template>
                            <template x-if="ed.state !== 'working' && ed.holding.length">
                                <p class="text-sm text-white"><span class="line-clamp-1 font-semibold" x-text="'⏸ ' + ed.holding[0].title"></span>
                                    <span class="line-clamp-1 text-xs text-violet-200" x-text="ed.holding[0].for + (ed.holding[0].note ? ' · “' + ed.holding[0].note + '”' : '')"></span></p>
                            </template>
                            <template x-if="ed.state !== 'working' && !ed.holding.length">
                                <p class="line-clamp-2 text-[13px] text-brand-100/60" x-text="ed.last ? 'Last: ' + ed.last.time + ' · ' + ed.last.label + ' · ' + ed.last.title : 'No moves yet today'"></p>
                            </template>
                        </div>

                        {{-- The day so far --}}
                        <div class="mt-3 grid grid-cols-4 gap-1.5 text-center">
                            <span class="rounded-lg bg-black/15 py-1.5"><b class="block text-base leading-tight text-emerald-300" x-text="ed.out"></b><span class="text-[10px] text-brand-100/55">out</span></span>
                            <span class="rounded-lg bg-black/15 py-1.5"><b class="block text-base leading-tight text-sky-300" x-text="ed.done"></b><span class="text-[10px] text-brand-100/55">done</span></span>
                            <span class="rounded-lg bg-black/15 py-1.5"><b class="block text-base leading-tight" :class="ed.fixes ? 'text-orange-300' : 'text-white'" x-text="ed.fixes"></b><span class="text-[10px] text-brand-100/55">fixes</span></span>
                            <span class="rounded-lg bg-black/15 py-1.5"><b class="block text-base leading-tight text-white" x-text="span(laneOf(ed.person)?.minutes ?? 0) || '0m'"></b><span class="text-[10px] text-brand-100/55">editing</span></span>
                        </div>

                        {{-- Next --}}
                        <p class="mt-3 text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-100/45" x-text="'Up next' + (ed.waiting ? ' · ' + ed.waiting + ' waiting' : '')"></p>
                        <template x-for="t in ed.next.slice(0, 2)" :key="t">
                            <p class="truncate text-[13px] text-brand-100/85" x-text="'· ' + t"></p>
                        </template>
                        <p x-show="!ed.next.length" class="text-[13px] text-brand-100/45">Nothing queued</p>
                    </a>
                </template>
            </div>
        </section>
    </div>
</template>
