{{--
    Ready for the portfolio: client reels that crossed a views milestone
    (10K / 25K / 50K / 100K, see ViewMilestones) and are not in the
    portfolio yet. One tap adds it, mapped to its post, cover saved.
--}}
@php
    $taken = \App\Models\PortfolioItem::query()->whereNotNull('social_media_item_id')->pluck('social_media_item_id');
    $ready = \App\Models\SocialMediaItem::query()
        ->whereNotNull('views_milestone')->whereNotIn('id', $taken)
        ->with(['socialAccount.client', 'insights'])
        ->orderByDesc('views_milestone')->orderByDesc('posted_at')
        ->limit(24)->get()
        ->filter(fn ($m) => $m->isReel() && $m->socialAccount?->client)
        ->sortByDesc(fn ($m) => $m->metricValue('views'))
        ->take(8)->values();
    $canAdd = auth()->user()->can('portfolio.create');
@endphp

@if ($ready->isNotEmpty())
    <section class="space-y-3" aria-labelledby="w-portfolio">
        <div class="flex items-baseline justify-between gap-3">
            <h2 id="w-portfolio" class="text-[17px] font-semibold text-white">Ready for the portfolio <span class="font-normal text-brand-100/55">· reels that crossed 10K+</span></h2>
            <a href="{{ route('portfolio.index') }}" class="text-[13px] text-brand-300 hover:text-white">Portfolio</a>
        </div>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach ($ready as $m)
                @php $views = (int) $m->metricValue('views'); @endphp
                <div class="flex flex-col overflow-hidden rounded-2xl border border-white/[0.06] bg-[#15303f]">
                    <a href="{{ $m->permalink }}" target="_blank" rel="noopener" class="relative block aspect-[4/5] bg-brand-900">
                        @if ($m->thumbnail_url)
                            <img src="{{ $m->thumbnail_url }}" alt="" loading="lazy" class="h-full w-full object-cover" onerror="this.remove()">
                        @endif
                        <span class="absolute left-2 top-2 rounded-full bg-gradient-to-r from-amber-300 to-orange-400 px-2 py-0.5 text-[10px] font-extrabold text-brand-900">{{ \App\Support\Metric::count($m->views_milestone) }}+</span>
                        <span class="absolute bottom-2 left-2 rounded bg-black/60 px-1.5 py-0.5 text-[11px] font-semibold text-white">👁 {{ \App\Support\Metric::count($views) }}</span>
                    </a>
                    <div class="flex flex-1 flex-col gap-2 p-3">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-brand-300">{{ $m->socialAccount->client->name }}</p>
                        <p class="line-clamp-2 text-[13px] leading-snug text-white">{{ $m->shortCaption(80) }}</p>
                        @if ($canAdd)
                            <form method="POST" action="{{ route('portfolio.add-media', $m) }}" class="mt-auto">
                                @csrf
                                <button class="h-9 w-full rounded-lg bg-brand-400 text-[13px] font-bold text-brand-900 hover:bg-brand-300">+ Add to portfolio</button>
                            </form>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </section>
@endif
