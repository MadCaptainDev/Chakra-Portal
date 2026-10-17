<x-public-layout
    :title="$seo['title']"
    :description="$seo['description']"
    :canonical="$seo['canonical']"
    :schema="$seo['schema']">

    <section class="border-b border-white/10">
        <div class="max-w-7xl mx-auto px-5 sm:px-8 py-16 sm:py-20">
            <p class="text-brand-300 text-xs font-semibold uppercase tracking-[0.25em] mb-4">Portfolio</p>
            <h1 class="pf-rise text-4xl sm:text-5xl lg:text-6xl font-extrabold leading-[1.05] max-w-3xl">
                Everything we have made, in one place.
            </h1>
            <p class="mt-5 text-lg text-brand-100/70 max-w-2xl leading-relaxed">
                Reels, YouTube and social media work for brands across Trichy, Manapparai and Tamil Nadu.
                Pick a category to narrow it down, then tap any still to play the film.
            </p>

            {{-- Who runs the ads, and that the numbers on every reel are live. --}}
            <div class="pf-rise mt-8 flex flex-wrap items-center gap-3" style="--d: 120ms">
                <span class="inline-flex items-center gap-2.5 rounded-full border border-white/15 bg-white/[0.06] py-2 pl-2.5 pr-4 text-sm">
                    <svg class="h-6 w-9" viewBox="0 0 36 24" aria-hidden="true">
                        <defs><linearGradient id="metaG" x1="0" x2="1"><stop offset="0" stop-color="#0064E0"/><stop offset="1" stop-color="#0082FB"/></linearGradient></defs>
                        <path d="M6.5 18.5c-2.4 0-4-2.1-4-5.4 0-4.1 2.3-7.6 5-7.6 2.2 0 3.9 1.9 6.9 6.9l1.6 2.6c2.3 3.7 3.6 4.9 5.6 4.9 2.6 0 4.4-2.4 4.4-6.1 0-3.9-1.9-7.3-4.8-7.3-2 0-3.5 1.5-5.8 5.2" fill="none" stroke="url(#metaG)" stroke-width="3" stroke-linecap="round"/>
                    </svg>
                    <span><span class="font-semibold text-white">Meta</span> <span class="text-brand-100/70">Ads specialists · Instagram &amp; Facebook</span></span>
                </span>
                <span class="inline-flex items-center gap-2 rounded-full bg-emerald-400/[0.12] px-3.5 py-2 text-sm font-semibold text-emerald-300" x-data>
                    <span class="relative flex h-2 w-2"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-70"></span><span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-400"></span></span>
                    Live numbers from Instagram
                    @if ($lastSynced)
                        <span class="font-normal text-emerald-200/70" data-pf-synced>· updated {{ \Illuminate\Support\Carbon::parse($lastSynced)->diffForHumans() }}</span>
                    @endif
                </span>
            </div>
        </div>
    </section>

    <section class="scroll-mt-20">
        {{-- Tighter side gap than the rest of the site's sections -- a grid
             of small tiles reads better closer to the edges. --}}
        <div class="max-w-7xl mx-auto px-3 sm:px-5 py-12 sm:py-16">
            @if ($items->isEmpty())
                <div class="text-center py-20">
                    <p class="text-xl font-semibold">Nothing published yet.</p>
                    <p class="mt-2 text-brand-100/60">Our latest work is on its way here.</p>
                    <a href="{{ route('home', ['from' => 'portfolio']) }}#contact"
                       class="mt-8 inline-flex items-center justify-center min-h-[52px] px-8 rounded-md bg-brand-400 text-brand-900 text-sm font-semibold uppercase tracking-widest hover:bg-brand-500 transition-colors">
                        Start a project
                    </a>
                </div>
            @else
                @include('partials.portfolio-grid', [
                    'stars' => $stars,
                    'liveUrl' => route('portfolio.live'),
                    'categories' => $categories,
                    'items' => $items,
                    'activeCategory' => $activeCategory,
                ])
            @endif
        </div>
    </section>

    <section class="bg-brand-800/40 border-t border-white/10">
        <div class="max-w-7xl mx-auto px-5 sm:px-8 py-16 sm:py-20 text-center">
            <h2 class="text-3xl sm:text-4xl font-bold">Want something like this?</h2>
            <p class="mt-4 text-brand-100/60 max-w-xl mx-auto leading-relaxed">
                Tell us roughly what you have in mind and we will come back with how we would approach it.
            </p>
            <a href="{{ route('home', ['from' => 'portfolio']) }}#contact"
               class="mt-8 inline-flex items-center justify-center min-h-[52px] px-8 rounded-md bg-brand-400 text-brand-900 text-sm font-semibold uppercase tracking-widest hover:bg-brand-500 transition-colors">
                Start a project
            </a>
        </div>
    </section>
</x-public-layout>
