{{--
    /showreel -- a 15-second motion-graphics film, then a landing page.

    The film is five scenes on one stage, played by resources/js/showreel.js:
      0–2.8s   the wheel (chakra) and the name
      2.4–6.3  three parallax rows of real reels, "Reels that stop the scroll."
      6.3–9.6  the numbers, counted from the database
      9.6–12.7 client logos, flying in at different depths
      12.7–15  the call to action
    Every element is rendered here, server-side, so Tailwind sees its classes;
    the script only moves them. Without the script the stage shows its last
    frame -- the call to action over the rows -- and nothing is lost.

    Data: ShowreelController. Colours are the brand scale in tailwind.config.js
    (brand-900 #132A38 ground, brand-400 #67BCD4 accent), type is Poppins.
--}}
@php
    $startProject = route('home', ['from' => 'showreel']).'#contact';
    $headline = ['Reels', 'that', 'stop', 'the', 'scroll.'];
    $endline = ["Let's", 'make', 'yours.'];

    // Visual order top to bottom: back (small, far), front (big), middle.
    $rowConfig = [
        ['index' => 2, 'height' => 'h-[19vh]', 'tone' => 'opacity-40 blur-[1.5px]', 'speed' => 26, 'dir' => 1, 'depth' => 0.3],
        ['index' => 0, 'height' => 'h-[38vh]', 'tone' => '', 'speed' => 70, 'dir' => -1, 'depth' => 1],
        ['index' => 1, 'height' => 'h-[27vh]', 'tone' => 'opacity-70', 'speed' => 44, 'dir' => 1, 'depth' => 0.6],
    ];

    // The logo strip has to be wider than any screen to loop without a gap.
    $marquee = collect();
    while ($clients->isNotEmpty() && $marquee->count() < 12) {
        $marquee = $marquee->concat($clients);
    }
@endphp

<x-public-layout title="Showreel — Chakra Productions"
                 description="Fifteen seconds of what we make: reels watched millions of times, for the brands we create for.">
    @push('styles')
        <style>
            @keyframes sr-spin { to { transform: rotate(360deg); } }
            .sr-spin { animation: sr-spin 40s linear infinite; }
            .sr-spin-rev { animation: sr-spin 26s linear infinite reverse; }
            .sr-spin-slow { animation: sr-spin 90s linear infinite; }
            @keyframes sr-bob { 0%, 100% { transform: translateY(-5px); } 50% { transform: translateY(5px); } }
            .sr-bob { animation: sr-bob 4.5s ease-in-out infinite; }
            @keyframes sr-marquee { to { transform: translateX(-50%); } }
            .sr-marquee { animation: sr-marquee 50s linear infinite; }
            .sr-marquee:hover { animation-play-state: paused; }
            @keyframes sr-cue { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(6px); } }
            .sr-cue { animation: sr-cue 1.6s ease-in-out infinite; }

            /* Film grain: a static noise tile, blended over everything. */
            .sr-grain {
                background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='160'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='2' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
                opacity: 0.06;
                mix-blend-mode: overlay;
            }

            /* Without the script only the last frame shows; while it loads,
               nothing does (the inline script below adds .sr-js at once, so
               the last frame never flashes before the first). */
            .sr-stage:not(.sr-ready) [data-scene]:not([data-scene="end"]) { visibility: hidden; }
            .sr-js:not(.sr-ready) [data-scene="end"] { visibility: hidden; }

            [data-sr-row], [data-sr-word], [data-sr-letter], [data-sr-logo] { will-change: transform; }

            @media (prefers-reduced-motion: reduce) {
                .sr-spin, .sr-spin-rev, .sr-spin-slow, .sr-bob, .sr-marquee, .sr-cue { animation: none; }
            }
        </style>
    @endpush

    {{-- ================================================================ the film --}}
    <section data-showreel
             class="sr-stage relative isolate overflow-hidden bg-brand-900 select-none h-[calc(100svh-4rem)] sm:h-[calc(100svh-5rem)] min-h-[560px]"
             aria-label="Chakra Productions showreel">
        <script>
            (function (stage) {
                stage.classList.add('sr-js');
                setTimeout(function () { if (! stage.classList.contains('sr-ready')) stage.classList.remove('sr-js'); }, 6000);
            })(document.currentScript.parentElement);
        </script>

        <h1 class="sr-only">Chakra Productions — showreel</h1>
        <p class="sr-only">
            A video content studio. Idea to posted.
            @foreach ($stats as $stat) {{ $stat['display'] }} {{ $stat['label'] }}. @endforeach
            @if ($clients->isNotEmpty()) Brands we create for: {{ $clients->pluck('name')->join(', ') }}. @endif
        </p>

        {{-- Ground, and a slow light behind everything. --}}
        <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_50%_45%,#284250_0%,#132A38_62%)]" aria-hidden="true"></div>
        <div data-sr="glow" class="absolute left-1/2 top-1/2 w-[70vmin] h-[70vmin] -ml-[35vmin] -mt-[35vmin] rounded-full bg-brand-400/20 blur-3xl" aria-hidden="true"></div>

        {{-- The work: three rows at three depths, tilted, drifting. --}}
        @if ($works->isNotEmpty())
            <div data-sr="work" class="absolute inset-0" aria-hidden="true">
                <div class="absolute -inset-[30%] flex flex-col justify-center gap-[2.5vh] -rotate-[9deg]">
                    @foreach ($rowConfig as $row)
                        <div data-sr-row data-speed="{{ $row['speed'] }}" data-dir="{{ $row['dir'] }}" data-depth="{{ $row['depth'] }}"
                             class="flex w-max {{ $row['tone'] }}">
                            <div data-sr-set class="flex shrink-0">
                                @foreach ($rows[$row['index']]->isNotEmpty() ? $rows[$row['index']] : $works as $work)
                                    <div class="shrink-0 pr-[1.6vh]">
                                        <div class="{{ $row['height'] }} aspect-[9/16] rounded-xl overflow-hidden ring-1 ring-white/10 shadow-2xl shadow-black/50 bg-brand-800">
                                            <img src="{{ $work['cover'] }}" alt="" class="w-full h-full object-cover" decoding="async" draggable="false">
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Darkens the rows under the later scenes. Its class is the
             no-script frame; the script drives it from there. --}}
        <div data-sr="veil" class="absolute inset-0 bg-brand-900 opacity-80" aria-hidden="true"></div>
        <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_center,transparent_45%,rgba(8,20,28,0.85)_100%)]" aria-hidden="true"></div>
        <div class="sr-grain pointer-events-none absolute inset-0" aria-hidden="true"></div>

        {{-- 1 · The wheel and the name. --}}
        <div data-scene="intro" class="absolute inset-0 flex flex-col items-center justify-center text-center px-6 pointer-events-none" aria-hidden="true">
            <div data-sr="ring" class="absolute inset-0 m-auto w-[min(80vmin,640px)] h-[min(80vmin,640px)] text-brand-400">
                <svg viewBox="0 0 200 200" class="sr-spin absolute inset-0 w-full h-full">
                    <circle cx="100" cy="100" r="97" fill="none" stroke="currentColor" stroke-width="0.5" stroke-dasharray="1.5 3.5" opacity="0.7" />
                </svg>
                <svg viewBox="0 0 200 200" class="sr-spin-rev absolute inset-0 w-full h-full">
                    <circle cx="100" cy="100" r="86" fill="none" stroke="currentColor" stroke-width="1.2" stroke-dasharray="46 10" opacity="0.45" />
                </svg>
                <svg viewBox="0 0 200 200" class="sr-spin-slow absolute inset-0 w-full h-full">
                    @for ($i = 0; $i < 24; $i++)
                        <line x1="100" y1="30" x2="100" y2="54" stroke="currentColor" stroke-width="0.6" opacity="0.35" transform="rotate({{ $i * 15 }} 100 100)" />
                    @endfor
                    <circle cx="100" cy="100" r="70" fill="none" stroke="currentColor" stroke-width="0.4" opacity="0.4" />
                </svg>
            </div>

            <div class="relative flex flex-col items-center">
                <img data-sr="mark" src="{{ asset('images/chakra-watermark.png') }}" alt="" draggable="false"
                     class="h-[clamp(64px,12vh,118px)] w-auto mb-[2vh]">
                <div class="overflow-hidden py-[0.04em]">
                    <p class="flex justify-center text-[clamp(3.4rem,15vw,10.5rem)] font-extrabold tracking-tight leading-[0.92] text-white">
                        @foreach (mb_str_split('CHAKRA') as $letter)
                            <span data-sr-letter class="inline-block">{{ $letter }}</span>
                        @endforeach
                    </p>
                </div>
                <p data-sr="sub" class="mt-[1.4vh] pl-[0.45em] text-[clamp(0.85rem,2.6vw,1.6rem)] font-semibold uppercase tracking-[0.45em] text-brand-300">
                    Productions
                </p>
                <p data-sr="tag" class="mt-[2.6vh] text-[clamp(0.9rem,2vw,1.15rem)] text-brand-100/75">
                    A video content studio. Idea to posted.
                </p>
            </div>
        </div>

        {{-- 2 · The work, named. --}}
        <div data-scene="work" class="absolute inset-0 flex flex-col items-center justify-center text-center px-6 pointer-events-none" aria-hidden="true">
            <div data-sr="shade" class="absolute inset-0 bg-[radial-gradient(ellipse_at_center,rgba(19,42,56,0.88)_0%,rgba(19,42,56,0)_62%)]"></div>
            <p data-sr="kicker" class="relative text-xs sm:text-sm font-semibold uppercase tracking-[0.3em] text-brand-300">Our work</p>
            <p class="relative mt-3 max-w-5xl text-[clamp(2.4rem,8vw,6.5rem)] font-extrabold leading-[1.04] tracking-tight">
                @foreach ($headline as $word)
                    <span class="inline-block overflow-hidden align-bottom pb-[0.1em] -mb-[0.1em]"><span data-sr-word="work" class="inline-block {{ $loop->last ? 'text-brand-400' : 'text-white' }}">{{ $word }}</span></span>
                @endforeach
            </p>
        </div>

        {{-- 3 · The numbers. --}}
        @if ($stats)
            <div data-scene="stats" class="absolute inset-0 flex items-center justify-center px-6 pointer-events-none" aria-hidden="true">
                <div class="grid w-full max-w-6xl gap-[4.5vh] sm:gap-10 text-center grid-cols-1 {{ [1 => 'sm:grid-cols-1', 2 => 'sm:grid-cols-2', 3 => 'sm:grid-cols-3'][count($stats)] }}">
                    @foreach ($stats as $stat)
                        <div data-sr-stat>
                            <p data-sr-count="{{ $stat['display'] }}" class="text-[clamp(2.8rem,9vw,6.5rem)] font-extrabold leading-none tracking-tight tabular-nums text-white">
                                {{ $stat['display'] }}
                            </p>
                            <div class="mx-auto mt-3 sm:mt-4 h-0.5 w-12 bg-brand-400"></div>
                            <p class="mt-3 text-sm sm:text-base text-brand-100/75">{{ $stat['label'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- 4 · The clients. --}}
        @if ($clients->isNotEmpty())
            <div data-scene="clients" class="absolute inset-0 flex flex-col items-center justify-center px-5 pointer-events-none" aria-hidden="true">
                <p data-sr="clients-head" class="text-xs sm:text-sm font-semibold uppercase tracking-[0.3em] text-brand-300">Brands we create for</p>
                <div class="mt-[4vh] flex flex-wrap justify-center gap-3 sm:gap-5 max-w-5xl">
                    @foreach ($clients->take(10) as $client)
                        <div data-sr-logo data-depth="{{ [0.6, 1.2, 0.8, 1.4, 0.7][$loop->index % 5] }}">
                            <div class="sr-bob" style="animation-delay: -{{ $loop->index * 0.7 }}s">
                                @include('showreel._client-tile', ['client' => $client, 'size' => 'w-[clamp(120px,22vw,190px)] h-[clamp(72px,12vw,112px)]'])
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- 5 · The ask. Also the frame shown when the script never runs. --}}
        <div data-scene="end" class="absolute inset-0 flex flex-col items-center justify-center text-center px-6 pointer-events-none">
            <p data-sr="end-kicker" class="text-xs sm:text-sm font-semibold uppercase tracking-[0.3em] text-brand-300">Chakra Productions</p>
            <h2 class="mt-3 text-[clamp(2.8rem,10vw,7.5rem)] font-extrabold leading-[1.02] tracking-tight">
                @foreach ($endline as $word)
                    <span class="inline-block overflow-hidden align-bottom pb-[0.1em] -mb-[0.1em]"><span data-sr-word="end" class="inline-block {{ $loop->last ? 'text-brand-400' : 'text-white' }}">{{ $word }}</span></span>
                @endforeach
            </h2>
            <p data-sr="end-sub" class="mt-5 max-w-xl text-base sm:text-lg text-brand-100/75">
                Scripted, shot, edited and posted by one team — for brands that need to show up every week.
            </p>
            <div data-sr="end-ctas" class="mt-8 flex flex-col sm:flex-row gap-3 pointer-events-auto">
                <a href="{{ $startProject }}"
                   class="inline-flex items-center justify-center min-h-[52px] px-8 rounded-md bg-brand-400 text-brand-900 text-sm font-semibold uppercase tracking-widest hover:bg-brand-500 transition-colors">
                    Start a project
                </a>
                @if ($works->isNotEmpty())
                    <a href="#work"
                       class="inline-flex items-center justify-center min-h-[52px] px-8 rounded-md border border-white/25 text-white text-sm font-semibold uppercase tracking-widest hover:bg-white/10 transition-colors">
                        See the work
                    </a>
                @endif
            </div>
        </div>

        {{-- Controls: where the film is, skip, replay. --}}
        <a href="#work" data-sr="cue" class="sr-cue absolute left-1/2 bottom-16 -ml-5 z-20 hidden sm:flex items-center justify-center w-10 h-10 rounded-full border border-white/25 text-white/80 opacity-0" aria-label="Scroll to the work">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
        </a>
        <div class="absolute inset-x-0 bottom-0 z-20">
            <div class="flex items-center justify-between gap-3 px-4 sm:px-6 pb-3">
                <p class="text-[11px] font-semibold uppercase tracking-[0.25em] text-brand-100/50 tabular-nums" aria-hidden="true">
                    Showreel <span data-sr-clock>0:15</span> / 0:15
                </p>
                <div class="flex items-center gap-2">
                    <button type="button" data-sr-skip hidden
                            class="min-h-[40px] px-4 rounded-full bg-white/10 text-[11px] font-semibold uppercase tracking-widest text-white hover:bg-white/20 transition-colors">
                        Skip
                    </button>
                    <button type="button" data-sr-replay hidden
                            class="inline-flex items-center gap-1.5 min-h-[40px] px-4 rounded-full bg-white/10 text-[11px] font-semibold uppercase tracking-widest text-white hover:bg-white/20 transition-colors">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v6h6M20 20v-6h-6M5.6 15a7 7 0 0011.8 2.4M18.4 9A7 7 0 006.6 6.6" /></svg>
                        Replay
                    </button>
                </div>
            </div>
            <div class="h-1 bg-white/10"><div data-sr-progress class="h-full bg-brand-400 origin-left"></div></div>
        </div>
    </section>

    {{-- ================================================================ the page --}}
    @if ($works->isNotEmpty())
        <section id="work" class="relative scroll-mt-20 overflow-hidden">
            <div class="max-w-7xl mx-auto px-4 sm:px-8 py-20 sm:py-28">
                <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-5 mb-12 sm:mb-16">
                    <div>
                        <p class="text-brand-300 text-xs font-semibold uppercase tracking-[0.25em] mb-3">Selected work</p>
                        <h2 class="text-3xl sm:text-5xl font-extrabold max-w-2xl leading-[1.1]">Made for the feed. Watched by millions.</h2>
                    </div>
                    <a href="{{ route('portfolio') }}"
                       class="shrink-0 inline-flex items-center justify-center min-h-[48px] px-6 rounded-md border border-white/20 text-white text-sm font-semibold uppercase tracking-widest hover:bg-white/5 transition-colors">
                        Full portfolio
                    </a>
                </div>

                {{-- Columns that scroll at different speeds. Three on a wide
                     screen, two on a phone -- two layouts, so nothing is lost
                     in a hidden column. --}}
                @foreach ([3 => 'hidden lg:grid grid-cols-3 gap-6', 2 => 'grid lg:hidden grid-cols-2 gap-3 sm:gap-5'] as $columns => $classes)
                    <div class="{{ $classes }}">
                        @for ($column = 0; $column < $columns; $column++)
                            <div data-parallax="{{ [0, -0.12, 0.06][$column] }}" class="space-y-3 sm:space-y-6 {{ $column === 1 ? 'pt-14 sm:pt-24' : '' }}">
                                @foreach ($works->filter(fn ($work, $index) => $index % $columns === $column) as $work)
                                    @include('showreel._work-card', ['work' => $work])
                                @endforeach
                            </div>
                        @endfor
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- What we make, sliding past as the page scrolls. --}}
    @if ($categories->isNotEmpty())
        <section class="py-10 sm:py-14 border-y border-white/10 bg-brand-800/40 overflow-hidden space-y-4 sm:space-y-6" aria-label="What we make">
            @foreach ([0.35, -0.35] as $direction)
                <div data-scroll-x="{{ $direction }}" class="flex w-max items-center gap-6 sm:gap-10 whitespace-nowrap text-[clamp(1.9rem,6vw,4.5rem)] font-extrabold leading-none {{ $direction < 0 ? '-ml-[60vw]' : '' }}"
                     @if (! $loop->first) aria-hidden="true" @endif>
                    @foreach (($direction < 0 ? $categories->reverse() : $categories)->concat($categories) as $name)
                        <span class="{{ $loop->odd ? 'text-white' : 'text-transparent [-webkit-text-stroke:1.5px_#67BCD4]' }}">{{ $name }}</span>
                        <span class="text-brand-400 text-[0.5em]" aria-hidden="true">✦</span>
                    @endforeach
                </div>
            @endforeach
        </section>
    @endif

    @if ($clients->isNotEmpty())
        <section class="py-20 sm:py-24 overflow-hidden">
            <div class="max-w-7xl mx-auto px-5 sm:px-8 text-center">
                <p class="text-brand-300 text-xs font-semibold uppercase tracking-[0.25em] mb-3">Brands we create for</p>
                <h2 class="text-3xl sm:text-4xl font-bold">The businesses behind the views.</h2>
            </div>

            <div class="relative mt-12">
                <div class="sr-marquee flex w-max">
                    @foreach ([false, true] as $duplicate)
                        @foreach ($marquee as $client)
                            <div class="shrink-0 pr-4 sm:pr-6" @if ($duplicate) aria-hidden="true" @endif>
                                @include('showreel._client-tile', ['client' => $client, 'size' => 'w-[150px] sm:w-[190px] h-[90px] sm:h-[112px]'])
                            </div>
                        @endforeach
                    @endforeach
                </div>
                <div class="pointer-events-none absolute inset-y-0 left-0 w-16 sm:w-32 bg-gradient-to-r from-brand-900 to-transparent"></div>
                <div class="pointer-events-none absolute inset-y-0 right-0 w-16 sm:w-32 bg-gradient-to-l from-brand-900 to-transparent"></div>
            </div>
        </section>
    @endif

    <section class="relative overflow-hidden bg-brand-800/40 border-t border-white/10">
        <img data-parallax="0.18" src="{{ asset('images/chakra-watermark.png') }}" alt=""
             class="pointer-events-none select-none absolute -right-16 top-6 w-[20rem] max-w-none opacity-[0.06]">
        <div class="relative max-w-4xl mx-auto px-5 sm:px-8 py-24 sm:py-32 text-center">
            <p class="text-brand-300 text-xs font-semibold uppercase tracking-[0.25em] mb-4">Your turn</p>
            <h2 class="text-4xl sm:text-6xl font-extrabold leading-[1.08]">
                Let's make your brand's <span class="text-brand-400">next reel</span>.
            </h2>
            <p class="mt-6 text-lg text-brand-100/70 max-w-2xl mx-auto leading-relaxed">
                A rough idea is enough to start. Tell us what it is for and roughly when you need it,
                and we will come back with how we would approach it.
            </p>
            <div class="mt-10 flex flex-col sm:flex-row gap-3 justify-center">
                <a href="{{ $startProject }}"
                   class="inline-flex items-center justify-center min-h-[52px] px-8 rounded-md bg-brand-400 text-brand-900 text-sm font-semibold uppercase tracking-widest hover:bg-brand-500 transition-colors">
                    Start a project
                </a>
                <a href="{{ route('portfolio') }}"
                   class="inline-flex items-center justify-center min-h-[52px] px-8 rounded-md border border-white/20 text-white text-sm font-semibold uppercase tracking-widest hover:bg-white/5 transition-colors">
                    See the full portfolio
                </a>
            </div>
        </div>
    </section>
</x-public-layout>
