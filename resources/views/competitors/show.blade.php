@php
    $unanalyzedCount = $reels->filter(fn ($reel) => ! $reel->isAnalyzed())->count();
@endphp

<x-app-layout title="{{ $competitor->handle() }}">
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
            @foreach ($reels as $reel)
                <x-card padding="md" x-data="{ generating: false }">
                    <div class="flex flex-col sm:flex-row gap-4">
                        @if ($reel->thumbnail_url)
                            <img src="{{ $reel->thumbnail_url }}" alt="" loading="lazy"
                                 class="w-full sm:w-32 aspect-[9/16] object-cover rounded-lg shrink-0">
                        @endif

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="font-semibold text-gray-900">{{ number_format((int) $reel->play_count) }} views</p>
                                @if ($reel->isViral())
                                    <x-badge status="active">Outperforming this account's average</x-badge>
                                @endif
                                @if ($reel->posted_at)
                                    <span class="text-xs text-gray-400">{{ $reel->posted_at->format('j M Y') }}</span>
                                @endif
                            </div>

                            @if ($reel->caption)
                                <p class="text-sm text-gray-600 mt-1 line-clamp-2">{{ $reel->caption }}</p>
                            @endif

                            @if ($reel->video_url)
                                <a href="{{ $reel->video_url }}" target="_blank" rel="noopener"
                                   class="text-xs font-semibold text-brand-600 hover:text-brand-800 mt-1 inline-block">
                                    Open on Instagram
                                </a>
                            @endif

                            @if ($reel->analysis)
                                <details class="mt-3">
                                    <summary class="text-xs font-semibold uppercase tracking-wider text-gray-500 cursor-pointer">
                                        Gemini's breakdown
                                    </summary>
                                    <p class="mt-2 text-sm text-gray-700 whitespace-pre-line">{{ $reel->analysis->breakdown }}</p>
                                </details>

                                <div class="mt-3 pt-3 border-t border-gray-100">
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
                            @endif
                        </div>
                    </div>
                </x-card>
            @endforeach
        @endif
    </div>
</x-app-layout>
