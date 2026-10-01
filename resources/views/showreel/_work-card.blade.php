{{-- One reel in the work wall under the film, linking to its case study. --}}
<a href="{{ $work['url'] }}"
   class="group block relative rounded-2xl overflow-hidden ring-1 ring-white/10 bg-brand-800 shadow-xl shadow-black/30 hover:ring-brand-400/50 transition">
    <div class="aspect-[9/16]">
        <img src="{{ $work['cover'] }}" alt="{{ $work['title'] }}" loading="lazy" decoding="async"
             class="w-full h-full object-cover transition duration-700 group-hover:scale-105">
    </div>
    <div class="absolute inset-0 bg-gradient-to-t from-brand-900/95 via-brand-900/10 to-transparent"></div>

    @if ($work['views'])
        <span class="absolute top-2.5 left-2.5 sm:top-3 sm:left-3 inline-flex items-center gap-1 rounded-full bg-black/50 backdrop-blur px-2.5 py-1 text-[10px] sm:text-[11px] font-semibold text-white">
            <svg class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z" /></svg>
            {{ $work['views'] }} views
        </span>
    @endif

    <div class="absolute inset-x-0 bottom-0 p-3 sm:p-4">
        @if ($work['client'] || $work['category'])
            <p class="text-[10px] sm:text-[11px] font-semibold uppercase tracking-wider text-brand-300 truncate">
                {{ $work['client'] ?? $work['category'] }}
            </p>
        @endif
        <p class="mt-1 text-sm sm:text-base font-semibold leading-snug text-white line-clamp-2">{{ $work['title'] }}</p>
    </div>
</a>
