@php
    /*
     * Competitors tracked for this client's market. Same scrape / analyze
     * pipeline as the dedicated Competitors module — scoped here so strategy
     * work happens on the client record rather than hunting by handle.
     */
@endphp

@unless ($competitorSettings?->hasApify())
    <div class="rounded-xl bg-amber-50 ring-1 ring-amber-200 p-4">
        <p class="text-sm text-amber-800">
            No Apify token set yet, so scraping won't work. Add one under
            @can('competitors.manage')
                <a href="{{ route('competitor-settings.edit') }}" class="font-semibold underline">Setup → Competitor Analysis</a>.
            @else
                Setup → Competitor Analysis.
            @endcan
        </p>
    </div>
@endunless

@can('competitors.create')
    <x-card class="p-4 sm:p-6 border border-brand-100/40">
        <x-section-heading title="Track a competitor"
                           subtitle="Instagram handle only — public scrape, no login needed." />

        <form method="POST" action="{{ route('competitors.store') }}"
              class="mt-4 grid grid-cols-1 sm:grid-cols-[1fr_auto] gap-3 items-end">
            @csrf
            <input type="hidden" name="client_id" value="{{ $client->id }}">
            <input type="hidden" name="return_to" value="{{ route('clients.show', $client) }}#competitors">
            <div>
                <x-input-label for="competitor_username" value="Instagram handle" />
                <x-text-input id="competitor_username" name="username" type="text" class="mt-1 w-full"
                              placeholder="@theircompanyname" value="{{ old('username') }}" required />
                <x-input-error :messages="$errors->get('username')" class="mt-2" />
            </div>
            <x-btn type="submit" icon="plus">Track</x-btn>
        </form>
    </x-card>
@endcan

@if ($competitors->isEmpty())
    <x-empty-state message="No competitors tracked for {{ $client->name }} yet." />
@else
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        @foreach ($competitors as $account)
            <article class="bg-white rounded-xl ring-1 ring-gray-200/80 overflow-hidden flex flex-col">
                <a href="{{ route('competitors.show', $account) }}"
                   class="flex-1 p-5 min-h-[44px] block hover:bg-brand-50/40 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 focus-visible:ring-inset">
                    <div class="flex items-start gap-3">
                        <div class="w-12 h-12 shrink-0 rounded-full bg-gray-100 ring-1 ring-gray-200 overflow-hidden flex items-center justify-center">
                            @if ($account->profile_pic_url)
                                <img src="{{ $account->profile_pic_url }}" alt="" loading="lazy" class="w-full h-full object-cover">
                            @else
                                <span class="text-sm font-bold text-gray-500">IG</span>
                            @endif
                        </div>
                        <div class="min-w-0 flex-1">
                            <h3 class="font-semibold text-gray-900 truncate">{{ $account->handle() }}</h3>
                            <p class="text-xs text-gray-500 mt-0.5">
                                {{ number_format($account->reels_count) }} {{ Str::plural('reel', $account->reels_count) }}
                                @if ($account->followers_count)
                                    &middot; {{ number_format($account->followers_count) }} followers
                                @endif
                            </p>
                            @if ($account->avg_views_30d)
                                <p class="text-sm text-gray-700 mt-2 tabular-nums">
                                    avg {{ number_format($account->avg_views_30d) }} views / 30d
                                </p>
                            @endif
                            <p class="text-xs text-gray-400 mt-1">
                                @if ($account->last_scraped_at)
                                    Scraped {{ $account->last_scraped_at->diffForHumans() }}
                                @else
                                    Never scraped
                                @endif
                            </p>
                        </div>
                    </div>
                </a>

                <div class="px-4 py-3 border-t border-gray-100 bg-gray-50/60 flex flex-wrap items-center justify-end gap-2">
                    @can('competitors.create')
                        <form method="POST" action="{{ route('competitors.scrape', $account) }}">
                            @csrf
                            <x-btn type="submit" size="sm" variant="secondary" icon="refresh">Scrape</x-btn>
                        </form>
                    @endcan
                    <x-btn :href="route('competitors.show', $account)" size="sm" variant="secondary">Open analysis</x-btn>
                    @can('competitors.delete')
                        <form method="POST" action="{{ route('competitors.destroy', $account) }}"
                              onsubmit="return confirm('Stop tracking {{ $account->handle() }}? Scraped reels and concepts go with it.');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="min-h-[44px] px-2 text-xs font-semibold text-red-600 hover:text-red-800">
                                Remove
                            </button>
                        </form>
                    @endcan
                </div>
            </article>
        @endforeach
    </div>

    <p class="text-xs text-gray-500">
        Full reel breakdowns and concept generation live on each competitor's analysis page.
        <a href="{{ route('competitors.index') }}" class="font-semibold text-brand-600 hover:text-brand-800">All competitors →</a>
    </p>
@endif
