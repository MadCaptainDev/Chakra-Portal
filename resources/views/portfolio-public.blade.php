<x-public-layout
    title="Work — Chakra Productions"
    description="Films, series and short-form work from Chakra Productions, by category.">

    <section class="border-b border-white/10">
        <div class="max-w-7xl mx-auto px-5 sm:px-8 py-16 sm:py-20">
            <p class="text-brand-300 text-xs font-semibold uppercase tracking-[0.25em] mb-4">Portfolio</p>
            <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold leading-[1.05] max-w-3xl">
                Everything we have made, in one place.
            </h1>
            <p class="mt-5 text-lg text-brand-100/70 max-w-2xl leading-relaxed">
                Pick a category to narrow it down, then tap any still to play the film.
            </p>
        </div>
    </section>

    <section class="scroll-mt-20">
        <div class="max-w-7xl mx-auto px-5 sm:px-8 py-12 sm:py-16">
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
