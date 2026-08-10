@php
    /*
    | The tabbed video grid, shared by the landing page's Portfolio section and
    | the full /portfolio screen.
    |
    | Expects: $categories, $items. Optional: $activeCategory (slug), $columns.
    |
    | Filtering is client-side on purpose -- tapping a tab must not reload the
    | page and lose the visitor's place, and the number of pieces a studio
    | publishes stays well inside what one page can hold.
    */
    $activeCategory = $activeCategory ?? 'all';
    $columns = $columns ?? 'sm:grid-cols-2 lg:grid-cols-3';

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
@endphp

<div x-data="portfolioGrid(@js($tabs->contains('slug', $activeCategory) ? $activeCategory : 'all'), @js($counts))">
    @if ($tabs->isNotEmpty())
        <div class="-mx-5 sm:mx-0 px-5 sm:px-0 overflow-x-auto">
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

    <div class="grid grid-cols-1 {{ $columns }} gap-4 sm:gap-5 mt-8">
        @foreach ($items as $item)
            @php $slug = $item->category?->slug ?? 'other'; @endphp

            <article x-show="active === 'all' || active === @js($slug)"
                     class="group rounded-xl overflow-hidden bg-white/5 border border-white/10 hover:border-brand-400/40 transition-colors">
                <button type="button"
                        @click="open($el)"
                        data-video="{{ $item->playbackUrl() }}"
                        data-kind="{{ $item->isUploaded() ? 'file' : 'link' }}"
                        data-title="{{ $item->title }}"
                        @unless ($item->playbackUrl()) disabled @endunless
                        class="block w-full text-left">
                    <div class="relative aspect-video bg-brand-900/60 overflow-hidden">
                        {{-- No thumbnail falls back to the uploaded file's first
                             frame, so a card is never a blank rectangle. --}}
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
                    </div>

                </button>

                {{-- The caption sits outside the play button so a piece with a
                     case study behind it can link there without nesting a link
                     inside a button. --}}
                @php $detail = $item->hasCaseStudy() ? route('portfolio.detail', $item) : null; @endphp

                @if ($detail)
                    <a href="{{ $detail }}" class="block p-5">
                @else
                    <div class="p-5">
                @endif
                    @if ($item->category)
                        <p class="text-[11px] font-semibold uppercase tracking-widest text-brand-300 mb-1">{{ $item->category->name }}</p>
                    @endif
                    <p class="font-semibold">{{ $item->title }}</p>
                    @if ($item->clientLabel())
                        <p class="text-sm text-brand-100/70 mt-0.5">{{ $item->clientLabel() }}</p>
                    @endif
                    @if ($item->description)
                        <p class="text-sm text-brand-100/60 mt-2 leading-relaxed">{{ $item->description }}</p>
                    @endif

                    @if ($detail)
                        <span class="mt-3 inline-flex items-center gap-1.5 min-h-[44px] text-xs font-semibold uppercase tracking-widest text-brand-300 group-hover:text-brand-200">
                            Read the case study
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
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
            <template x-if="player">
                <video controls autoplay playsinline class="w-full max-h-[75vh] rounded-lg bg-black"
                       :src="player.src"></video>
            </template>
        </div>
    </div>
</div>

@once
    @push('scripts')
        <script>
            function portfolioGrid(initial, counts) {
                return {
                    active: initial || 'all',
                    counts: counts || {},
                    player: null,

                    open(el) {
                        const src = el.dataset.video;
                        if (! src) return;

                        // A linked video lives on someone else's player, so
                        // send the visitor there rather than embedding it.
                        if (el.dataset.kind === 'link') {
                            window.open(src, '_blank', 'noopener');
                            return;
                        }

                        this.player = { src, mime: el.dataset.mime, title: el.dataset.title };
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
