{{--
    /portfolio (PublicPortfolioController::index): a wall of our reels behind
    the total views, the numbers counting up, the five biggest reels in a
    spotlight, the brands, then every piece in the grid
    (partials.portfolio-grid). Motion respects prefers-reduced-motion.
--}}
@php
    use App\Support\HomePage;

    // The hero wall: covers spread over columns, each column twice so it loops.
    $wallColumns = 6;
    $columns = collect(range(0, $wallColumns - 1))->map(fn ($c) => $wall->isEmpty() ? collect()
        : collect(range(0, 5))->map(fn ($k) => $wall[($c * 3 + $k * 7) % $wall->count()]));

    $hitsMax = collect(['views', 'reach', 'shares', 'saves'])
        ->mapWithKeys(fn ($k) => [$k => max(1, (int) $hits->max(fn ($h) => $h['stats'][$k] ?? 0))]);

    $marquee = collect();
    while ($brands->isNotEmpty() && $marquee->count() < 12) {
        $marquee = $marquee->concat($brands);
    }
@endphp

<x-public-layout
    :title="$seo['title']"
    :description="$seo['description']"
    :canonical="$seo['canonical']"
    :schema="$seo['schema']">

    @push('styles')
        <style>
            .pf-rise { animation: pfRise .8s cubic-bezier(.2,.8,.2,1) both; animation-delay: var(--d, 0ms); }
            @keyframes pfRise { from { opacity: 0; transform: translateY(18px); } to { opacity: 1; transform: none; } }
            .pf-col { animation: pfDrift linear infinite; will-change: transform; }
            @keyframes pfDrift { from { transform: translate3d(0, 0, 0); } to { transform: translate3d(0, -50%, 0); } }
            .pf-grain { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='160'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='2' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E"); opacity: .07; mix-blend-mode: overlay; }
            .pf-marquee { animation: pfMarquee 45s linear infinite; }
            .pf-marquee:hover { animation-play-state: paused; }
            @keyframes pfMarquee { to { transform: translateX(-50%); } }
            .pf-glow { background: radial-gradient(closest-side, rgba(103, 188, 212, .35), transparent); filter: blur(30px); }
            .pf-swap { animation: pfSwap .6s cubic-bezier(.2, .8, .2, 1); }
            @keyframes pfSwap { from { opacity: 0; transform: translateY(14px) scale(.985); } to { opacity: 1; transform: none; } }
            .pf-outline { color: transparent; -webkit-text-stroke: 1.5px rgba(103, 188, 212, .65); }
            .pf-bar { transition: width 1.1s cubic-bezier(.2, .8, .2, 1); }
            .pf-cue { animation: pfCue 1.6s ease-in-out infinite; }
            @keyframes pfCue { 0%, 100% { transform: translateY(0); opacity: 1; } 50% { transform: translateY(7px); opacity: .5; } }
            @media (prefers-reduced-motion: reduce) { .pf-rise, .pf-col, .pf-marquee, .pf-swap, .pf-cue { animation: none; } }
        </style>
    @endpush

    {{-- ═══════════════════════════════════════ The wall ═══ --}}
    <section class="relative isolate flex min-h-[86svh] items-end overflow-hidden" x-data>
        <div class="absolute inset-0 -z-10" aria-hidden="true">
            <div class="absolute -inset-x-16 -top-24 bottom-0 grid grid-cols-3 gap-3 sm:grid-cols-4 sm:gap-4 lg:grid-cols-6 -rotate-6 scale-110 opacity-70">
                @foreach ($columns as $c => $covers)
                    <div class="overflow-hidden {{ $c >= 4 ? 'hidden lg:block' : ($c >= 3 ? 'hidden sm:block' : '') }}">
                        <div class="pf-col space-y-3 sm:space-y-4" style="animation-duration: {{ 70 + $c * 9 }}s; animation-direction: {{ $c % 2 ? 'reverse' : 'normal' }}">
                            @foreach ($covers->concat($covers) as $src)
                                <img src="{{ $src }}" alt="" loading="{{ $loop->index < 3 ? 'eager' : 'lazy' }}" class="aspect-[9/16] w-full rounded-2xl object-cover">
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_30%_70%,rgba(19,42,56,0.55),rgba(19,42,56,0.92)_70%)]"></div>
            <div class="absolute inset-0 bg-gradient-to-t from-brand-900 via-brand-900/60 to-brand-900/40"></div>
            <div class="pf-grain absolute inset-0"></div>
        </div>

        <div class="mx-auto w-full max-w-7xl px-5 pb-14 pt-28 sm:px-8 sm:pb-20">
            <p class="pf-rise text-xs font-semibold uppercase tracking-[0.3em] text-brand-300">
                Portfolio · {{ $totals['pieces'] }} reels · {{ $totals['brands'] }} brands
            </p>
            <h1 class="pf-rise mt-5 text-[clamp(3.2rem,13vw,9rem)] font-extrabold leading-[0.92] tracking-tight" style="--d: 80ms">
                <span class="bg-gradient-to-r from-white via-brand-100 to-brand-400 bg-clip-text tabular-nums text-transparent"
                      data-pf-count="{{ $totals['views'] }}">{{ HomePage::compact($totals['views']) }}</span><span class="text-brand-400">+</span>
                <span class="block">views.</span>
            </h1>
            <p class="pf-rise mt-6 max-w-xl text-lg leading-relaxed text-brand-100/75 sm:text-xl" style="--d: 160ms">
                Reels we made for brands across Trichy, Manapparai and Tamil Nadu. Tap any one to watch it, then open its case study to see why it worked.
            </p>

            <div class="pf-rise mt-8 flex flex-wrap items-center gap-3" style="--d: 240ms">
                @if ($hits->isNotEmpty())
                    <button type="button" @click="$dispatch('pf-play', @js(['src' => $hits[0]['src'], 'kind' => $hits[0]['kind'], 'title' => $hits[0]['title']]))"
                            class="inline-flex min-h-[54px] items-center gap-3 rounded-full bg-white py-2 pl-2 pr-6 text-sm font-bold text-brand-900 transition hover:scale-[1.03]">
                        <span class="flex h-10 w-10 items-center justify-center rounded-full bg-brand-400">
                            <svg class="ml-0.5 h-4 w-4" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path d="M6.3 2.84A1.5 1.5 0 004 4.11v11.78a1.5 1.5 0 002.3 1.27l9.34-5.89a1.5 1.5 0 000-2.54L6.3 2.84z" /></svg>
                        </span>
                        Watch our #1 reel · {{ HomePage::compact((int) ($hits[0]['stats']['views'] ?? 0)) }} views
                    </button>
                @endif
                <a href="#work" class="inline-flex min-h-[54px] items-center rounded-full border border-white/25 px-6 text-sm font-semibold text-white transition hover:bg-white/10">See all the work</a>
                <span class="inline-flex min-h-[54px] items-center gap-2.5 rounded-full border border-white/15 bg-white/[0.06] py-2 pl-3 pr-5 text-sm">
                    <svg class="h-6 w-9" viewBox="0 0 36 24" aria-hidden="true">
                        <defs><linearGradient id="metaG" x1="0" x2="1"><stop offset="0" stop-color="#0064E0"/><stop offset="1" stop-color="#0082FB"/></linearGradient></defs>
                        <path d="M6.5 18.5c-2.4 0-4-2.1-4-5.4 0-4.1 2.3-7.6 5-7.6 2.2 0 3.9 1.9 6.9 6.9l1.6 2.6c2.3 3.7 3.6 4.9 5.6 4.9 2.6 0 4.4-2.4 4.4-6.1 0-3.9-1.9-7.3-4.8-7.3-2 0-3.5 1.5-5.8 5.2" fill="none" stroke="url(#metaG)" stroke-width="3" stroke-linecap="round"/>
                    </svg>
                    <span><span class="font-semibold text-white">Meta</span> <span class="text-brand-100/70">Ads specialists · Instagram &amp; Facebook</span></span>
                </span>
            </div>

            <a href="#numbers" class="mt-12 hidden items-center gap-3 text-xs font-semibold uppercase tracking-[0.25em] text-brand-100/50 hover:text-white sm:inline-flex" aria-label="Scroll down">
                <span class="flex h-9 w-6 justify-center rounded-full border border-white/30 pt-1.5"><span class="pf-cue h-1.5 w-1.5 rounded-full bg-white"></span></span>
                Scroll
            </a>
        </div>
    </section>

    {{-- ═══════════════════════════════════════ The numbers ═══ --}}
    <section id="numbers" class="border-y border-white/10 bg-brand-800/40">
        <div class="mx-auto grid max-w-7xl grid-cols-2 gap-px bg-white/[0.06] lg:grid-cols-4">
            @foreach ([
                ['views', 'views'],
                ['reach', 'accounts reached'],
                ['shares', 'times shared'],
                ['saves', 'times saved'],
            ] as [$key, $label])
                @continue(! $totals[$key])
                <div class="bg-brand-900 px-5 py-9 sm:px-8 sm:py-12">
                    <p class="text-[clamp(2.2rem,6vw,4rem)] font-extrabold leading-none tracking-tight tabular-nums text-white"
                       data-pf-count="{{ $totals[$key] }}" data-pf-on-view>{{ HomePage::compact($totals[$key]) }}</p>
                    <p class="mt-3 text-sm text-brand-100/60">{{ $label }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- ═══════════════════════════════════════ The hits ═══ --}}
    @if ($hits->isNotEmpty())
        <section class="relative overflow-hidden py-20 sm:py-28" x-data="pfHits(@js($hits), @js($hitsMax))" @mouseenter="paused = true" @mouseleave="paused = false">
            <div class="pf-glow pointer-events-none absolute -left-40 top-10 h-[520px] w-[520px]" aria-hidden="true"></div>
            <div class="mx-auto max-w-7xl px-5 sm:px-8">
                <p class="text-xs font-semibold uppercase tracking-[0.25em] text-brand-300">The hits</p>
                <h2 class="mt-3 max-w-3xl text-[clamp(2.2rem,6vw,4.5rem)] font-extrabold leading-[1.02] tracking-tight">{{ $hits->count() }} reels that broke out.</h2>

                <div x-ref="stage" class="pf-swap mt-12 grid items-center gap-10 lg:grid-cols-[minmax(0,400px)_minmax(0,1fr)] lg:gap-16">
                    {{-- The reel --}}
                    <button type="button" @click="play()" class="group relative mx-auto block w-full max-w-[360px] lg:max-w-none" :aria-label="'Watch ' + h.title">
                        <span class="absolute -inset-6 -z-10 rounded-[2.5rem] bg-brand-400/20 blur-2xl" aria-hidden="true"></span>
                        <span class="relative block aspect-[9/16] overflow-hidden rounded-[2rem] bg-brand-800 ring-1 ring-white/15 shadow-2xl shadow-black/50">
                            <img :src="h.cover" :alt="h.title" class="h-full w-full object-cover transition duration-700 group-hover:scale-105">
                            <span class="absolute inset-0 bg-gradient-to-t from-black/70 via-transparent to-transparent"></span>
                            <span class="absolute left-4 top-4 rounded-full bg-black/55 px-3 py-1 text-xs font-bold text-white backdrop-blur" x-text="'#' + (i + 1)"></span>
                            <span class="absolute inset-0 flex items-center justify-center">
                                <span class="flex h-20 w-20 items-center justify-center rounded-full bg-white/90 text-brand-900 shadow-xl transition group-hover:scale-110">
                                    <svg class="ml-1 h-8 w-8" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path d="M6.3 2.84A1.5 1.5 0 004 4.11v11.78a1.5 1.5 0 002.3 1.27l9.34-5.89a1.5 1.5 0 000-2.54L6.3 2.84z" /></svg>
                                </span>
                            </span>
                            <span class="absolute bottom-4 left-4 right-4 text-left">
                                <span class="block text-3xl font-extrabold text-white" x-text="fmt(h.stats.views) + ' views'"></span>
                            </span>
                        </span>
                    </button>

                    {{-- Why it worked --}}
                    <div class="min-w-0">
                        <p class="pf-outline text-[clamp(4rem,12vw,8rem)] font-extrabold leading-none" x-text="String(i + 1).padStart(2, '0')" aria-hidden="true"></p>
                        <p class="mt-2 text-sm font-semibold uppercase tracking-[0.2em] text-brand-300" x-text="h.client"></p>
                        <h3 class="mt-2 text-2xl font-bold leading-snug sm:text-4xl" x-text="h.title"></h3>
                        <template x-if="h.hook">
                            <blockquote class="mt-5 border-l-2 border-brand-400 pl-4 text-lg italic text-brand-100/85" x-text="'“' + h.hook + '”'"></blockquote>
                        </template>

                        <dl class="mt-8 space-y-4">
                            <template x-for="row in rows" :key="row.key">
                                <div>
                                    <div class="flex items-baseline justify-between text-sm">
                                        <dt class="text-brand-100/65" x-text="row.label"></dt>
                                        <dd class="font-bold tabular-nums text-white" x-text="fmt(row.value)"></dd>
                                    </div>
                                    <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-white/10">
                                        <div class="pf-bar h-full rounded-full bg-gradient-to-r from-brand-500 to-brand-300" :style="`width:${row.pct}%`"></div>
                                    </div>
                                </div>
                            </template>
                        </dl>

                        <p class="mt-6 line-clamp-3 max-w-2xl leading-relaxed text-brand-100/70" x-text="h.summary" x-show="h.summary"></p>

                        <div class="mt-7 flex flex-wrap gap-3">
                            <button type="button" @click="play()" class="inline-flex min-h-[50px] items-center rounded-full bg-brand-400 px-6 text-sm font-bold text-brand-900 transition hover:bg-brand-300">Watch the reel</button>
                            <a x-show="h.detail" :href="h.detail" class="inline-flex min-h-[50px] items-center rounded-full border border-white/25 px-6 text-sm font-semibold text-white transition hover:bg-white/10">Read the case study →</a>
                        </div>
                    </div>
                </div>

                {{-- Pick a hit; the bar under the current one runs to the next --}}
                <div class="mt-12 flex gap-3 overflow-x-auto pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                    <template x-for="(x, n) in hits" :key="x.id">
                        <button type="button" @click="go(n)" class="group w-[92px] shrink-0 text-left sm:w-[110px]" :aria-label="'Show #' + (n + 1)" :aria-current="n === i ? 'true' : 'false'">
                            <span class="block aspect-[9/16] overflow-hidden rounded-xl ring-2 transition" :class="n === i ? 'ring-brand-400' : 'ring-transparent opacity-55 group-hover:opacity-90'">
                                <img :src="x.cover" alt="" class="h-full w-full object-cover" loading="lazy">
                            </span>
                            <span class="mt-2 block h-1 overflow-hidden rounded-full bg-white/10">
                                <span class="block h-full bg-brand-400" :style="`width:${n < i ? 100 : (n === i ? progress : 0)}%`"></span>
                            </span>
                        </button>
                    </template>
                </div>
            </div>
        </section>
    @endif

    {{-- ═══════════════════════════════════════ The brands ═══ --}}
    @if ($marquee->isNotEmpty())
        <section class="overflow-hidden border-y border-white/10 bg-brand-800/40 py-14 sm:py-20" aria-label="Brands we create for">
            <p class="text-center text-xs font-semibold uppercase tracking-[0.25em] text-brand-300">Brands we create for</p>
            <div class="relative mt-8">
                <div class="pf-marquee flex w-max">
                    @foreach ([false, true] as $duplicate)
                        @foreach ($marquee as $client)
                            <div class="shrink-0 pr-4 sm:pr-6" @if ($duplicate) aria-hidden="true" @endif>
                                @include('home._client-tile', ['client' => $client, 'size' => 'w-[150px] sm:w-[190px] h-[86px] sm:h-[112px]'])
                            </div>
                        @endforeach
                    @endforeach
                </div>
                <div class="pointer-events-none absolute inset-y-0 left-0 w-12 bg-gradient-to-r from-[#1b3442] to-transparent sm:w-32"></div>
                <div class="pointer-events-none absolute inset-y-0 right-0 w-12 bg-gradient-to-l from-[#1b3442] to-transparent sm:w-32"></div>
            </div>
        </section>
    @endif

    {{-- ═══════════════════════════════════════ All the work ═══ --}}
    <section id="work" class="scroll-mt-16">
        <div class="mx-auto max-w-7xl px-3 py-16 sm:px-5 sm:py-24">
            <div class="mb-8 px-2 sm:px-3">
                <p class="text-xs font-semibold uppercase tracking-[0.25em] text-brand-300">All the work</p>
                <h2 class="mt-3 text-[clamp(2rem,5vw,3.5rem)] font-extrabold leading-[1.05] tracking-tight">Every reel, in one place.</h2>
            </div>
            @if ($items->isEmpty())
                <div class="py-20 text-center">
                    <p class="text-xl font-semibold">Nothing published yet.</p>
                    <p class="mt-2 text-brand-100/60">Our latest work is on its way here.</p>
                </div>
            @else
                @include('partials.portfolio-grid', [
                    'stars' => $stars,
                    'liveUrl' => route('portfolio.live'),
                    'categories' => $categories,
                    'items' => $items,
                    'activeCategory' => $activeCategory,
                    'sortable' => true,
                ])
            @endif
        </div>
    </section>

    {{-- ═══════════════════════════════════════ Yours next ═══ --}}
    <section class="relative isolate overflow-hidden border-t border-white/10">
        <div class="pf-glow pointer-events-none absolute left-1/2 top-1/2 -z-10 h-[620px] w-[620px] -translate-x-1/2 -translate-y-1/2" aria-hidden="true"></div>
        <div class="mx-auto max-w-4xl px-5 py-24 text-center sm:px-8 sm:py-32">
            <h2 class="text-[clamp(2.4rem,7vw,5rem)] font-extrabold leading-[1.02] tracking-tight">
                Your brand's <span class="bg-gradient-to-r from-brand-200 to-brand-400 bg-clip-text text-transparent">next reel</span> starts here.
            </h2>
            <p class="mx-auto mt-6 max-w-xl text-lg leading-relaxed text-brand-100/70">
                Tell us roughly what you have in mind and we will come back with how we would approach it.
            </p>
            <a href="{{ route('home', ['from' => 'portfolio']) }}#contact"
               class="mt-10 inline-flex min-h-[56px] items-center justify-center rounded-full bg-brand-400 px-9 text-sm font-bold uppercase tracking-widest text-brand-900 transition hover:scale-[1.03] hover:bg-brand-300">
                Start a project
            </a>
        </div>
    </section>

    @push('scripts')
        <script>
            // The five biggest reels, one at a time; the bar runs to the next.
            function pfHits(hits, max) {
                return {
                    hits, max, i: 0, progress: 0, paused: false,
                    get h() { return this.hits[this.i]; },
                    get rows() {
                        return [['views', 'Views'], ['reach', 'Accounts reached'], ['shares', 'Shares'], ['saves', 'Saves']]
                            .filter(([k]) => this.h.stats[k])
                            .map(([k, label]) => ({ key: k, label, value: this.h.stats[k], pct: Math.max(3, (this.h.stats[k] / this.max[k]) * 100) }));
                    },
                    init() {
                        if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return;
                        const step = 100 / (8000 / 100);
                        setInterval(() => {
                            if (this.paused || document.hidden) return;
                            this.progress += step;
                            if (this.progress >= 100) this.go((this.i + 1) % this.hits.length);
                        }, 100);
                    },
                    go(n) {
                        this.i = n;
                        this.progress = 0;
                        const el = this.$refs.stage;
                        el.classList.remove('pf-swap'); void el.offsetWidth; el.classList.add('pf-swap');
                    },
                    play() { this.$dispatch('pf-play', { src: this.h.src, kind: this.h.kind, title: this.h.title }); },
                    fmt(n) {
                        if (n >= 1e6) return (Math.floor(n / 1e5) / 10).toString() + 'M';
                        if (n >= 1e3) return Math.floor(n / 1e3) + 'K';
                        return String(n ?? '');
                    },
                };
            }

            // Big numbers count up -- the hero at once, the band as it scrolls in.
            (() => {
                const fmt = (n) => n >= 1e6 ? (Math.floor(n / 1e5) / 10) + 'M' : (n >= 1e3 ? Math.floor(n / 1e3) + 'K' : String(Math.floor(n)));
                const run = (el) => {
                    const to = +el.dataset.pfCount;
                    if (! to || window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return;
                    const t0 = performance.now(), dur = 1800;
                    const tick = (t) => {
                        const p = Math.min(1, (t - t0) / dur), e = 1 - Math.pow(1 - p, 3);
                        el.textContent = fmt(to * e);
                        if (p < 1) requestAnimationFrame(tick);
                    };
                    requestAnimationFrame(tick);
                };
                const start = () => {
                    document.querySelectorAll('[data-pf-count]:not([data-pf-on-view])').forEach(run);
                    const later = document.querySelectorAll('[data-pf-count][data-pf-on-view]');
                    if (! ('IntersectionObserver' in window)) return later.forEach(run);
                    const io = new IntersectionObserver((es) => es.forEach((e) => { if (e.isIntersecting) { run(e.target); io.unobserve(e.target); } }), { threshold: 0.4 });
                    later.forEach((el) => io.observe(el));
                };
                document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', start) : start();
            })();
        </script>
    @endpush
</x-public-layout>
