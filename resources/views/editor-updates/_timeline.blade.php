{{--
    The live timeline: one lane per editor, their animated icon standing
    where they are now. Drawn by resources/js/editor-timeline.js from
    EditorUpdatesController::timeline(), re-read every 15 seconds for today.
--}}
@push('styles')
    <style>
        .et-av { position: relative; display: flex; align-items: center; justify-content: center; border-radius: 9999px; color: #0b1a24; font-weight: 800; }
        .et-working .et-av { animation: etBob 1.1s ease-in-out infinite; }
        .et-working .et-av::after { content: ''; position: absolute; inset: -4px; border-radius: 9999px; border: 2px solid #fbbf24; animation: etPulse 1.6s ease-out infinite; }
        .et-hold .et-av { filter: grayscale(.7) brightness(.85); }
        .et-hold .et-z { position: absolute; right: -6px; top: -10px; font-size: 11px; font-weight: 800; color: #c4b5fd; animation: etZ 2.2s ease-in-out infinite; }
        .et-idle .et-av { opacity: .55; animation: etBreathe 3.5s ease-in-out infinite; }
        .et-active .et-av { box-shadow: 0 0 0 2px rgba(103, 188, 212, .55); }
        .et-celebrate .et-av { animation: etParty .9s ease-in-out infinite; box-shadow: 0 0 18px rgba(52, 211, 153, .7); }
        .et-burst { position: absolute; left: 50%; top: 50%; font-size: 11px; pointer-events: none; animation: etBurst 1.8s ease-out infinite; }
        .et-dots { display: inline-flex; gap: 2px; margin-left: 4px; vertical-align: middle; }
        .et-dots i { width: 4px; height: 4px; border-radius: 9999px; background: #fbbf24; animation: etDot 1.2s infinite; }
        .et-dots i:nth-child(2) { animation-delay: .2s; } .et-dots i:nth-child(3) { animation-delay: .4s; }
        .et-walker { transition: left 1.2s cubic-bezier(.3, .7, .3, 1); }
        .et-live-bar { background-image: repeating-linear-gradient(-45deg, rgba(255,255,255,.22) 0 6px, transparent 6px 12px); background-size: 17px 17px; animation: etSlide .8s linear infinite; }
        .et-hold-bar { background-image: repeating-linear-gradient(-45deg, rgba(196,181,253,.45) 0 4px, rgba(196,181,253,.15) 4px 8px); }
        .et-pop { animation: etPop .7s cubic-bezier(.2, 1.6, .4, 1); }
        .et-tick-in { animation: etTick .5s ease-out; }
        @keyframes etBob { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-3px); } }
        @keyframes etPulse { from { opacity: .9; transform: scale(1); } to { opacity: 0; transform: scale(1.45); } }
        @keyframes etZ { 0% { opacity: 0; transform: translate(0, 4px); } 40% { opacity: 1; } 100% { opacity: 0; transform: translate(6px, -8px); } }
        @keyframes etBreathe { 50% { opacity: .85; } }
        @keyframes etParty { 0%, 100% { transform: rotate(0) scale(1); } 25% { transform: rotate(-12deg) scale(1.12); } 75% { transform: rotate(12deg) scale(1.12); } }
        @keyframes etBurst { from { opacity: 1; transform: translate(-50%, -50%) scale(.6); } to { opacity: 0; transform: translate(-50%, -190%) scale(1.3); } }
        @keyframes etDot { 0%, 60%, 100% { opacity: .25; transform: translateY(0); } 30% { opacity: 1; transform: translateY(-2px); } }
        @keyframes etSlide { to { background-position: 17px 0; } }
        @keyframes etPop { 0% { transform: translate(-50%, -50%) scale(0); } 60% { transform: translate(-50%, -50%) scale(1.6); } 100% { transform: translate(-50%, -50%) scale(1); } }
        @keyframes etTick { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: none; } }
        @media (prefers-reduced-motion: reduce) { .et-av, .et-av::after, .et-z, .et-burst, .et-dots i, .et-live-bar, .et-pop, .et-walker { animation: none !important; transition: none !important; } }
    </style>
@endpush

<section class="rounded-2xl bg-white/[0.03] ring-1 ring-white/10" x-data="editorTimeline(@js($timeline), @js(route('editor-updates.live', ['date' => $day->toDateString()])))">
    {{-- Header: live state and what is waiting --}}
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-white/10 px-4 py-3 sm:px-5">
        <p class="flex items-center gap-2 font-semibold text-white">
            <template x-if="data.is_today">
                <span class="relative flex h-2.5 w-2.5">
                    <span class="absolute inline-flex h-full w-full rounded-full opacity-60" :class="failed ? 'bg-amber-400' : 'animate-ping bg-emerald-400'"></span>
                    <span class="relative inline-flex h-2.5 w-2.5 rounded-full" :class="failed ? 'bg-amber-400' : 'bg-emerald-400'"></span>
                </span>
            </template>
            <span x-text="data.is_today ? 'Live timeline' : 'Timeline'"></span>
        </p>
        <p class="text-xs text-brand-100/50" x-show="data.is_today">
            <span x-text="failed ? 'reconnecting…' : 'updated ' + data.generated"></span> · every 15s
        </p>
        <div class="ml-auto flex flex-wrap gap-1.5 text-[11px] font-semibold">
            <span class="rounded-full bg-sky-400/15 px-2 py-0.5 text-sky-200" x-show="data.waiting.review > 0"><span x-text="data.waiting.review"></span> in review</span>
            <span class="rounded-full bg-violet-400/15 px-2 py-0.5 text-violet-200" x-show="data.waiting.ready > 0"><span x-text="data.waiting.ready"></span> ready to publish</span>
            <span class="rounded-full bg-red-500/20 px-2 py-0.5 text-red-200" x-show="data.waiting.late > 0">⚠ <span x-text="data.waiting.late"></span> late</span>
        </div>
    </div>

    {{-- The newest moves, as they arrive --}}
    <div class="flex min-h-[36px] flex-wrap items-center gap-2 border-b border-white/5 px-4 py-1.5 sm:px-5" x-show="ticker.length > 0" x-cloak>
        <template x-for="t in ticker" :key="t.key">
            <span class="et-tick-in inline-flex max-w-full items-center gap-1.5 rounded-full bg-white/5 px-2.5 py-1 text-xs ring-1 ring-white/10">
                <span class="flex h-4 w-4 items-center justify-center rounded-full text-[9px] font-bold text-brand-900" :style="`background:hsl(${t.hue} 70% 58%)`" x-text="t.who.slice(0, 1)"></span>
                <span class="font-semibold text-white" x-text="t.who"></span>
                <span x-text="icon(t) + ' ' + t.label"></span>
                <span class="truncate text-brand-100/60" x-text="'— ' + t.title"></span>
            </span>
        </template>
    </div>

    <div class="overflow-x-auto">
        <div class="min-w-[760px] px-4 pb-4 pt-2 sm:px-5">
            {{-- Hours --}}
            <div class="grid grid-cols-[170px_1fr] items-end">
                <div></div>
                <div class="relative h-6">
                    <template x-for="h in hours" :key="h.at">
                        <span class="absolute bottom-0 -translate-x-1/2 text-[10px] tabular-nums text-brand-100/40" :style="`left:${x(h.at)}%`" x-text="h.label"></span>
                    </template>
                </div>
            </div>

            <template x-if="data.lanes.length === 0">
                <p class="py-8 text-center text-sm text-brand-100/50">Nobody has moved a video yet. Lanes appear as editors work in My Editing.</p>
            </template>

            {{-- One lane per editor --}}
            <template x-for="lane in data.lanes" :key="lane.id">
                <div class="grid grid-cols-[170px_1fr] items-center border-t border-white/5 py-2" :class="'et-' + lane.state">
                    {{-- Who and what now --}}
                    <div class="flex min-w-0 items-center gap-2.5 pr-3">
                        <span class="relative shrink-0">
                            <span class="et-av h-9 w-9 text-sm" :style="`background:${color(lane)}`" x-text="lane.initial"></span>
                            <span class="et-z" x-show="lane.state === 'hold'">z</span>
                        </span>
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-semibold text-white" x-text="lane.name"></span>
                            <span class="block truncate text-[11px]"
                                  :class="{ 'text-amber-200': lane.state === 'working', 'text-violet-200': lane.state === 'hold', 'text-emerald-300': lane.state === 'celebrate', 'text-brand-100/50': ['idle', 'active'].includes(lane.state) }">
                                <span x-text="stateWord(lane)"></span><span class="et-dots" x-show="lane.state === 'working'"><i></i><i></i><i></i></span>
                                <span class="text-brand-100/45" x-show="lane.minutes > 0" x-text="' · ' + hm(lane.minutes)"></span>
                            </span>
                            <span class="block truncate text-[10px] text-brand-100/40" x-text="lane.doing || ''" :title="lane.doing || ''"></span>
                        </span>
                    </div>

                    {{-- The day --}}
                    <div class="relative h-12 rounded-lg bg-white/[0.025]">
                        <template x-for="h in hours" :key="'g' + h.at">
                            <span class="absolute inset-y-0 w-px bg-white/[0.05]" :style="`left:${x(h.at)}%`"></span>
                        </template>

                        {{-- Working / on hold --}}
                        <template x-for="b in lane.blocks" :key="b.id">
                            <span class="absolute top-1/2 h-3 -translate-y-1/2 rounded-full"
                                  :class="b.type === 'work' ? (b.open ? 'et-live-bar' : '') : 'et-hold-bar'"
                                  :style="`left:${x(b.from)}%;width:${width(b)}%;${b.type === 'work' ? `background-color:${color(lane, .75)}` : ''}`"
                                  :title="(b.type === 'work' ? 'Working · ' : 'On hold · ') + b.title"></span>
                        </template>

                        {{-- Every move --}}
                        <template x-for="m in lane.marks" :key="m.id">
                            <span class="absolute top-[22%] flex h-5 w-5 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-brand-900 text-[10px] ring-1 ring-white/25"
                                  :class="fresh[m.id] ? 'et-pop ring-2 ring-emerald-300' : ''"
                                  :style="`left:${x(m.at)}%`" :title="tip(m)" x-text="icon(m)"></span>
                        </template>

                        {{-- Where they are now --}}
                        <span class="et-walker absolute bottom-0 z-10 -translate-x-1/2 translate-y-1/3" :style="`left:${avatarX(lane)}%`" x-show="lane.at || data.is_today">
                            <span class="et-av h-6 w-6 text-[10px] ring-2 ring-brand-900" :style="`background:${color(lane)}`" x-text="lane.initial"></span>
                            <span class="et-burst" x-show="lane.state === 'celebrate'">🎉</span>
                        </span>

                        {{-- Now --}}
                        <template x-if="nowX !== null">
                            <span class="absolute inset-y-[-6px] w-0.5 rounded-full bg-red-400/80" :style="`left:${nowX}%`"></span>
                        </template>
                    </div>
                </div>
            </template>

            {{-- Key --}}
            <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 border-t border-white/5 pt-3 text-[11px] text-brand-100/50">
                <span><span class="mr-1 inline-block h-2 w-5 rounded-full bg-amber-300/80 align-middle"></span>Working</span>
                <span><span class="et-hold-bar mr-1 inline-block h-2 w-5 rounded-full align-middle"></span>On hold</span>
                <span>＋ added · ▶ started · ⚠ correction · ✓ fixed · ✔ done → review · 🕒 video ready · 🚀 posted · 📦 delivered · → moved</span>
                <span><span class="mr-1 inline-block h-3 w-0.5 bg-red-400 align-middle"></span>now</span>
            </div>
        </div>
    </div>
</section>
