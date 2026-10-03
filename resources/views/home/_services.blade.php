{{--
    What we do: the services as tabs, each with a small live mock-up of
    the work it describes (serviceExplorer in resources/js/home-studio.js).

    It plays itself, one service every few seconds, once it is on screen;
    a tap, click or hover hands it over to the visitor. The mock-ups are CSS
    animations inside <template x-if>, so switching to one restarts it; with
    reduced motion they are simply shown finished. The covers are the real
    portfolio's, from the film ($film).

    Editing flips between log and graded footage, Stills shows the real
    Instagram grids of the accounts we run (App\Support\InstagramGrid), and
    Reports draws the real monthly numbers as shapes only -- shares of the
    best month and multiples, never the figures (App\Support\StudioNumbers).

    Active-tab styling comes only from :class: a server-rendered "first is
    active" class would never be taken off again.

    Expects: $services (list of [title, description]), $film, $studio, $grid.
--}}
@php
    $covers = $film->pluck('cover')->filter()->values();
    $cover = fn (int $i) => $covers->isEmpty() ? null : $covers[$i % $covers->count()];
    $report = $studio['report'];

    $icons = [
        // phone, play, pen, scissors, camera, calendar, chart
        'M7 2h10a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm4 17h2',
        'M3 6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zm7 3v6l5-3z',
        'M4 20l4-1 11-11-3-3L5 16zm10-13l3 3',
        'M6 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm0 12a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM8.1 7.9 20 18M8.1 16.1 20 6',
        'M4 8h3l2-3h6l2 3h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1zm8 9a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
        'M4 6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2zm0 4h16M8 2v4m8-4v4',
        'M3 3v18h18M7 15l4-4 3 3 6-7',
    ];
@endphp

@push('styles')
    <style>
        @keyframes wd-in { from { opacity: 0; transform: translateY(14px) scale(.97); } to { opacity: 1; transform: none; } }
        @keyframes wd-zoom { from { transform: scale(1); } to { transform: scale(1.14); } }
        @keyframes wd-word { from { opacity: 0; transform: translateY(8px) scale(.9); } to { opacity: 1; transform: none; } }
        @keyframes wd-fill { from { transform: scaleX(0); } to { transform: scaleX(1); } }
        @keyframes wd-heart { 0% { opacity: 0; transform: translate(0, 0) scale(.6); } 15% { opacity: 1; } 100% { opacity: 0; transform: translate(-18px, -150px) scale(1.15); } }
        @keyframes wd-pulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.25); } }
        @keyframes wd-type { from { clip-path: inset(0 100% 0 0); } to { clip-path: inset(0 0 0 0); } }
        @keyframes wd-stamp { 0% { opacity: 0; transform: rotate(-12deg) scale(2.2); } 60% { opacity: 1; transform: rotate(-12deg) scale(.92); } 100% { opacity: 1; transform: rotate(-12deg) scale(1); } }
        @keyframes wd-slide { from { opacity: 0; transform: translateX(60px); } to { opacity: 1; transform: none; } }
        @keyframes wd-head { from { left: 0%; } to { left: 100%; } }
        @keyframes wd-wave { 0%, 100% { transform: scaleY(.35); } 50% { transform: scaleY(1); } }
        @keyframes wd-pop { 0% { opacity: 0; transform: scale(.4) rotate(var(--r, 0deg)); } 70% { opacity: 1; transform: scale(1.06) rotate(var(--r, 0deg)); } 100% { opacity: 1; transform: scale(1) rotate(var(--r, 0deg)); } }
        @keyframes wd-flash { 0%, 100% { opacity: 0; } 8% { opacity: .85; } }
        @keyframes wd-drop { 0% { opacity: 0; transform: translateY(-40px) rotate(-6deg); } 70% { opacity: 1; transform: translateY(3px); } 100% { opacity: 1; transform: none; } }
        @keyframes wd-blink { 50% { opacity: 0; } }

        .wd-in { animation: wd-in .5s cubic-bezier(.2,.8,.2,1) both; }
        .wd-zoom { animation: wd-zoom 6s ease-out both; }
        .wd-word { animation: wd-word .45s cubic-bezier(.2,.8,.2,1) both; }
        .wd-fill { transform-origin: left; animation: wd-fill var(--d, 6s) linear both; }
        .wd-heart { animation: wd-heart 2.4s ease-out infinite both; }
        .wd-pulse { animation: wd-pulse 1.2s ease-in-out infinite; }
        .wd-type { animation: wd-type .9s steps(24) both; }
        .wd-stamp { animation: wd-stamp .5s cubic-bezier(.2,.8,.2,1) both; }
        .wd-slide { animation: wd-slide .5s cubic-bezier(.2,.8,.2,1) both; }
        .wd-head { animation: wd-head 4s linear 1.2s infinite both; }
        .wd-wave { transform-origin: center; animation: wd-wave 1s ease-in-out infinite; }
        .wd-pop { animation: wd-pop .5s cubic-bezier(.2,.8,.2,1) both; }
        .wd-flash { animation: wd-flash 1.6s ease-out both; }
        .wd-drop { animation: wd-drop .55s cubic-bezier(.2,.8,.2,1) both; }
        .wd-blink { animation: wd-blink 1s steps(1) infinite; }

        @media (prefers-reduced-motion: reduce) {
            [class*="wd-"] { animation: none !important; }
        }
    </style>
@endpush

<section id="services" x-data="serviceExplorer({{ count($services) }}, @js($report))" class="relative scroll-mt-20 py-16 sm:py-24 overflow-hidden">
    <div aria-hidden="true" class="pointer-events-none absolute -top-40 right-[-10vw] w-[50vw] h-[50vw] max-w-[640px] max-h-[640px] rounded-full bg-brand-400/10 blur-3xl"></div>

    <div class="relative max-w-7xl mx-auto px-5 sm:px-8">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
            <div>
                <p class="text-brand-300 text-xs font-semibold uppercase tracking-[0.25em] mb-3">What we do</p>
                <h2 class="text-3xl sm:text-5xl font-extrabold max-w-2xl leading-[1.1]">Everything between the brief and the upload.</h2>
            </div>
            <p class="text-sm text-brand-100/60 max-w-xs">Tap a service and watch it work. One team, from the first idea to the last comment reply.</p>
        </div>

        <div class="mt-10 sm:mt-14 grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.15fr)] lg:gap-12 lg:items-center">
            {{-- The services. A swipeable row of chips on a phone, a list on a desk. --}}
            <div role="tablist" aria-label="Services"
                 class="-mx-5 px-5 flex gap-2 overflow-x-auto snap-x [scrollbar-width:none] [&::-webkit-scrollbar]:hidden lg:mx-0 lg:px-0 lg:flex-col lg:gap-1 lg:overflow-visible">
                @foreach ($services as $i => [$title, $description])
                    <button type="button" role="tab" id="wd-tab-{{ $i }}" aria-controls="wd-stage"
                            :aria-selected="(active === {{ $i }}).toString()"
                            @click="pick({{ $i }})" @mouseenter="window.matchMedia('(hover: hover)').matches && pick({{ $i }})"
                            :class="active === {{ $i }} ? 'bg-white/[0.07] border-brand-400/50 lg:border-transparent' : 'border-white/10 lg:border-transparent hover:bg-white/[0.03]'"
                            class="group relative snap-start shrink-0 text-left rounded-full lg:rounded-2xl border px-4 py-2.5 lg:px-5 lg:py-4 transition-colors">
                        <span class="flex items-center gap-3">
                            <span :class="active === {{ $i }} ? 'bg-brand-400 text-brand-900' : 'bg-white/5 text-brand-300'"
                                  class="hidden lg:flex shrink-0 w-10 h-10 rounded-xl items-center justify-center transition-colors">
                                <svg viewBox="0 0 24 24" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icons[$i] }}" /></svg>
                            </span>
                            <span class="min-w-0">
                                <span class="flex items-baseline gap-2 whitespace-nowrap">
                                    <span class="text-brand-400/70 text-xs font-extrabold tabular-nums">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</span>
                                    <span :class="active === {{ $i }} ? 'text-white' : 'text-brand-100/70'" class="font-semibold text-sm lg:text-lg">{{ $title }}</span>
                                </span>
                                {{-- The description, under the active one, on a desk. --}}
                                <span x-show="active === {{ $i }}" x-transition.opacity.duration.300ms @if ($i !== 0) style="display: none" @endif
                                      class="hidden lg:block mt-1 text-sm text-brand-100/65 leading-relaxed max-w-md">{{ $description }}</span>
                            </span>
                        </span>
                        {{-- How long until the next one, while it plays itself. --}}
                        <template x-if="auto && active === {{ $i }}">
                            <span class="absolute left-5 right-5 bottom-0 h-0.5 rounded-full bg-white/10 overflow-hidden" aria-hidden="true">
                                <span class="wd-fill block h-full bg-brand-400" style="--d: 6.5s"></span>
                            </span>
                        </template>
                    </button>
                @endforeach
            </div>

            {{-- The stage: the active service, as a working mock-up. --}}
            <div id="wd-stage" x-ref="stage" role="tabpanel" :aria-labelledby="'wd-tab-' + active">
                <div class="relative aspect-[4/5] sm:aspect-[4/3] rounded-[1.75rem] bg-gradient-to-br from-brand-800 via-brand-800/70 to-brand-900 ring-1 ring-white/10 shadow-2xl shadow-black/40 overflow-hidden">
                    <div aria-hidden="true" class="absolute inset-0 [background-image:radial-gradient(rgba(171,218,231,.08)_1px,transparent_1px)] [background-size:22px_22px]"></div>

                    {{-- 01 · Short-form: a reel playing on a phone, the caption landing word by word, hearts coming in. --}}
                    <template x-if="active === 0"><div class="absolute inset-0 flex items-center justify-center gap-6 p-6" aria-hidden="true">
                        <div class="wd-in relative h-full aspect-[9/16] rounded-[1.6rem] bg-black ring-4 ring-brand-900 shadow-2xl overflow-hidden">
                            @if ($cover(0))
                                <img src="{{ $cover(0) }}" alt="" class="wd-zoom absolute inset-0 w-full h-full object-cover" loading="lazy">
                            @else
                                <div class="wd-zoom absolute inset-0 bg-gradient-to-br from-brand-500 to-brand-800"></div>
                            @endif
                            <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-transparent to-black/30"></div>
                            <span class="absolute top-2 inset-x-3 h-0.5 rounded-full bg-white/25 overflow-hidden"><span class="wd-fill block h-full bg-white" style="--d: 6s"></span></span>
                            <p class="absolute inset-x-3 top-[38%] text-center text-lg sm:text-xl font-extrabold leading-tight drop-shadow">
                                @foreach (['Wait', 'for', 'the', 'end…'] as $w => $word)
                                    <span class="wd-word inline-block {{ $w === 3 ? 'text-brand-300' : '' }}" style="animation-delay: {{ 0.5 + $w * 0.35 }}s">{{ $word }}</span>
                                @endforeach
                            </p>
                            <div class="absolute right-2 bottom-16 flex flex-col items-center gap-3 text-[10px] font-semibold">
                                <span class="wd-pulse text-red-400"><svg viewBox="0 0 24 24" class="w-6 h-6" fill="currentColor"><path d="M12 21s-7-4.5-9.5-9A5.5 5.5 0 0 1 12 6a5.5 5.5 0 0 1 9.5 6c-2.5 4.5-9.5 9-9.5 9z"/></svg></span>
                                <svg viewBox="0 0 24 24" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/></svg>
                                <svg viewBox="0 0 24 24" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 3 11 14M22 3l-7 19-4-8-8-4z"/></svg>
                            </div>
                            @foreach ([0, 0.6, 1.2, 1.8] as $h => $delay)
                                <span class="wd-heart absolute bottom-24 text-red-400" style="right: {{ 10 + $h * 3 }}px; animation-delay: {{ 1 + $delay }}s"><svg viewBox="0 0 24 24" class="w-4 h-4" fill="currentColor"><path d="M12 21s-7-4.5-9.5-9A5.5 5.5 0 0 1 12 6a5.5 5.5 0 0 1 9.5 6c-2.5 4.5-9.5 9-9.5 9z"/></svg></span>
                            @endforeach
                            <p class="absolute left-3 bottom-4 right-12 text-[11px] leading-snug"><span class="font-bold">&#64;yourbrand</span> <span class="text-white/70">Hook in the first second ✨</span></p>
                        </div>
                    </div></template>

                    {{-- 02 · YouTube: a long-form player, chapters appearing, then subscribed. --}}
                    <template x-if="active === 1"><div class="absolute inset-0 flex flex-col justify-center p-5 sm:p-8" aria-hidden="true">
                        <div class="wd-in rounded-2xl bg-black/60 ring-1 ring-white/10 overflow-hidden shadow-2xl">
                            <div class="relative aspect-video bg-brand-900">
                                @if ($cover(1))
                                    <img src="{{ $cover(1) }}" alt="" class="wd-zoom absolute inset-0 w-full h-full object-cover opacity-70" loading="lazy">
                                @endif
                                <div class="absolute inset-0 bg-gradient-to-t from-black/80 to-transparent"></div>
                                <span class="absolute left-4 top-4 rounded bg-black/70 px-2 py-1 text-[11px] font-bold tracking-wide">EP 12 · FULL INTERVIEW</span>
                                <span class="absolute inset-0 m-auto w-14 h-10 rounded-xl bg-red-600 flex items-center justify-center"><svg viewBox="0 0 24 24" class="w-5 h-5" fill="#fff"><path d="M8 5v14l11-7z"/></svg></span>
                                <div class="absolute inset-x-4 bottom-4">
                                    <div class="relative h-1.5 rounded-full bg-white/25">
                                        <span class="wd-fill absolute inset-y-0 left-0 w-full rounded-full bg-red-600" style="--d: 5.5s"></span>
                                        @foreach ([18, 41, 63, 84] as $c => $at)
                                            <span class="wd-pop absolute -top-1 w-1 h-3.5 rounded bg-white" style="left: {{ $at }}%; animation-delay: {{ 0.4 + $c * 0.3 }}s"></span>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-3 p-4">
                                <span class="w-9 h-9 rounded-full bg-brand-400 shrink-0"></span>
                                <div class="flex-1 min-w-0 space-y-1.5">
                                    <span class="block h-2.5 w-3/4 rounded bg-white/70"></span>
                                    <span class="block h-2 w-1/2 rounded bg-white/25"></span>
                                </div>
                                <span class="relative shrink-0 grid">
                                    <span class="[grid-area:1/1] rounded-full bg-white text-black text-xs font-bold px-4 py-2">Subscribe</span>
                                    <span class="wd-pop [grid-area:1/1] rounded-full bg-white/15 text-white text-xs font-bold px-4 py-2 text-center" style="animation-delay: 3.2s">Subscribed ✓</span>
                                </span>
                            </div>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2 text-[11px] font-semibold">
                            @foreach (['00:00 Cold open', '02:14 The story', '08:40 Q&A', '14:05 Takeaways'] as $c => $chapter)
                                <span class="wd-in rounded-full bg-white/10 px-3 py-1" style="animation-delay: {{ 0.6 + $c * 0.3 }}s">{{ $chapter }}</span>
                            @endforeach
                        </div>
                    </div></template>

                    {{-- 03 · Scripting: the beats written out, then signed off. --}}
                    <template x-if="active === 2"><div class="absolute inset-0 flex items-center justify-center p-5 sm:p-8" aria-hidden="true">
                        <div class="wd-in relative w-full max-w-md rounded-2xl bg-white text-brand-900 p-5 sm:p-7 shadow-2xl">
                            <p class="text-[11px] font-extrabold tracking-[0.2em] text-brand-600">SCRIPT · REEL 01</p>
                            <dl class="mt-4 space-y-3 text-sm">
                                @foreach ([['HOOK', 'Say the surprising thing first.'], ['PROBLEM', 'Name what the viewer already feels.'], ['PAYOFF', 'Show the answer, not a lecture.'], ['CTA', 'One clear ask. Follow, save, or DM.']] as $l => [$beat, $line])
                                    <div class="flex gap-3">
                                        <dt class="wd-in w-20 shrink-0 text-[11px] font-extrabold tracking-wider text-brand-500 pt-0.5" style="animation-delay: {{ 0.3 + $l * 0.9 }}s">{{ $beat }}</dt>
                                        <dd class="wd-type font-medium" style="animation-delay: {{ 0.5 + $l * 0.9 }}s">{{ $line }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                            <span class="wd-blink inline-block mt-3 w-0.5 h-4 bg-brand-900 align-middle"></span>
                            <span class="wd-stamp absolute right-5 bottom-5 rounded-md border-[3px] border-emerald-500 px-3 py-1 text-sm font-extrabold tracking-[0.2em] text-emerald-500" style="animation-delay: 4.3s">APPROVED</span>
                        </div>
                    </div></template>

                    {{-- 04 · Editing: a colour page. A 9:16 reel in the viewer,
                         flipping between flat log footage and the grade;
                         the wheels, nodes and scopes move with it. The
                         toggle hands the switch to the visitor. --}}
                    <template x-if="active === 3"><div class="absolute inset-0 flex flex-col bg-[#161618] text-[#c9c9cc] select-none">
                        <div class="flex items-center justify-between gap-2 h-7 px-3 bg-[#1f1f22] border-b border-black/60 text-[10px] font-semibold">
                            <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-red-400/80"></span><span class="w-2 h-2 rounded-full bg-amber-300/80"></span><span class="w-2 h-2 rounded-full bg-emerald-400/80"></span><span class="ml-2 truncate">Reel_Final_v3 · Colour</span></span>
                            <span class="tabular-nums text-[#8f8f94]">01:00:12:08</span>
                        </div>

                        <div class="flex-1 min-h-0 flex gap-2 p-2">
                            {{-- The viewer. --}}
                            <div class="relative h-full aspect-[9/16] shrink-0 rounded-md overflow-hidden bg-black ring-1 ring-black">
                                @if ($cover(2))
                                    <img src="{{ $cover(2) }}" alt="" loading="lazy" class="absolute inset-0 w-full h-full object-cover transition-[filter] duration-700"
                                         :style="graded ? 'filter: saturate(1.2) contrast(1.1) brightness(1.02)' : 'filter: saturate(0.35) contrast(0.66) brightness(1.14)'">
                                @else
                                    <div class="absolute inset-0 bg-gradient-to-br from-amber-400 via-rose-500 to-brand-700 transition-[filter] duration-700" :style="graded ? '' : 'filter: saturate(0.35) contrast(0.66) brightness(1.14)'"></div>
                                @endif
                                <span class="absolute left-1.5 top-1.5 rounded px-1.5 py-0.5 text-[9px] font-bold tracking-wider transition-colors"
                                      :class="graded ? 'bg-emerald-400 text-black' : 'bg-white/80 text-black'" x-text="graded ? 'GRADED · REC.709' : 'LOG · FLAT'">LOG · FLAT</span>
                                <span class="absolute inset-x-0 bottom-0 h-1 bg-white/15"><span class="wd-fill block h-full bg-red-500" style="--d: 7s"></span></span>
                            </div>

                            {{-- The panel: switch, nodes, wheels, scope. --}}
                            <div class="flex-1 min-w-0 flex flex-col gap-2">
                                <div class="flex rounded-md bg-black/50 p-0.5 text-[10px] sm:text-xs font-bold" role="group" aria-label="Footage">
                                    <button type="button" @click="setGrade(false)" :aria-pressed="(!graded).toString()" :class="graded ? 'text-[#8f8f94]' : 'bg-[#3a3a3f] text-white'" class="flex-1 rounded py-1.5 transition-colors">Log</button>
                                    <button type="button" @click="setGrade(true)" :aria-pressed="graded.toString()" :class="graded ? 'bg-brand-400 text-brand-900' : 'text-[#8f8f94]'" class="flex-1 rounded py-1.5 transition-colors">Graded</button>
                                </div>

                                <div class="hidden sm:flex items-center gap-1.5 rounded-md bg-[#1f1f22] p-2" aria-hidden="true">
                                    @foreach (['01 CST', '02 Skin', '03 Look'] as $n => $node)
                                        @if ($n)<span class="h-px flex-1 bg-[#55555a]"></span>@endif
                                        <span class="rounded border px-1.5 py-1 text-[9px] font-bold transition-colors duration-500"
                                              :class="graded ? 'border-brand-400 bg-brand-400/15 text-brand-200' : 'border-[#55555a] text-[#8f8f94]'">{{ $node }}</span>
                                    @endforeach
                                </div>

                                <div class="grid grid-cols-3 gap-1.5 sm:gap-2 rounded-md bg-[#1f1f22] p-2" aria-hidden="true">
                                    @foreach ([['Lift', -5, 4], ['Gamma', 4, -3], ['Gain', 6, -5]] as [$wheel, $dx, $dy])
                                        <div class="flex flex-col items-center gap-1">
                                            <span class="relative w-full max-w-[4.5rem] aspect-square rounded-full p-[3px] [background:conic-gradient(#ef4444,#f59e0b,#eab308,#22c55e,#06b6d4,#3b82f6,#a855f7,#ef4444)]">
                                                <span class="absolute inset-[3px] rounded-full bg-[radial-gradient(circle,#3a3a3f,#1b1b1e)]"></span>
                                                <span class="absolute left-1/2 top-1/2 -ml-1 -mt-1 w-2 h-2 rounded-full bg-white shadow transition-transform duration-700"
                                                      :style="graded ? 'transform: translate({{ $dx }}px, {{ $dy }}px)' : 'transform: none'"></span>
                                            </span>
                                            <span class="text-[9px] font-semibold">{{ $wheel }}</span>
                                        </div>
                                    @endforeach
                                </div>

                                {{-- RGB parade: squeezed into the middle on log, spread on the grade. --}}
                                <div class="flex-1 min-h-[2.5rem] grid grid-cols-3 gap-1 rounded-md bg-black p-1.5" aria-hidden="true">
                                    @foreach (['bg-red-500/70', 'bg-green-500/70', 'bg-blue-500/70'] as $c => $channel)
                                        <div class="flex items-center gap-[2px]">
                                            @for ($b = 0; $b < 10; $b++)
                                                <span class="flex-1 rounded-sm {{ $channel }} transition-transform duration-700"
                                                      style="height: {{ 40 + (($b * 29 + $c * 13) % 55) }}%" :style="`height: {{ 40 + (($b * 29 + $c * 13) % 55) }}%; transform: scaleY(${graded ? 1 : 0.45})`"></span>
                                            @endfor
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center justify-center gap-3 sm:gap-5 h-7 bg-[#1f1f22] border-t border-black/60 text-[9px] sm:text-[10px] font-semibold text-[#8f8f94]" aria-hidden="true">
                            @foreach (['Media', 'Cut', 'Edit', 'Fusion', 'Colour', 'Audio', 'Deliver'] as $page)
                                <span class="{{ $page === 'Colour' ? 'text-brand-300' : '' }} {{ in_array($page, ['Media', 'Fusion', 'Audio'], true) ? 'hidden sm:inline' : '' }}">{{ $page }}</span>
                            @endforeach
                        </div>
                    </div></template>

                    {{-- 05 · Stills & static: the real grids of the accounts we
                         run, as an Instagram profile, switchable. --}}
                    <template x-if="active === 4"><div class="absolute inset-0 flex flex-col sm:flex-row gap-3 sm:gap-5 p-3 sm:p-6 bg-white text-neutral-900">
                        @if ($grid)
                            <div class="shrink-0 sm:w-40 flex sm:flex-col gap-3">
                                <div class="flex sm:grid sm:grid-cols-2 gap-2 sm:gap-3 overflow-x-auto [scrollbar-width:none]" role="group" aria-label="Accounts">
                                    @foreach ($grid as $a => $profile)
                                        <button type="button" @click="pickAccount({{ $a }})" :aria-pressed="(account === {{ $a }}).toString()" class="shrink-0 flex flex-col items-center gap-1">
                                            <span :class="account === {{ $a }} ? 'opacity-100 scale-105' : 'opacity-60'" class="block w-11 h-11 sm:w-14 sm:h-14 rounded-full p-[2px] bg-gradient-to-tr from-amber-400 via-pink-500 to-purple-600 transition">
                                                <span class="block w-full h-full rounded-full bg-white p-[2px]">
                                                    @if ($profile['avatar'])
                                                        <img src="{{ asset($profile['avatar']) }}" alt="" class="w-full h-full rounded-full object-cover" loading="lazy">
                                                    @else
                                                        <span class="block w-full h-full rounded-full bg-neutral-200"></span>
                                                    @endif
                                                </span>
                                            </span>
                                            <span class="max-w-[4.5rem] truncate text-[9px] font-semibold text-neutral-600">{{ $profile['username'] }}</span>
                                        </button>
                                    @endforeach
                                </div>
                                @foreach ($grid as $a => $profile)
                                    <div x-show="account === {{ $a }}" @if ($a) style="display: none" @endif class="hidden sm:block">
                                        <p class="text-sm font-bold truncate">{{ $profile['username'] }}</p>
                                        @if ($profile['name'])<p class="text-xs text-neutral-500 truncate">{{ $profile['name'] }}</p>@endif
                                        <span class="mt-2 inline-flex items-center gap-1.5 rounded-full bg-emerald-50 ring-1 ring-emerald-200 px-2 py-0.5 text-[9px] font-bold uppercase tracking-widest text-emerald-700"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>Real posts</span>
                                    </div>
                                @endforeach
                            </div>

                            <div class="flex-1 min-h-0 flex items-center justify-center">
                                @foreach ($grid as $a => $profile)
                                    <template x-if="account === {{ $a }}">
                                        <div class="h-full max-w-full aspect-[3/4] grid grid-cols-3 grid-rows-3 gap-[2px]">
                                            @foreach (array_slice($profile['posts'], 0, 9) as $p => $post)
                                                <a href="{{ $post['url'] }}" target="_blank" rel="noopener" class="wd-pop group relative block overflow-hidden bg-neutral-200" style="animation-delay: {{ $p * 0.07 }}s">
                                                    <img src="{{ asset($post['image']) }}" alt="Post by {{ $profile['username'] }}" class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105" loading="lazy">
                                                    @if ($post['type'] !== 'photo')
                                                        <svg viewBox="0 0 24 24" class="absolute right-1 top-1 w-3.5 h-3.5 drop-shadow" fill="#fff" aria-hidden="true">
                                                            @if ($post['type'] === 'reel')<path d="M4 4h16v16H4zM4 9h16M9 4l3 5M15 4l3 5" fill="none" stroke="#fff" stroke-width="2"/><path d="M10 12.5v5l4-2.5z"/>@else<path d="M7 3h13v13H7z"/><path d="M4 7v13h13" fill="none" stroke="#fff" stroke-width="2"/>@endif
                                                        </svg>
                                                    @endif
                                                    <span class="absolute inset-0 bg-black/0 group-hover:bg-black/25 transition-colors"></span>
                                                </a>
                                            @endforeach
                                        </div>
                                    </template>
                                @endforeach
                            </div>
                        @else
                            <div class="m-auto grid grid-cols-3 gap-1 w-full max-w-xs" aria-hidden="true">
                                @for ($s = 0; $s < 9; $s++)
                                    <div class="wd-pop aspect-[3/4] bg-neutral-200 overflow-hidden" style="animation-delay: {{ $s * 0.07 }}s">
                                        @if ($cover(3 + $s))<img src="{{ $cover(3 + $s) }}" alt="" class="w-full h-full object-cover" loading="lazy">@endif
                                    </div>
                                @endfor
                            </div>
                        @endif
                    </div></template>


                    {{-- 06 · Publishing: the week fills up, then it is all scheduled. --}}
                    <template x-if="active === 5"><div class="absolute inset-0 flex flex-col justify-center p-5 sm:p-8" aria-hidden="true">
                        <div class="wd-in rounded-2xl bg-white/[0.06] ring-1 ring-white/10 p-4">
                            <div class="flex items-center justify-between text-xs font-semibold text-brand-200">
                                <span>This week</span><span class="text-brand-100/50">Instagram · YouTube · Facebook</span>
                            </div>
                            <div class="mt-3 grid grid-cols-7 gap-1.5 sm:gap-2">
                                @foreach (['M', 'T', 'W', 'T', 'F', 'S', 'S'] as $d => $day)
                                    <div class="text-center">
                                        <span class="text-[10px] font-bold text-brand-100/50">{{ $day }}</span>
                                        <div class="mt-1 h-24 sm:h-28 rounded-lg bg-black/25 p-1 flex flex-col gap-1">
                                            @if ($d !== 6)
                                                <span class="wd-drop block aspect-[9/16] rounded bg-cover bg-center ring-1 ring-white/20 {{ $cover($d) ? '' : 'bg-brand-500' }}"
                                                      @if ($cover($d)) style="background-image: url('{{ $cover($d) }}'); animation-delay: {{ 0.3 + $d * 0.3 }}s" @else style="animation-delay: {{ 0.3 + $d * 0.3 }}s" @endif></span>
                                                <span class="wd-in text-[9px] font-bold text-brand-300" style="animation-delay: {{ 0.5 + $d * 0.3 }}s">{{ ['7 PM', '1 PM', '7 PM', '6 PM', '8 PM', '11 AM'][$d] }}</span>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        <div class="wd-pop mt-4 self-center flex items-center gap-2 rounded-full bg-emerald-400 text-brand-900 px-4 py-2 text-sm font-bold shadow-lg" style="animation-delay: 2.4s">
                            <svg viewBox="0 0 24 24" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="3"><path d="m5 12 5 5 9-10"/></svg>
                            6 posts scheduled · replies handled
                        </div>
                    </div></template>

                    {{-- 07 · Reports & analysis: a monthly report built from
                         the real numbers, shown as shares of the best month
                         and multiples -- never the numbers themselves. --}}
                    <template x-if="active === 6"><div class="absolute inset-0 flex flex-col gap-3 p-4 sm:p-6">
                        <div class="flex items-center justify-between gap-2">
                            <div>
                                <p class="text-[10px] uppercase tracking-widest text-brand-300 font-semibold">Monthly report</p>
                                <p class="text-sm sm:text-base font-bold">Every account we run, month by month</p>
                            </div>
                            <span class="shrink-0 inline-flex items-center gap-1.5 rounded-full bg-emerald-400/10 ring-1 ring-emerald-400/30 px-2.5 py-1 text-[10px] font-bold uppercase tracking-widest text-emerald-300"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>Real data</span>
                        </div>

                        <div class="grid grid-cols-2 gap-2 sm:gap-3">
                            @if ($report['viewsMultiple'])
                                <div class="wd-in rounded-xl bg-white/[0.06] p-3">
                                    <p class="text-2xl sm:text-3xl font-extrabold text-brand-200 tabular-nums" data-count>×{{ $report['viewsMultiple'] }}</p>
                                    <p class="text-[11px] text-brand-100/60">monthly views, {{ $report['months'][0]['label'] }} to {{ last($report['months'])['label'] }}</p>
                                </div>
                            @endif
                            @if ($report['engagement'])
                                <div class="wd-in rounded-xl bg-white/[0.06] p-3" style="animation-delay: .1s">
                                    <p class="text-2xl sm:text-3xl font-extrabold tabular-nums" data-count>{{ $report['engagement'] }}%</p>
                                    <p class="text-[11px] text-brand-100/60">engagement rate on reach</p>
                                </div>
                            @endif
                        </div>

                        <div class="flex flex-wrap gap-1.5" role="group" aria-label="Metric">
                            @foreach (['views' => 'Views', 'reach' => 'Reach', 'likes' => 'Likes', 'shares' => 'Shares', 'saved' => 'Saves'] as $key => $label)
                                <button type="button" @click="pickMetric('{{ $key }}')" :aria-pressed="(metric === '{{ $key }}').toString()"
                                        :class="metric === '{{ $key }}' ? 'bg-brand-400 text-brand-900' : 'bg-white/[0.06] text-brand-100/70 hover:bg-white/10'"
                                        class="rounded-full px-3 py-1 text-[11px] font-bold transition-colors">{{ $label }}</button>
                            @endforeach
                        </div>

                        <div class="flex-1 min-h-0 grid gap-3 sm:grid-cols-[1fr_11rem]">
                            <div class="min-h-0 flex flex-col">
                                <div class="flex-1 min-h-[5rem] flex items-end gap-1.5 sm:gap-3">
                                    <template x-for="(month, m) in months" :key="month.label">
                                        <div class="flex-1 h-full flex items-end">
                                            <span class="w-full rounded-t-md bg-gradient-to-t from-brand-600 to-brand-300 transition-[height] duration-700 ease-out" :style="`height: ${bar(month)}%`"></span>
                                        </div>
                                    </template>
                                </div>
                                <div class="mt-1 flex gap-1.5 sm:gap-3">
                                    @foreach ($report['months'] as $month)
                                        <span class="flex-1 text-center text-[10px] text-brand-100/50">{{ $month['label'] }}</span>
                                    @endforeach
                                </div>
                            </div>
                            @if ($report['top'])
                                <div class="hidden sm:block">
                                    <p class="text-[10px] uppercase tracking-widest text-brand-100/50 font-semibold">Top reels</p>
                                    <ol class="mt-2 space-y-2">
                                        @foreach ($report['top'] as $t => $reel)
                                            <li class="wd-in" style="animation-delay: {{ 0.2 + $t * 0.12 }}s">
                                                <p class="text-[11px] font-semibold truncate">&#64;{{ $reel['username'] }}</p>
                                                <p class="text-[10px] text-brand-100/50 truncate">{{ $reel['caption'] }}</p>
                                                <span class="mt-1 block h-1.5 rounded-full bg-white/10 overflow-hidden"><span class="wd-fill block h-full bg-emerald-400" style="--d: .9s; width: {{ $reel['pct'] }}%"></span></span>
                                            </li>
                                        @endforeach
                                    </ol>
                                </div>
                            @endif
                        </div>
                    </div></template>
                </div>

                {{-- The description, under the stage, on a phone. --}}
                @foreach ($services as $i => [$title, $description])
                    <p x-show="active === {{ $i }}" @if ($i !== 0) style="display: none" @endif class="lg:hidden mt-4 text-sm text-brand-100/70 leading-relaxed">
                        <span class="font-semibold text-white">{{ $title }}.</span> {{ $description }}
                    </p>
                @endforeach
            </div>
        </div>
    </div>
</section>
