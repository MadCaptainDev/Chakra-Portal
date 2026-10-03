{{--
    What we do: the six services as tabs, each with a small live mock-up of
    the work it describes (serviceExplorer in resources/js/home-studio.js).

    It plays itself, one service every six seconds, once it is on screen;
    a tap, click or hover hands it over to the visitor. The mock-ups are CSS
    animations inside <template x-if>, so switching to one restarts it; with
    reduced motion they are simply shown finished. The covers are the real
    portfolio's, from the film ($film).

    Expects: $services (list of [title, description]), $film, $stats.
--}}
@php
    $covers = $film->pluck('cover')->filter()->values();
    $cover = fn (int $i) => $covers->isEmpty() ? null : $covers[$i % $covers->count()];
    $topViews = collect($stats)->firstWhere('label', 'views on a single reel')['display'] ?? null;

    $icons = [
        // phone, play, pen, scissors, camera, calendar
        'M7 2h10a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm4 17h2',
        'M3 6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zm7 3v6l5-3z',
        'M4 20l4-1 11-11-3-3L5 16zm10-13l3 3',
        'M6 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm0 12a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM8.1 7.9 20 18M8.1 16.1 20 6',
        'M4 8h3l2-3h6l2 3h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1zm8 9a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
        'M4 6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2zm0 4h16M8 2v4m8-4v4',
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

<section id="services" x-data="serviceExplorer({{ count($services) }})" class="relative scroll-mt-20 py-16 sm:py-24 overflow-hidden">
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
                            aria-selected="{{ $i === 0 ? 'true' : 'false' }}"
                            @click="pick({{ $i }})" @mouseenter="window.matchMedia('(hover: hover)').matches && pick({{ $i }})"
                            :class="active === {{ $i }} ? 'bg-white/[0.07] border-brand-400/50 lg:border-transparent' : 'border-white/10 lg:border-transparent hover:bg-white/[0.03]'"
                            class="group relative snap-start shrink-0 text-left rounded-full lg:rounded-2xl border px-4 py-2.5 lg:px-5 lg:py-4 transition-colors {{ $i === 0 ? 'bg-white/[0.07] border-brand-400/50' : 'border-white/10' }}">
                        <span class="flex items-center gap-3">
                            <span :class="active === {{ $i }} ? 'bg-brand-400 text-brand-900' : 'bg-white/5 text-brand-300'"
                                  class="hidden lg:flex shrink-0 w-10 h-10 rounded-xl items-center justify-center transition-colors {{ $i === 0 ? 'bg-brand-400 text-brand-900' : 'bg-white/5 text-brand-300' }}">
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
                                <span class="wd-fill block h-full bg-brand-400" style="--d: 6s"></span>
                            </span>
                        </template>
                    </button>
                @endforeach
            </div>

            {{-- The stage: the active service, as a working mock-up. --}}
            <div id="wd-stage" role="tabpanel" :aria-labelledby="'wd-tab-' + active" aria-labelledby="wd-tab-0">
                <div class="relative aspect-[4/3.4] sm:aspect-[4/3] rounded-[1.75rem] bg-gradient-to-br from-brand-800 via-brand-800/70 to-brand-900 ring-1 ring-white/10 shadow-2xl shadow-black/40 overflow-hidden">
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
                        @if ($topViews)
                            <div class="hidden sm:block wd-in max-w-[11rem]" style="animation-delay: 1.6s">
                                <p class="text-4xl font-extrabold text-brand-300">{{ $topViews }}</p>
                                <p class="mt-1 text-sm text-brand-100/70">views on one reel we made. Built hook first, for the sound-off scroll.</p>
                            </div>
                        @endif
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

                    {{-- 04 · Editing: clips land on the timeline, the playhead runs, the audio moves. --}}
                    <template x-if="active === 3"><div class="absolute inset-0 flex flex-col justify-center gap-4 p-5 sm:p-8" aria-hidden="true">
                        <div class="wd-in relative mx-auto w-[62%] aspect-video rounded-xl bg-brand-900 ring-1 ring-white/10 overflow-hidden">
                            @if ($cover(2))
                                <img src="{{ $cover(2) }}" alt="" class="absolute inset-0 w-full h-full object-cover" loading="lazy">
                            @endif
                            <span class="absolute left-2 top-2 rounded bg-black/60 px-1.5 py-0.5 text-[10px] font-bold tabular-nums">00:12:08</span>
                            <span class="absolute right-2 top-2 rounded bg-brand-400 text-brand-900 px-1.5 py-0.5 text-[10px] font-bold">COLOUR ✓</span>
                        </div>
                        <div class="wd-in relative rounded-xl bg-black/40 ring-1 ring-white/10 p-3 space-y-2" style="animation-delay: .2s">
                            @foreach ([['V2', [[22, 18, 'bg-violet-400'], [55, 16, 'bg-violet-400']]], ['V1', [[0, 30, 'bg-brand-400'], [31, 22, 'bg-brand-300'], [54, 26, 'bg-brand-500'], [81, 19, 'bg-brand-300']]], ['T', [[6, 24, 'bg-amber-300'], [44, 30, 'bg-amber-300']]]] as $t => [$track, $clips])
                                <div class="flex items-center gap-2">
                                    <span class="w-6 text-[10px] font-bold text-brand-200">{{ $track }}</span>
                                    <div class="relative flex-1 h-6">
                                        @foreach ($clips as $c => [$left, $width, $colour])
                                            <span class="wd-slide absolute inset-y-0 rounded-md {{ $colour }}" style="left: {{ $left }}%; width: {{ $width }}%; animation-delay: {{ 0.3 + ($t * 4 + $c) * 0.15 }}s"></span>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                            <div class="flex items-center gap-2">
                                <span class="w-6 text-[10px] font-bold text-brand-200">A1</span>
                                <div class="flex-1 h-7 flex items-center gap-[3px]">
                                    @for ($b = 0; $b < 40; $b++)
                                        <span class="wd-wave flex-1 rounded-full bg-emerald-300/80" style="height: {{ 30 + (($b * 37) % 70) }}%; animation-delay: {{ ($b % 7) * 0.12 }}s"></span>
                                    @endfor
                                </div>
                            </div>
                            <div class="pointer-events-none absolute top-1 bottom-1 left-[2.75rem] right-3">
                                <span class="wd-head absolute top-0 bottom-0 w-0.5 bg-red-400"><span class="absolute -top-1 -left-[5px] w-3 h-3 rotate-45 bg-red-400"></span></span>
                            </div>
                        </div>
                    </div></template>

                    {{-- 05 · Stills: a grid of shots, popping in with the flash. --}}
                    <template x-if="active === 4"><div class="absolute inset-0 flex items-center justify-center p-5 sm:p-8" aria-hidden="true">
                        <div class="grid grid-cols-3 gap-2 sm:gap-3 w-full max-w-sm">
                            @for ($s = 0; $s < 9; $s++)
                                <div class="wd-pop aspect-square rounded-lg overflow-hidden bg-brand-700 ring-2 ring-white shadow-lg" style="--r: {{ [-3, 2, -1, 3, -2, 1, -3, 2, -1][$s] }}deg; animation-delay: {{ 0.15 + $s * 0.18 }}s">
                                    @if ($cover(3 + $s))
                                        <img src="{{ $cover(3 + $s) }}" alt="" class="w-full h-full object-cover" loading="lazy">
                                    @endif
                                </div>
                            @endfor
                        </div>
                        <div class="wd-flash absolute inset-0 bg-white opacity-0" style="animation-delay: .1s"></div>
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
