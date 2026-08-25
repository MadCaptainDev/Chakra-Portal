@php
    $unanalyzedCount = $reels->filter(fn ($reel) => ! $reel->isAnalyzed())->count();
@endphp

<x-app-layout :title="$competitor->handle()">
    <x-slot name="header">
        <x-page-header :title="$competitor->handle()"
                       eyebrow="Competitors"
                       :subtitle="$competitor->avg_views_30d
                            ? number_format($competitor->followers_count ?? 0).' followers · avg '.number_format($competitor->avg_views_30d).' views/30d'.($competitor->client ? ' · '.$competitor->client->name.'\'s competitor' : '')
                            : 'Not scraped yet'">
            <x-slot name="actions">
                <form method="POST" action="{{ route('competitors.scrape', $competitor) }}">
                    @csrf
                    <x-btn type="submit" variant="secondary" icon="refresh">Scrape now</x-btn>
                </form>
                <x-btn :href="route('competitors.index')" variant="secondary">All competitors</x-btn>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-4">
        @unless ($settings->hasGemini())
            <div class="rounded-xl bg-amber-50 ring-1 ring-amber-200 p-4">
                <p class="text-sm text-amber-800">
                    No Gemini key set, so reels can't be analyzed yet. Add one under
                    <a href="{{ route('competitor-settings.edit') }}" class="font-semibold underline">Setup → Competitor Analysis</a>.
                </p>
            </div>
        @else
            @if ($unanalyzedCount > 0)
                <div class="rounded-xl bg-brand-50 ring-1 ring-brand-200 p-4">
                    <p class="text-sm text-brand-800">
                        {{ $unanalyzedCount }} {{ Str::plural('reel', $unanalyzedCount) }} not analyzed yet. Run this over SSH:
                    </p>
                    <code class="block mt-1.5 text-xs bg-white/60 rounded px-2 py-1 text-brand-900">php artisan competitors:analyze --account={{ $competitor->id }}</code>
                </div>
            @endif
        @endunless

        @if ($reels->isEmpty())
            <x-empty-state message="Nothing scraped yet.">
                <form method="POST" action="{{ route('competitors.scrape', $competitor) }}">
                    @csrf
                    <x-btn type="submit" size="sm">Scrape now</x-btn>
                </form>
            </x-empty-state>
        @else
            {{-- Thumbnail first, insights on the still, case study behind a click
                 — same card shape as the public portfolio grid, so a reel reads
                 as a reel rather than as a row of numbers with a postage stamp. --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
                @foreach ($reels as $reel)
                    <x-card padding="none" class="overflow-hidden flex flex-col"
                            x-data="{ caseStudy: false, generating: false }">
                        <div class="relative aspect-[9/16] bg-gray-100">
                            @if ($reel->thumbnail_url)
                                <img src="{{ $reel->thumbnail_url }}" alt="" loading="lazy"
                                     class="absolute inset-0 w-full h-full object-cover">
                            @endif

                            {{-- Full-frame overlay: badge pinned top, metrics in a
                                 deep bottom scrim so neither fights the other or
                                 washes out on bright stills. --}}
                            <div class="absolute inset-0 z-10 flex flex-col justify-between pointer-events-none">
                                <div class="p-2">
                                    @if ($reel->isViral())
                                        <span class="inline-flex items-center max-w-full truncate rounded-full bg-emerald-600 px-2 py-0.5 text-[10px] font-semibold leading-tight text-white shadow-sm ring-1 ring-black/10">
                                            Outperforming
                                        </span>
                                    @endif
                                </div>

                                <div class="bg-gradient-to-t from-black/90 via-black/55 to-transparent px-2.5 pb-2.5 pt-12 text-white">
                                    <div class="flex flex-col gap-0.5 drop-shadow-[0_1px_2px_rgba(0,0,0,0.85)]">
                                        <div class="flex flex-wrap items-center gap-x-2.5 gap-y-0.5 text-[11px] font-semibold tabular-nums leading-snug">
                                            <span class="inline-flex items-center gap-1">
                                                <x-icon name="eye" class="w-3 h-3 shrink-0 opacity-90" />
                                                {{ \App\Support\Metric::count($reel->play_count) }}
                                                <span class="font-medium text-white/85">views</span>
                                            </span>
                                            @if ($reel->like_count !== null)
                                                <span>{{ \App\Support\Metric::count($reel->like_count) }} <span class="font-medium text-white/85">likes</span></span>
                                            @endif
                                            @if ($reel->comment_count !== null)
                                                <span>{{ \App\Support\Metric::count($reel->comment_count) }} <span class="font-medium text-white/85">comments</span></span>
                                            @endif
                                        </div>
                                        @if ($reel->posted_at)
                                            <p class="text-[10px] font-medium text-white/90">{{ $reel->posted_at->format('j M Y') }}</p>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="p-4 flex flex-col gap-3 flex-1">
                            @if ($reel->caption)
                                <p class="text-sm text-gray-600 line-clamp-2">{{ $reel->caption }}</p>
                            @endif

                            <div class="flex flex-wrap items-center gap-2 mt-auto">
                                @if ($reel->video_url)
                                    <a href="{{ $reel->video_url }}" target="_blank" rel="noopener"
                                       class="text-xs font-semibold text-brand-600 hover:text-brand-800 min-h-[44px] inline-flex items-center">
                                        Open on Instagram
                                    </a>
                                @endif

                                @if ($reel->analysis)
                                    <button type="button"
                                            @click="caseStudy = ! caseStudy"
                                            class="ml-auto inline-flex items-center gap-1 min-h-[44px] text-xs font-semibold uppercase tracking-widest text-brand-700 hover:text-brand-900">
                                        Case study
                                        <svg class="w-3.5 h-3.5 transition-transform" :class="caseStudy && 'rotate-90'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                                        </svg>
                                    </button>
                                @else
                                    <span class="ml-auto text-[11px] text-gray-400">Not analyzed yet</span>
                                @endif
                            </div>

                            @if ($reel->analysis)
                                <div x-show="caseStudy" x-cloak class="pt-3 border-t border-gray-100 space-y-3">
                                    <div>
                                        <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">Gemini's breakdown</p>
                                        <p class="mt-2 text-sm text-gray-700 whitespace-pre-line">{{ $reel->analysis->breakdown }}</p>
                                    </div>

                                    <div>
                                        <button type="button" @click="generating = ! generating"
                                                class="text-xs font-semibold text-brand-600 hover:text-brand-800 min-h-[44px]">
                                            <span x-show="! generating">+ Generate concepts</span>
                                            <span x-show="generating" x-cloak>Cancel</span>
                                        </button>

                                        <form x-show="generating" x-cloak method="POST"
                                              action="{{ route('competitor-reel-analyses.generate-concepts', $reel->analysis) }}"
                                              class="mt-2 space-y-2">
                                            @csrf
                                            <x-select name="client_id" class="w-full">
                                                <option value="">Not tied to a client</option>
                                                @foreach ($clients as $client)
                                                    <option value="{{ $client->id }}">{{ $client->name }}</option>
                                                @endforeach
                                            </x-select>
                                            <x-textarea name="brand_prompt" rows="3" class="w-full"
                                                        placeholder="Adapt this for [brand] — tone, audience, what to keep or change..."></x-textarea>
                                            <x-btn type="submit" size="sm">Generate</x-btn>
                                        </form>

                                        @if ($reel->analysis->concepts->isNotEmpty())
                                            <div class="mt-3 space-y-3">
                                                @foreach ($reel->analysis->concepts->sortByDesc('generated_at') as $concept)
                                                    <div class="rounded-lg bg-gray-50 p-3">
                                                        <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">
                                                            {{ $concept->client?->name ?? 'General' }} &middot; {{ $concept->generated_at->diffForHumans() }}
                                                        </p>
                                                        <p class="mt-1.5 text-sm text-gray-700 whitespace-pre-line">{{ $concept->concept_text }}</p>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        </div>
                    </x-card>
                @endforeach
            </div>
        @endif
    </div>
</x-app-layout>
