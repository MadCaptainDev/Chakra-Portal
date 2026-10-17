@php
    use App\Support\Metric;

    /*
    | The tabbed video grid, shared by the landing page's Portfolio section and
    | the full /portfolio screen.
    |
    | Expects: $categories, $items. Optional: $activeCategory (slug), $columns.
    |
    | Filtering is client-side on purpose -- tapping a tab must not reload the
    | page and lose the visitor's place, and the number of pieces a studio
    | publishes stays well inside what one page can hold.
    |
    | Cards are small on purpose: the catalogue is now almost entirely 9:16
    | Reels (see PortfolioItem::isVertical()), and a tile sized for a
    | landscape still leaves a vertical one swimming in empty space. More,
    | smaller columns read the way a Reels grid actually reads.
    */
    $activeCategory = $activeCategory ?? 'all';
    $columns = $columns ?? 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5';

    // Only offer a tab that has something behind it.
    $usedCategoryIds = $items->pluck('portfolio_category_id')->filter()->unique();
    $tabs = $categories->filter(fn ($category) => $usedCategoryIds->contains($category->id))->values();
    $hasUncategorised = $items->contains(fn ($item) => ! $item->portfolio_category_id);

    // How many cards each tab shows. Passed to Alpine so the empty message is
    // driven by the same numbers the markup was built from.
    $counts = ['all' => $items->count(), 'other' => $items->filter(fn ($item) => ! $item->portfolio_category_id)->count()];
    foreach ($tabs as $tab) {
        $counts[$tab->slug] = $items->where('portfolio_category_id', $tab->id)->count();
    }

    // The top performers pop up one at a time (PublicPortfolioController::STAR_VIEWS).
    $stars = ($stars ?? collect())->map(fn ($i) => [
        'id' => $i->id, 'title' => $i->title, 'client' => $i->clientLabel(), 'views' => (int) $i->views,
        'thumb' => $i->thumbnailUrl(), 'src' => $i->playbackUrl(), 'kind' => $i->isUploaded() ? 'file' : 'link',
    ])->values();
@endphp

<div x-data="portfolioGrid(@js($tabs->contains('slug', $activeCategory) ? $activeCategory : 'all'), @js($counts), @js($stars), @js($liveUrl ?? null))">
    @if ($tabs->isNotEmpty())
        <div class="-mx-3 sm:mx-0 px-3 sm:px-0 overflow-x-auto">
            <div class="flex gap-2 min-w-max sm:min-w-0 sm:flex-wrap" role="tablist" aria-label="Portfolio categories">
                <button type="button" role="tab" @click="active = 'all'"
                        :aria-selected="active === 'all' ? 'true' : 'false'"
                        class="shrink-0 inline-flex items-center min-h-[44px] px-5 rounded-full text-sm font-semibold border transition-colors"
                        :class="active === 'all'
                            ? 'bg-brand-400 border-brand-400 text-brand-900'
                            : 'bg-white/5 border-white/15 text-brand-100/70 hover:text-white hover:border-white/30'">
                    All work
                </button>

                @foreach ($tabs as $category)
                    <button type="button" role="tab" @click="active = @js($category->slug)"
                            :aria-selected="active === @js($category->slug) ? 'true' : 'false'"
                            class="shrink-0 inline-flex items-center min-h-[44px] px-5 rounded-full text-sm font-semibold border transition-colors"
                            :class="active === @js($category->slug)
                                ? 'bg-brand-400 border-brand-400 text-brand-900'
                                : 'bg-white/5 border-white/15 text-brand-100/70 hover:text-white hover:border-white/30'">
                        {{ $category->name }}
                    </button>
                @endforeach

                @if ($hasUncategorised)
                    <button type="button" role="tab" @click="active = 'other'"
                            :aria-selected="active === 'other' ? 'true' : 'false'"
                            class="shrink-0 inline-flex items-center min-h-[44px] px-5 rounded-full text-sm font-semibold border transition-colors"
                            :class="active === 'other'
                                ? 'bg-brand-400 border-brand-400 text-brand-900'
                                : 'bg-white/5 border-white/15 text-brand-100/70 hover:text-white hover:border-white/30'">
                        Other
                    </button>
                @endif
            </div>
        </div>
    @endif

    <div class="grid {{ $columns }} gap-3 sm:gap-4 mt-8">
        @foreach ($items as $item)
            @php $slug = $item->category?->slug ?? 'other'; @endphp

            <article x-show="active === 'all' || active === @js($slug)"
                     x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                     data-pf-card style="--i: {{ $loop->index % 10 }}"
                     class="pf-card group rounded-xl overflow-hidden bg-white/5 border border-white/10 hover:border-brand-400/40 hover:shadow-[0_18px_40px_-18px_rgba(103,188,212,0.45)]">
                <button type="button"
                        @click="open($el)"
                        data-video="{{ $item->playbackUrl() }}"
                        data-kind="{{ $item->isUploaded() ? 'file' : 'link' }}"
                        data-title="{{ $item->title }}"
                        data-id="{{ $item->id }}"
                        @unless ($item->playbackUrl()) disabled @endunless
                        class="block w-full text-left">
                    <div class="relative {{ $item->isVertical() ? 'aspect-[9/16]' : 'aspect-video' }} bg-brand-900/60 overflow-hidden">
                        {{-- No thumbnail falls back to the uploaded file's first
                             frame, so a card is never a blank rectangle. Ratio
                             follows the piece's own format -- a Reel stays
                             vertical rather than being cropped into 16:9. --}}
                        @if ($item->thumbnailUrl())
                            <img src="{{ $item->thumbnailUrl() }}" alt="{{ $item->title }}" loading="lazy"
                                 class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300">
                        @elseif ($item->isUploaded())
                            <video src="{{ $item->playbackUrl() }}#t=0.1" preload="metadata"
                                   muted playsinline tabindex="-1" aria-hidden="true"
                                   class="w-full h-full object-cover pointer-events-none group-hover:scale-105 transition-transform duration-300"></video>
                        @endif

                        @if ($item->playbackUrl())
                            <span class="absolute inset-0 flex items-center justify-center bg-brand-900/30 group-hover:bg-brand-900/10 transition-colors">
                                <span class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-white/90 text-brand-900 shadow-lg group-hover:scale-110 transition-transform">
                                    <svg class="w-6 h-6 ml-0.5" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M6.3 2.84A1.5 1.5 0 004 4.11v11.78a1.5 1.5 0 002.3 1.27l9.34-5.89a1.5 1.5 0 000-2.54L6.3 2.84z" />
                                    </svg>
                                </span>
                            </span>
                        @endif

                        {{-- Insight, on the thumbnail itself -- same corner
                             Instagram's own Reels grid puts a view count in.
                             Front-of-card is the thumbnail at this size, so
                             this is "in front" without costing any of the
                             card's (now small) caption space. --}}
                        @php
                            $headline = $item->views ?? $item->reach;
                            $headlineLabel = $item->views !== null ? 'views' : 'reach';
                        @endphp
                        @if ($headline !== null)
                            <span class="absolute left-2 bottom-2 inline-flex items-center gap-1 px-1.5 py-0.5 rounded bg-black/55 backdrop-blur-sm text-[11px] font-semibold text-white">
                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.46 12C3.73 7.94 7.52 5 12 5c4.48 0 8.27 2.94 9.54 7-1.27 4.06-5.06 7-9.54 7-4.48 0-8.27-2.94-9.54-7z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                                <span @if ($item->social_media_item_id && $item->views !== null) data-pf-views="{{ $item->id }}" @endif>{{ Metric::count($headline) }}</span>
                                <span class="sr-only">{{ $headlineLabel }}</span>
                            </span>
                        @endif
                    </div>

                </button>

                {{-- The caption sits outside the play button so a piece with a
                     case study behind it can link there without nesting a link
                     inside a button. Kept to title + client -- the description
                     stays on the case-study page, not repeated on every tile
                     in what is now a dense, small-card grid. --}}
                @php $detail = $item->hasCaseStudy() ? route('portfolio.detail', $item) : null; @endphp

                @if ($detail)
                    <a href="{{ $detail }}" class="block p-3">
                @else
                    <div class="p-3">
                @endif
                    @if ($item->category)
                        <p class="text-[10px] font-semibold uppercase tracking-widest text-brand-300 mb-0.5 truncate">{{ $item->category->name }}</p>
                    @endif
                    <p class="text-sm font-semibold leading-snug line-clamp-2">{{ $item->title }}</p>
                    @if ($item->clientLabel())
                        <p class="text-xs text-brand-100/70 mt-0.5 truncate">{{ $item->clientLabel() }}</p>
                    @endif

                    @if ($detail)
                        <span class="mt-2 inline-flex items-center gap-1 text-[11px] font-semibold uppercase tracking-widest text-brand-300 group-hover:text-brand-200">
                            Case study
                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                            </svg>
                        </span>
                    @endif
                @if ($detail)
                    </a>
                @else
                    </div>
                @endif
            </article>
        @endforeach
    </div>

    {{-- Every piece is hidden when a tab holds nothing the visitor can see. --}}
    <p x-show="! (counts[active] ?? 0)" x-cloak class="text-center text-brand-100/70 py-12">
        Nothing here yet — try another category.
    </p>

    {{-- Player. One per grid, reused by every card. --}}
    <div x-show="player" x-cloak @keydown.escape.window="close()"
         class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-8">
        <div class="absolute inset-0 bg-black/80" @click="close()"></div>

        <div class="relative w-full max-w-4xl">
            <div class="flex items-center justify-between gap-3 mb-3">
                <p class="font-semibold text-white truncate" x-text="player?.title"></p>
                <button type="button" @click="close()"
                        class="shrink-0 inline-flex items-center justify-center min-h-[44px] min-w-[44px] rounded-md text-white/70 hover:text-white hover:bg-white/10"
                        aria-label="Close video">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            {{-- Rebuilt per open so the <video> starts from the top and the
                 previous file stops downloading. --}}
            <template x-if="player && ! player.embed">
                <video controls autoplay playsinline class="w-full max-h-[75vh] rounded-lg bg-black"
                       :src="player.src"></video>
            </template>
            {{-- An Instagram reel plays right here, in Instagram's own player. --}}
            <template x-if="player && player.embed">
                <div class="mx-auto w-full max-w-[400px]">
                    <iframe :src="player.embed" class="h-[min(78vh,720px)] w-full rounded-lg bg-white" allowtransparency="true" allow="autoplay; encrypted-media; picture-in-picture" loading="lazy" title="Instagram reel"></iframe>
                    <a :href="player.src" target="_blank" rel="noopener" class="mt-2 block text-center text-xs text-white/60 hover:text-white">Open on Instagram ↗</a>
                </div>
            </template>
        </div>
    </div>

    {{-- Top performer: one of the best reels pops up in the corner. --}}
    <template x-if="star">
        <div class="pf-pop fixed bottom-4 left-4 right-4 z-40 sm:left-auto sm:right-6 sm:bottom-6 sm:w-[340px]" role="status">
            <div class="flex items-center gap-3 rounded-2xl border border-amber-300/30 bg-[#102633]/95 p-3 shadow-2xl backdrop-blur">
                <button type="button" @click="playStar()" class="relative h-[86px] w-[60px] shrink-0 overflow-hidden rounded-lg bg-brand-900" aria-label="Play this reel">
                    <template x-if="star.thumb"><img :src="star.thumb" alt="" class="h-full w-full object-cover"></template>
                    <span class="absolute inset-0 flex items-center justify-center bg-black/25 text-white">▶</span>
                </button>
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-bold uppercase tracking-[0.18em] text-amber-300">🔥 Top performer</p>
                    <p class="mt-0.5 truncate text-sm font-semibold text-white" x-text="star.title"></p>
                    <p class="text-xs text-brand-100/70"><span class="font-bold text-white" x-text="fmt(star.views)"></span> views<span x-show="star.client" x-text="' · ' + star.client"></span></p>
                    <button type="button" @click="playStar()" class="mt-1.5 text-xs font-semibold text-brand-300 hover:text-white">Watch it →</button>
                </div>
                <button type="button" @click="dismissStars()" class="self-start rounded-md p-1 text-white/50 hover:bg-white/10 hover:text-white" aria-label="Close">✕</button>
            </div>
        </div>
    </template>
</div>

@once
    @push('styles')
        <style>
            .pf-rise { animation: pfRise .7s cubic-bezier(.2,.8,.2,1) both; animation-delay: var(--d, 0ms); }
            .pf-card { opacity: 0; transform: translateY(18px); }
            .pf-card.pf-in { opacity: 1; transform: none; transition: opacity .55s ease, transform .55s cubic-bezier(.2,.8,.2,1), border-color .3s, box-shadow .3s; transition-delay: calc(var(--i) * 45ms), calc(var(--i) * 45ms), 0s, 0s; }
            .pf-card.pf-in:hover { transform: translateY(-4px); transition-delay: 0s; }
            .pf-bump { animation: pfBump .9s ease; }
            .pf-pop { animation: pfPop .55s cubic-bezier(.2,1.3,.4,1) both; }
            @keyframes pfRise { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: none; } }
            @keyframes pfBump { 0% { color: #fff; } 30% { color: #6ee7b7; transform: scale(1.18); } 100% { color: #fff; transform: none; } }
            @keyframes pfPop { from { opacity: 0; transform: translateY(24px) scale(.96); } to { opacity: 1; transform: none; } }
            @media (prefers-reduced-motion: reduce) { .pf-rise, .pf-pop, .pf-bump { animation: none; } .pf-card { opacity: 1; transform: none; } }
        </style>
    @endpush
    @push('scripts')
        <script>
            function portfolioGrid(initial, counts, stars, liveUrl) {
                return {
                    active: initial || 'all',
                    counts: counts || {},
                    player: null,
                    stars: stars || [],
                    star: null,
                    starAt: 0,

                    init() {
                        // Cards rise in as they scroll into view.
                        const cards = this.$el.querySelectorAll('[data-pf-card]');
                        if ('IntersectionObserver' in window) {
                            const io = new IntersectionObserver((entries) => entries.forEach((e) => {
                                if (e.isIntersecting) { e.target.classList.add('pf-in'); io.unobserve(e.target); }
                            }), { rootMargin: '0px 0px -40px 0px' });
                            cards.forEach((c) => io.observe(c));
                        } else {
                            cards.forEach((c) => c.classList.add('pf-in'));
                        }

                        // A top performer pops up after a few seconds, then the next one.
                        let off = false;
                        try { off = sessionStorage.getItem('pf-stars-off') === '1'; } catch (e) {}
                        if (this.stars.length && ! off) {
                            setTimeout(() => this.nextStar(), 6000);
                            this.starTimer = setInterval(() => { if (! this.player && ! document.hidden) this.nextStar(); }, 14000);
                        }

                        // Live numbers: re-read every minute while the page is open.
                        if (liveUrl) setInterval(() => { if (! document.hidden) this.live(liveUrl); }, 60000);
                    },

                    nextStar() {
                        this.star = null;
                        this.$nextTick(() => { this.star = this.stars[this.starAt % this.stars.length]; this.starAt++; });
                    },
                    playStar() {
                        const s = this.star;
                        if (! s) return;
                        this.openSrc(s.src, s.kind, s.title);
                    },
                    dismissStars() {
                        this.star = null;
                        clearInterval(this.starTimer);
                        try { sessionStorage.setItem('pf-stars-off', '1'); } catch (e) {}
                    },

                    async live(url) {
                        try {
                            const r = await fetch(url, { headers: { Accept: 'application/json' } });
                            if (! r.ok) return;
                            const d = await r.json();
                            Object.entries(d.items || {}).forEach(([id, n]) => {
                                const el = this.$el.querySelector(`[data-pf-views="${id}"]`);
                                const next = this.fmt(n.views);
                                if (el && n.views !== null && el.textContent.trim() !== next) {
                                    el.textContent = next;
                                    el.classList.remove('pf-bump'); void el.offsetWidth; el.classList.add('pf-bump');
                                }
                            });
                        } catch (e) {}
                    },

                    fmt(n) {
                        if (n === null || n === undefined) return '';
                        if (n >= 1e6) return (n / 1e6).toFixed(n >= 1e7 ? 0 : 1).replace(/\.0$/, '') + 'M';
                        if (n >= 1e3) return (n / 1e3).toFixed(n >= 1e4 ? 0 : 1).replace(/\.0$/, '') + 'K';
                        return String(n);
                    },

                    open(el) {
                        this.openSrc(el.dataset.video, el.dataset.kind, el.dataset.title);
                    },

                    openSrc(src, kind, title) {
                        if (! src) return;
                        let embed = null;
                        if (kind === 'link') {
                            // An Instagram reel plays here in Instagram's player; any other link opens where it lives.
                            const m = src.match(/instagram\.com\/(?:[^/]+\/)?(reel|reels|p|tv)\/([A-Za-z0-9_-]+)/);
                            if (! m) { window.open(src, '_blank', 'noopener'); return; }
                            embed = `https://www.instagram.com/${m[1] === 'reels' ? 'reel' : m[1]}/${m[2]}/embed`;
                        }
                        this.star = null;
                        this.player = { src, embed, title };
                        document.body.classList.add('overflow-hidden');
                    },

                    close() {
                        this.player = null;
                        document.body.classList.remove('overflow-hidden');
                    },
                };
            }
        </script>
    @endpush
@endonce

