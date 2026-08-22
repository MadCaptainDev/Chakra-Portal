<x-app-layout title="Competitors">
    <x-slot name="header">
        <x-page-header title="Competitors" eyebrow="Studio"
                       subtitle="Track a competitor's public Instagram account, scrape their reels, and see which ones outperform their own average.">
            @can('competitors.manage')
                <x-slot name="actions">
                    <x-btn :href="route('competitor-settings.edit')" variant="secondary" icon="cog">Settings</x-btn>
                </x-slot>
            @endcan
        </x-page-header>
    </x-slot>

    <div class="space-y-4">
        @unless ($settings->hasApify())
            <div class="rounded-xl bg-amber-50 ring-1 ring-amber-200 p-4">
                <p class="text-sm text-amber-800">
                    No Apify token set yet, so scraping won't work. Add one under
                    <a href="{{ route('competitor-settings.edit') }}" class="font-semibold underline">Setup → Competitor Analysis</a>.
                </p>
            </div>
        @endunless

        <x-card padding="md">
            <x-section-heading title="Track a new competitor" subtitle="Just the Instagram handle — no login or connection needed, this is a public scrape." />

            <form method="POST" action="{{ route('competitors.store') }}" class="grid grid-cols-1 sm:grid-cols-[1fr_1fr_auto] gap-3 items-end">
                @csrf
                <div>
                    <x-input-label for="username" value="Instagram handle" />
                    <x-text-input id="username" name="username" type="text" class="mt-1 w-full"
                                  placeholder="@theircompanyname" value="{{ old('username') }}" />
                    <x-input-error :messages="$errors->get('username')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="client_id" value="Whose competitor (optional)" />
                    <x-select id="client_id" name="client_id" class="mt-1 w-full">
                        <option value="">Not tied to a client</option>
                        @foreach ($clients as $client)
                            <option value="{{ $client->id }}" @selected(old('client_id') == $client->id)>{{ $client->name }}</option>
                        @endforeach
                    </x-select>
                </div>
                <x-btn type="submit" icon="plus">Track</x-btn>
            </form>
        </x-card>

        @if ($accounts->isEmpty())
            <x-empty-state message="Not tracking anyone yet." />
        @else
            <x-card class="divide-y divide-gray-100 overflow-hidden">
                @foreach ($accounts as $account)
                    <div class="p-4 flex flex-col sm:flex-row sm:items-center gap-3">
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('competitors.show', $account) }}" class="font-semibold text-gray-900 hover:text-brand-600">
                                {{ $account->handle() }}
                            </a>
                            <p class="text-xs text-gray-500 mt-0.5">
                                {{ $account->reels_count }} {{ Str::plural('reel', $account->reels_count) }} scraped
                                @if ($account->avg_views_30d)
                                    &middot; avg {{ number_format($account->avg_views_30d) }} views/30d
                                @endif
                                @if ($account->client)
                                    &middot; {{ $account->client->name }}'s competitor
                                @endif
                                @if ($account->last_scraped_at)
                                    &middot; last scraped {{ $account->last_scraped_at->diffForHumans() }}
                                @else
                                    &middot; never scraped
                                @endif
                            </p>
                        </div>

                        <div class="flex items-center gap-2 shrink-0">
                            <form method="POST" action="{{ route('competitors.scrape', $account) }}">
                                @csrf
                                <x-btn type="submit" size="sm" variant="secondary" icon="refresh">Scrape now</x-btn>
                            </form>
                            <form method="POST" action="{{ route('competitors.destroy', $account) }}"
                                  onsubmit="return confirm('Stop tracking {{ $account->handle() }}? Its scraped reels and any generated concepts go with it.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="min-h-[44px] px-2 text-xs font-semibold text-red-600 hover:text-red-800">Remove</button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </x-card>
        @endif
    </div>
</x-app-layout>
