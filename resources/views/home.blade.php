{{--
    The homepage (LandingController; /showreel shows it while signed in).

    It opens on a full-screen motion-graphics film that plays as you scroll:
    the stage is pinned while a tall track scrolls past it, and the track's
    progress is the film's clock (resources/js/showreel.js). The name plays
    by itself; scrolling plays the rest, and scrolling back rewinds it.
      0–3.2s    the opening, on its own: black and letterboxed, the logo
                lands piece by piece, lightning backlights the camera, then
                the bars open and the colour floods in -- "Digital Chaos"
      3.2–7.5   three parallax rows of real reels, "Reels that stop the scroll."
      7.5–10.8  the numbers, counted from the database
      10.8–13.9 client logos, flying in at different depths
      13.9–16.2 the call to action
    Then the landing page: work, what we make, services and process, the
    team, clients, and the enquiry form.

    Every element is rendered here so Tailwind sees its classes; the script
    only moves them. Without it -- or for someone who asked their device for
    less motion -- the film is one screen showing its last frame.

    Data: App\Support\HomePage. Colours: the brand scale (brand-900 #132A38
    ground, brand-400 #67BCD4 accent). Type: Poppins.
--}}
@php
    /*
    | Contact details. Anything left null simply does not render.
    */
    $contact = [
        'email' => null,      // 'hello@chakragroups.in'
        'phone' => null,      // '+91 98765 43210'
        'whatsapp' => null,   // '919876543210' - digits only, no + or spaces
        'address' => null,    // 'Manapparai, Tamil Nadu'
    ];

    $social = [
        'Instagram' => null,  // 'https://instagram.com/...'
        'YouTube' => null,    // 'https://youtube.com/@...'
        'LinkedIn' => null,
    ];

    $services = [
        ['Short-form video', 'Reels, Shorts and stories cut for the feed - hook first, built to be watched with the sound off.'],
        ['YouTube long-form', 'Scripted episodes, interviews and series, with show notes and thumbnails handled end to end.'],
        ['Scripting & story', 'From a rough idea to a shooting script, so the shoot day has a plan instead of a vibe.'],
        ['Editing & post', 'Cut, colour, sound and captions. Consistent output whether it is one film or a monthly slate.'],
        ['Stills & static', 'Photography and designed posts for the grid, shot alongside the video so it all matches.'],
        ['Publishing & scheduling', 'We can take it all the way to posted and scheduled, not just hand over a folder of files.'],
    ];

    // The pipeline every piece of content moves through in the studio.
    $process = ['Idea', 'Script', 'Shoot', 'Edit', 'Review', 'Publish'];

    // Until people are added in Team Page, the crew is described by what it
    // does -- the same pipeline -- rather than by invented faces.
    $crew = [
        ['Script', 'Writers who turn a rough idea into a shooting script before anyone books a camera.'],
        ['Shoot', 'Camera crew on planned shoot days, against the script, so nothing is discovered on set.'],
        ['Edit', 'Editors who cut, colour, caption and mix — one film or a monthly slate.'],
        ['Publish', 'Social media managers who schedule and post it, then report back on how it did.'],
    ];

    $headline = ['Reels', 'that', 'stop', 'the', 'scroll.'];
    $endline = ["Let's", 'make', 'yours.'];

    // Visual order top to bottom: back (small, far), front (big), middle.
    $rowConfig = [
        ['index' => 2, 'height' => 'h-[17vh] sm:h-[19vh]', 'tone' => 'opacity-40 blur-[1.5px]', 'speed' => 26, 'dir' => 1, 'depth' => 0.3],
        ['index' => 0, 'height' => 'h-[34vh] sm:h-[38vh]', 'tone' => '', 'speed' => 64, 'dir' => -1, 'depth' => 1],
        ['index' => 1, 'height' => 'h-[24vh] sm:h-[27vh]', 'tone' => 'opacity-70', 'speed' => 42, 'dir' => 1, 'depth' => 0.6],
    ];

    // The logo strip has to be wider than any screen to loop without a gap.
    $marquee = collect();
    while ($clients->isNotEmpty() && $marquee->count() < 12) {
        $marquee = $marquee->concat($clients);
    }

    $cardScroller = 'flex gap-4 overflow-x-auto snap-x snap-mandatory scroll-px-5 px-5 pb-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden sm:px-8 sm:scroll-px-8';
@endphp

<x-public-layout
    title="Chakra Productions — Best Digital Marketing in Manapparai, Trichy and More"
    description="Chakra Productions is a content and digital marketing studio serving Manapparai, Trichy and surrounding Tamil Nadu &mdash; short-form video, YouTube, scripting, editing, social media management and publishing, taken from idea to posted.">

    @push('styles')
        <link rel="canonical" href="{{ url('/') }}">
        <style>
            /* Dust in the projector beam: drifting up, glinting as it turns. */
            @keyframes sr-dust { 0% { transform: translate3d(0, 0, 0); opacity: 0; } 20% { opacity: .8; } 50% { opacity: .25; } 80% { opacity: .7; } 100% { transform: translate3d(14px, -60px, 0); opacity: 0; } }
            .sr-dust { animation: sr-dust 4.2s ease-in-out infinite; box-shadow: 0 0 6px rgba(171, 218, 231, .9); }
            @keyframes sr-bob { 0%, 100% { transform: translateY(-5px); } 50% { transform: translateY(5px); } }
            .sr-bob { animation: sr-bob 4.5s ease-in-out infinite; }
            @keyframes sr-marquee { to { transform: translateX(-50%); } }
            .sr-marquee { animation: sr-marquee 50s linear infinite; }
            .sr-marquee:hover { animation-play-state: paused; }
            @keyframes sr-cue { 0%, 100% { transform: translateY(0); opacity: 1; } 50% { transform: translateY(7px); opacity: .5; } }
            .sr-cue-dot { animation: sr-cue 1.6s ease-in-out infinite; }

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

            [data-sr-row], [data-sr-word], [data-sr-logo], [data-sr="logo"] > img { will-change: transform, filter; }

            @media (prefers-reduced-motion: reduce) {
                .sr-dust, .sr-bob, .sr-marquee, .sr-cue-dot { animation: none; }
            }
        </style>
    @endpush

    {{-- Local-business structured data -- tells Google which towns we serve. --}}
    <script type="application/ld+json">
        {!! json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'MarketingAgency',
            'name' => 'Chakra Productions',
            'description' => 'Content and digital marketing studio: short-form video, YouTube, scripting, editing and social media publishing.',
            'url' => url('/'),
            'image' => asset('images/og-image.png'),
            'areaServed' => [
                ['@type' => 'City', 'name' => 'Manapparai'],
                ['@type' => 'City', 'name' => 'Trichy'],
                ['@type' => 'State', 'name' => 'Tamil Nadu'],
            ],
            ...($contact['address'] ? ['address' => [
                '@type' => 'PostalAddress',
                'addressLocality' => $contact['address'],
                'addressCountry' => 'IN',
            ]] : []),
            ...array_filter(['email' => $contact['email'], 'telephone' => $contact['phone']]),
        ], JSON_UNESCAPED_SLASHES) !!}
    </script>

    {{-- ================================================================ the film --}}
    {{-- The track is one screen tall until the script makes it long enough to
         scroll the film through; the stage stays pinned while it does. --}}
    <section data-showreel-track class="relative bg-brand-900" style="height: 100vh; height: 100svh" aria-label="Chakra Productions showreel">
        <div data-showreel class="sr-stage sticky top-0 isolate overflow-hidden bg-brand-900 select-none" style="height: 100vh; height: 100svh">
            <script>
                (function (stage) {
                    stage.classList.add('sr-js');
                    setTimeout(function () { if (! stage.classList.contains('sr-ready')) stage.classList.remove('sr-js'); }, 6000);
                })(document.currentScript.parentElement);
            </script>

            <h1 class="sr-only">Chakra Productions — Digital Chaos. A video content studio.</h1>
            <p class="sr-only">
                @foreach ($stats as $stat) {{ $stat['display'] }} {{ $stat['label'] }}. @endforeach
                @if ($clients->isNotEmpty()) Brands we create for: {{ $clients->pluck('name')->join(', ') }}. @endif
            </p>

            {{-- Ground, and a slow light behind everything. --}}
            <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_50%_45%,#284250_0%,#132A38_62%)]" aria-hidden="true"></div>
            <div data-sr="glow" class="absolute left-1/2 top-1/2 w-[70vmin] h-[70vmin] -ml-[35vmin] -mt-[35vmin] rounded-full bg-brand-400/20 blur-3xl" aria-hidden="true"></div>

            {{-- The work: three rows at three depths, tilted, drifting. --}}
            @if ($film->isNotEmpty())
                <div data-sr="work" class="absolute inset-0" aria-hidden="true">
                    <div class="absolute -inset-[30%] flex flex-col justify-center gap-[2.2vh] -rotate-[9deg]">
                        @foreach ($rowConfig as $row)
                            <div data-sr-row data-speed="{{ $row['speed'] }}" data-dir="{{ $row['dir'] }}" data-depth="{{ $row['depth'] }}"
                                 class="flex w-max {{ $row['tone'] }}">
                                <div data-sr-set class="flex shrink-0">
                                    @foreach ($rows[$row['index']]->isNotEmpty() ? $rows[$row['index']] : $film as $work)
                                        <div class="shrink-0 pr-[1.5vh]">
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

            {{-- The opening is black: this covers the ground, the light and
                 the rows until the logo is in, then lifts so colour floods in. --}}
            <div data-sr="blackout" class="absolute inset-0 bg-black opacity-0" aria-hidden="true"></div>

            {{-- 1 · The logo, in the dark, then the light. --}}
            <div data-scene="intro" class="absolute inset-0 flex flex-col items-center justify-center text-center px-4 pt-10 pointer-events-none" aria-hidden="true">
                {{-- A projector's beam from above, with dust drifting in it. --}}
                <div data-sr="beam" class="absolute left-1/2 top-0 -ml-[60vmax] w-[120vmax] h-full bg-[radial-gradient(ellipse_32%_95%_at_50%_0%,rgba(171,218,231,0.26)_0%,rgba(103,188,212,0.10)_45%,transparent_75%)]"></div>
                <div data-sr="dust" class="absolute inset-0 overflow-hidden">
                    @foreach ([[12, 30, 2, 0], [22, 62, 3, 1.4], [31, 18, 2, 2.6], [38, 78, 2, 0.8], [44, 40, 3, 3.1], [50, 70, 2, 1.9], [56, 24, 2, 0.4], [61, 55, 3, 2.2], [67, 82, 2, 1.1], [73, 35, 2, 2.9], [79, 66, 3, 0.6], [86, 20, 2, 1.7], [47, 88, 2, 3.4], [28, 48, 2, 2.0]] as [$left, $top, $size, $delay])
                        <span class="sr-dust absolute rounded-full bg-brand-100" style="left: {{ $left }}%; top: {{ $top }}%; width: {{ $size }}px; height: {{ $size }}px; animation-delay: -{{ $delay }}s"></span>
                    @endforeach
                </div>

                <div class="relative flex flex-col items-center">
                    @include('home._logo')
                    <p data-sr="tag" class="mt-[3.5vh] text-[clamp(1rem,4.8vw,1.6rem)] font-extrabold uppercase tracking-[0.35em] pl-[0.35em] text-white">
                        Digital <span class="text-brand-400">Chaos</span>
                    </p>
                </div>

                {{-- An anamorphic streak of light across the logo as the colour arrives. --}}
                <div data-sr="streak" class="absolute left-0 right-0 top-1/2 -mt-px h-[2px] opacity-0">
                    <div class="mx-auto h-full w-[70%] bg-gradient-to-r from-transparent via-brand-100 to-transparent shadow-[0_0_18px_4px_rgba(171,218,231,0.7)]"></div>
                </div>

                {{-- The lightning's flash, over the whole stage. --}}
                <div data-sr="flash" class="absolute inset-0 bg-white opacity-0"></div>
            </div>

            {{-- Cinema bars: the opening is letterboxed, and they open as the colour comes in. --}}
            <div data-scene="bars" class="absolute inset-0 z-10 pointer-events-none" aria-hidden="true">
                <div data-sr="bar-top" class="absolute inset-x-0 top-0 h-[12vh] bg-black"></div>
                <div data-sr="bar-bottom" class="absolute inset-x-0 bottom-0 h-[12vh] bg-black"></div>
            </div>

            {{-- 2 · The work, named. --}}
            <div data-scene="work" class="absolute inset-0 flex flex-col items-center justify-center text-center px-5 pointer-events-none" aria-hidden="true">
                <div data-sr="shade" class="absolute inset-0 bg-[radial-gradient(ellipse_at_center,rgba(19,42,56,0.9)_0%,rgba(19,42,56,0)_68%)]"></div>
                <p data-sr="kicker" class="relative text-xs sm:text-sm font-semibold uppercase tracking-[0.3em] text-brand-300">Our work</p>
                <p class="relative mt-3 max-w-5xl text-[clamp(2.5rem,11vw,6.5rem)] font-extrabold leading-[1.04] tracking-tight">
                    @foreach ($headline as $word)
                        <span class="inline-block overflow-hidden align-bottom pb-[0.1em] -mb-[0.1em]"><span data-sr-word="work" class="inline-block {{ $loop->last ? 'text-brand-400' : 'text-white' }}">{{ $word }}</span></span>
                    @endforeach
                </p>
            </div>

            {{-- 3 · The numbers. --}}
            @if ($stats)
                <div data-scene="stats" class="absolute inset-0 flex items-center justify-center px-6 pt-12 pb-8 pointer-events-none" aria-hidden="true">
                    <div class="grid w-full max-w-6xl gap-[3.5vh] sm:gap-10 text-center grid-cols-1 {{ [1 => 'sm:grid-cols-1', 2 => 'sm:grid-cols-2', 3 => 'sm:grid-cols-3'][count($stats)] }}">
                        @foreach ($stats as $stat)
                            <div data-sr-stat>
                                <p data-sr-count="{{ $stat['display'] }}" class="text-[clamp(2.6rem,min(13vw,9vh),6.5rem)] font-extrabold leading-none tracking-tight tabular-nums text-white">
                                    {{ $stat['display'] }}
                                </p>
                                <div class="mx-auto mt-2.5 sm:mt-4 h-0.5 w-10 sm:w-12 bg-brand-400"></div>
                                <p class="mt-2 sm:mt-3 text-sm sm:text-base text-brand-100/80">{{ $stat['label'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- 4 · The clients. --}}
            @if ($clients->isNotEmpty())
                <div data-scene="clients" class="absolute inset-0 flex flex-col items-center justify-center px-4 pt-12 pb-8 pointer-events-none" aria-hidden="true">
                    <p data-sr="clients-head" class="text-xs sm:text-sm font-semibold uppercase tracking-[0.3em] text-brand-300">Brands we create for</p>
                    <div class="mt-[3.5vh] grid grid-cols-2 sm:flex sm:flex-wrap justify-center gap-3 sm:gap-5 max-w-5xl">
                        @foreach ($clients->take(8) as $client)
                            <div data-sr-logo data-depth="{{ [0.6, 1.2, 0.8, 1.4, 0.7][$loop->index % 5] }}">
                                <div class="sr-bob" style="animation-delay: -{{ $loop->index * 0.7 }}s">
                                    @include('home._client-tile', ['client' => $client, 'size' => 'w-[clamp(132px,40vw,190px)] sm:w-[clamp(132px,22vw,190px)] h-[clamp(64px,10vh,112px)]'])
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- 5 · The ask. Also the frame shown when the script never runs. --}}
            <div data-scene="end" class="absolute inset-0 flex flex-col items-center justify-center text-center px-6 pt-12 pointer-events-none">
                <p data-sr="end-kicker" class="text-xs sm:text-sm font-semibold uppercase tracking-[0.3em] text-brand-300">Chakra Productions</p>
                <h2 class="mt-3 text-[clamp(2.8rem,13vw,7.5rem)] font-extrabold leading-[1.02] tracking-tight">
                    @foreach ($endline as $word)
                        <span class="inline-block overflow-hidden align-bottom pb-[0.1em] -mb-[0.1em]"><span data-sr-word="end" class="inline-block {{ $loop->last ? 'text-brand-400' : 'text-white' }}">{{ $word }}</span></span>
                    @endforeach
                </h2>
                <p data-sr="end-sub" class="mt-4 sm:mt-5 max-w-xl text-base sm:text-lg text-brand-100/80">
                    Scripted, shot, edited and posted by one team — for brands that need to show up every week.
                </p>
                <div data-sr="end-ctas" class="mt-7 sm:mt-8 flex flex-col sm:flex-row gap-3 w-full sm:w-auto max-w-xs sm:max-w-none pointer-events-auto">
                    <a href="#contact"
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

            {{-- "Scroll to play", while the film waits at the end of its intro. --}}
            <div data-sr="cue" class="absolute inset-x-0 bottom-7 z-20 flex flex-col items-center gap-2 pointer-events-none opacity-0" aria-hidden="true">
                <span class="flex justify-center w-6 h-10 rounded-full border-2 border-white/40 pt-2">
                    <span class="sr-cue-dot block w-1 h-2 rounded-full bg-white"></span>
                </span>
                <span class="text-[11px] font-semibold uppercase tracking-[0.3em] text-white/70">Scroll to play</span>
            </div>

            {{-- How far through the film the scroll is. --}}
            <div class="absolute inset-x-0 bottom-0 z-20 h-1 bg-white/10" aria-hidden="true">
                <div data-sr-progress class="h-full bg-brand-400 origin-left"></div>
            </div>
        </div>
    </section>
    <div data-film-end aria-hidden="true"></div>

    {{-- ================================================================ the page --}}
    @if ($works->isNotEmpty())
        <section id="work" class="relative scroll-mt-20 overflow-hidden">
            <div class="max-w-7xl mx-auto px-4 sm:px-8 py-16 sm:py-28">
                <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-5 mb-10 sm:mb-16">
                    <div>
                        <p class="text-brand-300 text-xs font-semibold uppercase tracking-[0.25em] mb-3">Selected work</p>
                        <h2 class="text-3xl sm:text-5xl font-extrabold max-w-2xl leading-[1.1]">Made for the feed. Watched by millions.</h2>
                    </div>
                    @if ($hasMorePortfolio)
                        <a href="{{ route('portfolio') }}"
                           class="self-start sm:self-auto shrink-0 inline-flex items-center justify-center min-h-[48px] px-6 rounded-md border border-white/20 text-white text-sm font-semibold uppercase tracking-widest hover:bg-white/5 transition-colors">
                            See more
                        </a>
                    @endif
                </div>

                {{-- Columns that scroll at different speeds. Three on a wide
                     screen, two on a phone -- two layouts, so nothing is lost
                     in a hidden column. --}}
                @foreach ([3 => 'hidden lg:grid grid-cols-3 gap-6', 2 => 'grid lg:hidden grid-cols-2 gap-3 sm:gap-5'] as $columns => $classes)
                    <div class="{{ $classes }}">
                        @for ($column = 0; $column < $columns; $column++)
                            <div data-parallax="{{ [0, -70, -30][$column] }}" class="space-y-3 sm:space-y-6 {{ $column === 1 ? 'pt-10 sm:pt-20' : '' }}">
                                @foreach ($works->filter(fn ($work, $index) => $index % $columns === $column) as $work)
                                    @include('home._work-card', ['work' => $work])
                                @endforeach
                            </div>
                        @endfor
                    </div>
                @endforeach

                <div class="mt-12 sm:mt-16 text-center">
                    <a href="{{ route('portfolio') }}"
                       class="inline-flex items-center justify-center min-h-[52px] px-8 rounded-md bg-brand-400 text-brand-900 text-sm font-semibold uppercase tracking-widest hover:bg-brand-500 transition-colors">
                        See the full portfolio
                    </a>
                </div>
            </div>
        </section>
    @endif

    {{-- What we make, sliding past as the page scrolls. --}}
    @if ($categories->isNotEmpty())
        <section class="py-8 sm:py-14 border-y border-white/10 bg-brand-800/40 overflow-hidden space-y-3 sm:space-y-6" aria-label="What we make">
            @foreach ([1, -1] as $direction)
                <div data-scroll-x="{{ $direction * 0.3 }}" class="flex w-max items-center gap-5 sm:gap-10 whitespace-nowrap text-[clamp(1.6rem,7vw,4.5rem)] font-extrabold leading-tight {{ $direction < 0 ? '-ml-[80vw]' : '' }}"
                     @if (! $loop->first) aria-hidden="true" @endif>
                    @foreach (($direction < 0 ? $categories->reverse() : $categories)->concat($categories) as $name)
                        <span class="{{ $loop->odd ? 'text-white' : 'text-transparent [-webkit-text-stroke:1.5px_#67BCD4]' }}">{{ $name }}</span>
                        <span class="text-brand-400 text-[0.5em]" aria-hidden="true">✦</span>
                    @endforeach
                </div>
            @endforeach
        </section>
    @endif

    {{-- What we do, and how. Swipeable cards on a phone, a grid on a desk. --}}
    <section id="services" class="scroll-mt-20 py-16 sm:py-24">
        <div class="max-w-7xl mx-auto px-5 sm:px-8">
            <p class="text-brand-300 text-xs font-semibold uppercase tracking-[0.25em] mb-3">What we do</p>
            <h2 class="text-3xl sm:text-4xl font-bold max-w-2xl leading-tight">Everything between the brief and the upload.</h2>
        </div>

        <div class="mt-10 max-w-7xl mx-auto lg:px-8">
            <div class="{{ $cardScroller }} lg:grid lg:grid-cols-3 lg:gap-5 lg:overflow-visible lg:px-0">
                @foreach ($services as [$title, $description])
                    <div class="snap-start shrink-0 w-[78%] sm:w-[46%] lg:w-auto rounded-2xl bg-white/5 border border-white/10 p-6 hover:border-brand-400/40 transition-colors">
                        <span class="text-brand-400/60 text-sm font-extrabold">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                        <h3 class="mt-2 font-semibold text-lg">{{ $title }}</h3>
                        <p class="mt-2 text-sm text-brand-100/65 leading-relaxed">{{ $description }}</p>
                    </div>
                @endforeach
            </div>
        </div>

        <div id="process" class="scroll-mt-20 max-w-7xl mx-auto px-5 sm:px-8 mt-14 sm:mt-20">
            <p class="text-brand-300 text-xs font-semibold uppercase tracking-[0.25em] mb-3">How we work</p>
            <h3 class="text-2xl sm:text-3xl font-bold max-w-2xl leading-tight">Six steps, and you see it at every one.</h3>
            <ol class="mt-8 grid grid-cols-3 sm:grid-cols-6 gap-2 sm:gap-3">
                @foreach ($process as $step)
                    <li data-parallax="{{ ($loop->index % 2 ? -1 : 1) * 12 }}" class="rounded-xl bg-white/5 border border-white/10 px-3 py-4 text-center">
                        <span class="block text-brand-400 text-xl sm:text-2xl font-extrabold leading-none">{{ $loop->iteration }}</span>
                        <span class="mt-2 block text-sm font-semibold">{{ $step }}</span>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- The team. From Team Page once people are published there; until
         then, the crew described by the work it does. --}}
    <section id="team" class="scroll-mt-20 py-16 sm:py-24 bg-brand-800/40 border-y border-white/10 overflow-hidden">
        <div class="max-w-7xl mx-auto px-5 sm:px-8">
            <p class="text-brand-300 text-xs font-semibold uppercase tracking-[0.25em] mb-3">{{ $team->isNotEmpty() ? 'Who you work with' : 'The crew' }}</p>
            <h2 class="text-3xl sm:text-4xl font-bold max-w-2xl leading-tight">
                {{ $team->isNotEmpty() ? 'The people behind the work.' : 'One team, from the first idea to the post.' }}
            </h2>
        </div>

        <div class="mt-10 max-w-7xl mx-auto lg:px-8">
            <div class="{{ $cardScroller }} lg:grid lg:grid-cols-4 lg:gap-5 lg:overflow-visible lg:px-0">
                @if ($team->isNotEmpty())
                    @foreach ($team as $member)
                        <article data-parallax-lg="{{ $loop->odd ? 0 : -24 }}" class="snap-start shrink-0 w-[70%] sm:w-[40%] lg:w-auto group">
                            <div class="relative aspect-[4/5] rounded-2xl overflow-hidden bg-brand-800 ring-1 ring-white/10">
                                @if ($member['photo'])
                                    <img src="{{ $member['photo'] }}" alt="{{ $member['name'] }}" loading="lazy"
                                         class="w-full h-full object-cover transition duration-700 group-hover:scale-105">
                                @else
                                    <div class="w-full h-full bg-gradient-to-br from-brand-700 to-brand-900 flex items-center justify-center text-6xl font-extrabold text-brand-300/50">
                                        {{ Str::of($member['name'])->substr(0, 1)->upper() }}
                                    </div>
                                @endif
                                <div class="absolute inset-0 bg-gradient-to-t from-brand-900/90 via-transparent to-transparent"></div>
                                <div class="absolute inset-x-0 bottom-0 p-4">
                                    <p class="font-semibold text-lg leading-tight">{{ $member['name'] }}</p>
                                    @if ($member['role'])
                                        <p class="text-sm text-brand-300 mt-0.5">{{ $member['role'] }}</p>
                                    @endif
                                </div>
                            </div>
                            @if ($member['bio'])
                                <p class="text-sm text-brand-100/65 mt-3 leading-relaxed">{{ $member['bio'] }}</p>
                            @endif
                        </article>
                    @endforeach
                @else
                    @foreach ($crew as [$role, $description])
                        <article data-parallax-lg="{{ $loop->odd ? 0 : -24 }}" class="snap-start shrink-0 w-[70%] sm:w-[40%] lg:w-auto rounded-2xl bg-white/5 border border-white/10 p-6">
                            <span class="flex items-center justify-center w-12 h-12 rounded-full bg-brand-400/15 text-brand-300">
                                @switch($role)
                                    @case('Script')
                                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M16.86 4.49l2.65 2.65M4 20h4l11.5-11.5a1.9 1.9 0 00-2.65-2.65L5.35 17.35 4 20z" /></svg>
                                        @break
                                    @case('Shoot')
                                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.55-2.28A1 1 0 0121 8.62v6.76a1 1 0 01-1.45.9L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
                                        @break
                                    @case('Edit')
                                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M6 6a2 2 0 100-4 2 2 0 000 4zm0 16a2 2 0 100-4 2 2 0 000 4zM20 4L8.12 15.88M14.47 14.48L20 20M8.12 8.12L12 12" /></svg>
                                        @break
                                    @default
                                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19V5m0 0l-6 6m6-6l6 6" /></svg>
                                @endswitch
                            </span>
                            <h3 class="mt-5 font-semibold text-lg">{{ $role }}</h3>
                            <p class="mt-2 text-sm text-brand-100/65 leading-relaxed">{{ $description }}</p>
                        </article>
                    @endforeach
                @endif
            </div>
        </div>
    </section>

    @if ($clients->isNotEmpty())
        <section class="py-16 sm:py-24 overflow-hidden">
            <div class="max-w-7xl mx-auto px-5 sm:px-8 text-center">
                <p class="text-brand-300 text-xs font-semibold uppercase tracking-[0.25em] mb-3">Brands we create for</p>
                <h2 class="text-3xl sm:text-4xl font-bold">The businesses behind the views.</h2>
            </div>

            <div class="relative mt-10 sm:mt-12">
                <div class="sr-marquee flex w-max">
                    @foreach ([false, true] as $duplicate)
                        @foreach ($marquee as $client)
                            <div class="shrink-0 pr-4 sm:pr-6" @if ($duplicate) aria-hidden="true" @endif>
                                @include('home._client-tile', ['client' => $client, 'size' => 'w-[150px] sm:w-[190px] h-[86px] sm:h-[112px]'])
                            </div>
                        @endforeach
                    @endforeach
                </div>
                <div class="pointer-events-none absolute inset-y-0 left-0 w-12 sm:w-32 bg-gradient-to-r from-brand-900 to-transparent"></div>
                <div class="pointer-events-none absolute inset-y-0 right-0 w-12 sm:w-32 bg-gradient-to-l from-brand-900 to-transparent"></div>
            </div>
        </section>
    @endif

    {{-- Contact --}}
    <section id="contact" class="relative overflow-hidden bg-brand-800/40 border-t border-white/10 scroll-mt-20">
        <img data-parallax="50" src="{{ asset('images/chakra-watermark.png') }}" alt=""
             class="pointer-events-none select-none absolute -right-16 top-10 w-[18rem] sm:w-[22rem] max-w-none opacity-[0.05]">
        <div class="relative max-w-7xl mx-auto px-5 sm:px-8 py-16 sm:py-24">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-10 lg:gap-16">
                <div>
                    <p class="text-brand-300 text-xs font-semibold uppercase tracking-[0.25em] mb-3">Get in touch</p>
                    <h2 class="text-3xl sm:text-5xl font-extrabold leading-[1.1]">Let's make your brand's <span class="text-brand-400">next reel</span>.</h2>
                    <p class="mt-5 text-brand-100/65 leading-relaxed">
                        A rough idea is enough to start. Tell us what it is for and roughly when
                        you need it, and we will come back with how we would approach it.
                    </p>

                    @if (array_filter($contact) || array_filter($social))
                        <div class="mt-8 space-y-3 text-sm">
                            @if ($contact['email'])
                                <p><a href="mailto:{{ $contact['email'] }}" class="text-brand-200 hover:text-white transition-colors">{{ $contact['email'] }}</a></p>
                            @endif
                            @if ($contact['phone'])
                                <p><a href="tel:{{ preg_replace('/[^0-9+]/', '', $contact['phone']) }}" class="text-brand-200 hover:text-white transition-colors">{{ $contact['phone'] }}</a></p>
                            @endif
                            @if ($contact['whatsapp'])
                                <p><a href="https://wa.me/{{ $contact['whatsapp'] }}" target="_blank" rel="noopener" class="text-brand-200 hover:text-white transition-colors">WhatsApp us</a></p>
                            @endif
                            @if ($contact['address'])
                                <p class="text-brand-100/70">{{ $contact['address'] }}</p>
                            @endif
                            @if (array_filter($social))
                                <div class="flex flex-wrap gap-4 pt-2">
                                    @foreach (array_filter($social) as $label => $url)
                                        <a href="{{ $url }}" target="_blank" rel="noopener" class="inline-flex items-center min-h-[44px] text-brand-200 hover:text-white transition-colors">{{ $label }}</a>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif
                </div>

                <div class="rounded-2xl bg-brand-900/60 border border-white/10 p-5 sm:p-8">
                    @if (session('status'))
                        <div class="mb-6 rounded-md bg-green-500/15 border border-green-400/30 px-4 py-3 text-sm text-green-100">{{ session('status') }}</div>
                    @endif
                    @if (session('error'))
                        <div class="mb-6 rounded-md bg-red-500/15 border border-red-400/30 px-4 py-3 text-sm text-red-100">{{ session('error') }}</div>
                    @endif

                    <form method="POST" action="{{ route('enquiry.store') }}" class="space-y-4">
                        @csrf

                        {{-- Honeypot: hidden from people, irresistible to bots. --}}
                        <div class="hidden" aria-hidden="true">
                            <label for="website">Website</label>
                            <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
                        </div>

                        {{-- Which page sent them here (see App\Models\Enquiry::SOURCES). --}}
                        @php
                            $from = (string) request()->query('from', '');
                            $source = old('source', isset(\App\Models\Enquiry::SOURCES[$from]) ? $from : 'landing');
                        @endphp
                        <input type="hidden" name="source" value="{{ $source }}">

                        <div>
                            <label for="name" class="block text-sm font-medium text-brand-100/80 mb-1.5">Your name</label>
                            <input type="text" id="name" name="name" value="{{ old('name') }}" required autocomplete="name"
                                   class="w-full min-h-[48px] rounded-md bg-brand-900/60 border-white/15 text-base text-white placeholder-brand-100/30 focus:border-brand-400 focus:ring-brand-400">
                            @error('name') <p class="mt-1.5 text-sm text-red-300">{{ $message }}</p> @enderror
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label for="email" class="block text-sm font-medium text-brand-100/80 mb-1.5">Email</label>
                                <input type="email" id="email" name="email" value="{{ old('email') }}" required autocomplete="email"
                                       class="w-full min-h-[48px] rounded-md bg-brand-900/60 border-white/15 text-base text-white placeholder-brand-100/30 focus:border-brand-400 focus:ring-brand-400">
                                @error('email') <p class="mt-1.5 text-sm text-red-300">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="phone" class="block text-sm font-medium text-brand-100/80 mb-1.5">Phone <span class="text-brand-100/40">(optional)</span></label>
                                <input type="tel" id="phone" name="phone" value="{{ old('phone') }}" autocomplete="tel"
                                       class="w-full min-h-[48px] rounded-md bg-brand-900/60 border-white/15 text-base text-white placeholder-brand-100/30 focus:border-brand-400 focus:ring-brand-400">
                                @error('phone') <p class="mt-1.5 text-sm text-red-300">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <div>
                            <label for="project" class="block text-sm font-medium text-brand-100/80 mb-1.5">What do you need?</label>
                            <select id="project" name="project"
                                    class="w-full min-h-[48px] rounded-md bg-brand-900/60 border-white/15 text-base text-white focus:border-brand-400 focus:ring-brand-400">
                                <option value="">Not sure yet</option>
                                @foreach (['Short-form video', 'YouTube long-form', 'Full content retainer', 'Stills & static', 'Something else'] as $option)
                                    <option value="{{ $option }}" @selected(old('project') === $option)>{{ $option }}</option>
                                @endforeach
                            </select>
                            @error('project') <p class="mt-1.5 text-sm text-red-300">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="message" class="block text-sm font-medium text-brand-100/80 mb-1.5">About the project</label>
                            <textarea id="message" name="message" rows="5" required
                                      placeholder="What it is for, roughly when you need it, anything you already have."
                                      class="w-full rounded-md bg-brand-900/60 border-white/15 text-base text-white placeholder-brand-100/30 focus:border-brand-400 focus:ring-brand-400">{{ old('message') }}</textarea>
                            @error('message') <p class="mt-1.5 text-sm text-red-300">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="prompted_by" class="block text-sm font-medium text-brand-100/80 mb-1.5">What made you get in touch? <span class="text-brand-100/40">(optional)</span></label>
                            <input type="text" id="prompted_by" name="prompted_by" value="{{ old('prompted_by') }}"
                                   placeholder="A film you saw, a recommendation, something else"
                                   class="w-full min-h-[48px] rounded-md bg-brand-900/60 border-white/15 text-base text-white placeholder-brand-100/30 focus:border-brand-400 focus:ring-brand-400">
                            @error('prompted_by') <p class="mt-1.5 text-sm text-red-300">{{ $message }}</p> @enderror
                        </div>

                        @error('website') <p class="text-sm text-red-300">{{ $message }}</p> @enderror

                        <button type="submit"
                                class="w-full inline-flex items-center justify-center min-h-[52px] px-8 rounded-md bg-brand-400 text-brand-900 text-sm font-semibold uppercase tracking-widest hover:bg-brand-500 transition-colors">
                            Send enquiry
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </section>
</x-public-layout>
