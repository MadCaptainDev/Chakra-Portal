<x-app-layout title="Your Work" dark>
    <div class="space-y-6">

        <div class="animate-rise-in">
            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-300">{{ $client->name }}</p>
            <h1 class="mt-2 text-3xl sm:text-4xl font-extrabold tracking-tight">Your Work</h1>
            <p class="mt-2 text-sm text-brand-100/70">Finished pieces, ready to look back on.</p>
        </div>

        @if ($items->isEmpty())
            <div class="rounded-xl border border-dashed border-white/15 px-6 py-12 text-center">
                <p class="text-sm text-brand-100/70">Nothing published to the portfolio yet.</p>
                <p class="mt-1 text-xs text-brand-100/50">Finished work appears here as it's added.</p>
            </div>
        @else
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3.5">
                @foreach ($items as $item)
                    <a href="{{ route('portfolio.detail', $item) }}" target="_blank" rel="noopener"
                       class="group block rounded-xl overflow-hidden bg-white/5 ring-1 ring-white/10 hover:ring-white/25 transition">
                        <div class="relative {{ $item->isVertical() ? 'aspect-[9/16]' : 'aspect-video' }} bg-brand-900/60">
                            @if ($item->thumbnailUrl())
                                <img src="{{ $item->thumbnailUrl() }}" alt="" loading="lazy"
                                     class="absolute inset-0 w-full h-full object-cover">
                            @else
                                <div class="absolute inset-0 flex items-center justify-center">
                                    <x-icon name="sparkles" class="w-6 h-6 text-brand-100/30" />
                                </div>
                            @endif
                            <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-black/0 to-black/0 opacity-0 group-hover:opacity-100 transition"></div>
                        </div>
                        <div class="p-3">
                            <p class="text-sm font-semibold text-white truncate">{{ $item->title ?: 'Untitled' }}</p>
                            <p class="mt-0.5 text-xs text-brand-100/60 truncate">
                                {{ $item->formatLabel() ?: $item->platformLabel() ?: '—' }}
                                @if ($item->published_on) &middot; {{ $item->published_on->format('M Y') }} @endif
                            </p>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</x-app-layout>
