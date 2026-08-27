@php
    /*
     * Instagram analytics for one client, read entirely from the local cache
     * -- see InstagramInsightsController for why nothing here calls Instagram.
     *
     * $account is null when nothing is connected, in which case the page is
     * one sentence and a link back to Social Media rather than a wall of
     * empty stat cards.
     */
    $rangeQuery = fn (string $key) => route('instagram.insights', $client) . '?range=' . $key;
@endphp

<x-app-layout title="Instagram Analytics">
    <x-slot name="header">
        <x-page-header :title="$client->name" eyebrow="Instagram Analytics">
            <x-slot name="actions">
                @if ($account)
                    <a href="{{ route('instagram.report', $client) }}"
                       class="text-xs font-semibold uppercase tracking-widest text-brand-300 hover:text-brand-200">
                        Monthly report
                    </a>
                @endif
                <a href="{{ route('clients.show', $client) }}#social"
                   class="text-xs font-semibold uppercase tracking-widest text-brand-300 hover:text-brand-200">
                    ← Back to client
                </a>
            </x-slot>
        </x-page-header>
    </x-slot>

    @if (! $account)
        <x-card padding="md" class="max-w-lg">
            <p class="text-sm text-brand-100/70">
                No Instagram account is connected for {{ $client->name }} yet.
            </p>
            <a href="{{ route('clients.show', $client) }}#social"
               class="mt-3 inline-flex items-center gap-1.5 text-xs font-semibold uppercase tracking-widest text-brand-300 hover:text-brand-200">
                Connect Instagram
            </a>
        </x-card>
    @else
        @php
            // A sortable column header, carrying the current range alongside
            // the sort choice -- picking a column must not reset whatever
            // dates are on screen. Clicking the already-active column flips
            // direction; clicking a different one starts it at desc
            // (highest first is the more useful default for "what worked").
            $sortLink = fn (string $key) => route('instagram.insights', $client) . '?' . http_build_query([
                'range' => $rangeKey,
                'from' => $since->toDateString(),
                'to' => $until->toDateString(),
                'sort' => $key,
                'direction' => ($sortBy === $key && $direction === 'desc') ? 'asc' : 'desc',
            ]);
        @endphp

        <div class="space-y-6">

            {{-- Account strip: who, and how fresh the numbers are. --}}
            <x-card padding="md">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        @if ($account->profile_picture_url)
                            <img src="{{ $account->profile_picture_url }}" alt="" onerror="this.remove()"
                                 class="w-10 h-10 rounded-full object-cover ring-1 ring-white/10">
                        @endif
                        <div>
                            <p class="text-sm font-semibold text-white">{{ $account->handle() }}</p>
                            <p class="text-xs text-brand-100/60">
                                {{-- The exact date and time, not "2 hours ago": a
                                     number on a client-facing screen should say
                                     precisely when it was true, in the studio's
                                     own timezone, not leave that to guesswork. --}}
                                @if ($account->last_synced_at)
                                    Synced {{ $account->last_synced_at->format('j M Y, g:i A') }} IST
                                    ({{ $account->last_synced_at->diffForHumans() }})
                                @else
                                    Never synced
                                @endif
                            </p>
                        </div>
                    </div>

                    <div class="text-right">
                        {{-- from/to are carried alongside range, not just
                             range alone: on a custom range, resolveRange()
                             needs them to reconstruct the exact window
                             being viewed -- without them, a custom-range
                             "Sync now" silently synced the last-30-days
                             default instead of the dates actually on
                             screen. Harmless extra params on a preset
                             range, since those branches ignore from/to. --}}
                        <form method="POST" action="{{ route('instagram.insights.sync', $client) }}?range={{ $rangeKey }}&from={{ $since->toDateString() }}&to={{ $until->toDateString() }}">
                            @csrf
                            <x-secondary-button type="submit" :disabled="! $account->canSyncNow()">
                                Sync now
                            </x-secondary-button>
                        </form>
                        {{-- Shown before the click, not just after a refused
                             one: a disabled-looking button with no explanation
                             reads as broken rather than as "already synced". --}}
                        @unless ($account->canSyncNow())
                            <p class="mt-1 text-[11px] text-brand-100/50">
                                Again in {{ now()->diffForHumans($account->nextSyncAllowedAt(), true) }}
                            </p>
                        @endunless
                    </div>
                </div>

                {{-- The exact window every number below covers. Ranges like
                     "This month" mean nothing without saying which month, and
                     Instagram's own day boundary does not line up with an IST
                     midnight -- see InstagramInsights for why -- so the dates
                     shown are the studio's own IST calendar days, not
                     Instagram's Pacific-anchored ones underneath them. --}}
                <p class="mt-3 text-xs text-brand-100/60">
                    Showing <span class="font-medium text-brand-100/80">{{ $since->format('j M Y') }}</span>
                    to <span class="font-medium text-brand-100/80">{{ $until->format('j M Y') }}</span> (Asia/Kolkata).
                </p>

                @if ($account->last_error)
                    <p class="mt-3 text-sm text-amber-200 bg-amber-400/10 ring-1 ring-amber-400/20 rounded-lg px-3 py-2">
                        Last problem: {{ $account->last_error }}
                    </p>
                @endif
            </x-card>

            {{-- Date range. Plain links rather than JS state: the whole page
                 is server-rendered from the range in the query string, so a
                 link is simpler than an Alpine store that has to refetch. --}}
            <div class="flex flex-wrap items-center gap-2">
                @foreach ($ranges as $key => $label)
                    <a href="{{ $rangeQuery($key) }}"
                       @class([
                           'inline-flex items-center min-h-[36px] px-3 rounded-full text-xs font-semibold transition-colors',
                           'bg-brand-500 text-white' => $rangeKey === $key,
                           'bg-white/5 text-brand-100/70 ring-1 ring-white/10 hover:ring-white/15' => $rangeKey !== $key,
                       ])>
                        {{ $label }}
                    </a>
                @endforeach

                {{-- Custom range, its own small form so it can carry two dates
                     without a JS date-range widget nothing else in the product
                     uses. --}}
                <form method="GET" action="{{ route('instagram.insights', $client) }}"
                      class="inline-flex items-center gap-1.5">
                    <input type="hidden" name="range" value="custom">
                    <input type="date" name="from" value="{{ $since->toDateString() }}"
                           class="rounded-md border-white/15 text-xs py-1.5">
                    <span class="text-xs text-brand-100/50">to</span>
                    <input type="date" name="to" value="{{ $until->toDateString() }}"
                           class="rounded-md border-white/15 text-xs py-1.5">
                    <button type="submit"
                            class="inline-flex items-center min-h-[36px] px-3 rounded-full text-xs font-semibold transition-colors
                                   {{ $rangeKey === 'custom' ? 'bg-brand-500 text-white' : 'bg-white/5 text-brand-100/70 ring-1 ring-white/10 hover:ring-white/15' }}">
                        Go
                    </button>
                </form>
            </div>

            @if ($beyondAccountInsights)
                {{-- Not a "press Sync now" situation -- Instagram itself no
                     longer serves account-wide daily data this old (its own
                     90-day retention on reach/follower/views/engagement),
                     so no sync, ours or anyone else's, can populate it.
                     Individual posts published in this range are a
                     separate story -- Content Performance below still shows
                     them, since per-post metrics are not bound by the same
                     90-day wall. --}}
                <p class="text-xs text-amber-200 bg-amber-400/10 ring-1 ring-amber-400/20 rounded-lg px-3 py-2">
                    Part of this range is older than Instagram's own 90-day window for account-wide numbers
                    (Reach, Follower growth, Views trend, Engagement breakdown below) — those will look thin
                    or empty for dates before {{ now()->subDays(90)->format('j M Y') }}, and no amount of
                    syncing brings that back. Individual posts from this range still show their own numbers
                    in Content performance further down.
                </p>
            @endif

            {{-- Overview --}}
            <div class="grid grid-cols-2 lg:grid-cols-5 gap-3">
                <x-stat-card label="Reach" :value="number_format($overview['reach'])" icon="trending-up" accent="brand" />
                <x-stat-card label="Views" :value="number_format($overview['views'])" icon="eye" accent="brand" />
                <x-stat-card label="Engagement" :value="number_format($overview['engagement'])" icon="sparkles" accent="green" />
                <x-stat-card label="Followers" :value="$overview['followers'] !== null ? number_format($overview['followers']) : '—'" icon="users" accent="gray" />
                <x-stat-card label="Follower growth"
                    :value="$overview['follower_growth'] !== null ? ($overview['follower_growth'] >= 0 ? '+' : '').number_format($overview['follower_growth']) : '—'"
                    icon="trending-up"
                    :accent="($overview['follower_growth'] ?? 0) > 0 ? 'green' : (($overview['follower_growth'] ?? 0) < 0 ? 'red' : 'gray')" />
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-5 gap-4">
                {{-- Trend --}}
                <div class="lg:col-span-3">
                    <x-charts.metric-trend :days="$trend" title="Reach, day by day"
                        empty="No reach data cached for this range yet — press Sync now." />

                    @php
                        $lastDay = end($trend);
                        $isPartial = $lastDay && $lastDay['date'] === now()->toDateString();
                    @endphp
                    <p class="mt-2 text-[11px] text-brand-100/50">
                        Dates are Instagram's own daily figures, read in Asia/Kolkata.
                        @if ($isPartial)
                            Today's bar is still accumulating — check back later for the full day.
                        @endif
                    </p>
                </div>

                {{-- Engagement breakdown --}}
                <x-card padding="md" class="lg:col-span-2">
                    <x-section-heading title="Engagement breakdown"
                        subtitle="What the {{ number_format($overview['engagement']) }} total engagements were made of." />
                    <x-charts.bar-list :items="$breakdown" empty="No engagement data cached for this range yet." />
                    @if (collect($breakdown)->sum('value') > 0 && collect($breakdown)->sum('value') != $overview['engagement'])
                        <p class="mt-3 text-[11px] text-brand-100/50">
                            These six add up to {{ number_format(collect($breakdown)->sum('value')) }}. Instagram's total
                            can include a few interaction types the studio does not break out individually.
                        </p>
                    @endif
                </x-card>
            </div>

            {{-- What was published, by type -- "No of Video Shared" and
                 friends. Same tile markup as the Monthly Report's own
                 "What we published" section, so the two screens read the
                 same way. A Reel is never counted as a Video --
                 typeLabel() checks isReel() first. --}}
            <x-card padding="md">
                <x-section-heading title="What was published"
                    subtitle="{{ $content->count() }} piece(s) {{ $since->format('j M') }}–{{ $until->format('j M Y') }}." />
                @if ($formats)
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        @foreach ($formats as $format)
                            <div class="rounded-lg bg-brand-900/40 ring-1 ring-white/10 px-4 py-3">
                                <p class="text-2xl font-bold text-white tabular-nums">{{ $format['count'] }}</p>
                                <p class="mt-1 text-[11px] font-semibold uppercase tracking-wider text-brand-100/60">{{ $format['label'] }}{{ $format['count'] === 1 ? '' : 's' }}</p>
                            </div>
                        @endforeach
                    </div>
                @else
                    <x-empty-state message="Nothing was posted in this date range." />
                @endif
            </x-card>

            {{-- Content performance --}}
            <x-card padding="none">
                <div class="p-4 sm:p-5 pb-0">
                    <x-section-heading title="Content performance"
                        subtitle="Posts and reels published {{ $since->format('j M') }}–{{ $until->format('j M Y') }}, sorted by {{ strtolower(\App\Services\Instagram\InstagramReportData::SORTABLE[$sortBy]) }} ({{ $direction === 'desc' ? 'highest first' : 'lowest first' }})." />
                </div>

                @if ($content->isEmpty())
                    <div class="p-4 sm:p-5">
                        @if ($account->socialMediaItems()->exists())
                            {{-- Media has been synced, just nothing was posted in
                                 THIS range -- a different message from "nothing
                                 has ever been synced", since Sync now would not
                                 fix this one. --}}
                            <x-empty-state message="Nothing was posted in this date range. Try a wider range." />
                        @else
                            <x-empty-state message="No media cached yet — press Sync now to pull recent posts." />
                        @endif
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="text-left text-[11px] font-semibold uppercase tracking-wider text-brand-100/60 border-b border-white/10">
                                    <th class="px-4 sm:px-5 py-2.5">
                                        <a href="{{ $sortLink('date') }}" class="inline-flex items-center gap-1 hover:text-brand-100/80">
                                            Content
                                            @if ($sortBy === 'date')
                                                <span aria-hidden="true">{{ $direction === 'desc' ? '▼' : '▲' }}</span>
                                            @endif
                                        </a>
                                    </th>
                                    <th class="px-3 py-2.5">Type</th>
                                    @foreach (['reach' => 'Reach', 'views' => 'Views', 'engagement' => 'Engagement'] as $key => $label)
                                        <th class="px-3 py-2.5 text-right">
                                            <a href="{{ $sortLink($key) }}" class="inline-flex items-center gap-1 hover:text-brand-100/80">
                                                {{ $label }}
                                                @if ($sortBy === $key)
                                                    <span aria-hidden="true">{{ $direction === 'desc' ? '▼' : '▲' }}</span>
                                                @endif
                                            </a>
                                        </th>
                                    @endforeach
                                    @can('portfolio.create')
                                        <th class="px-3 py-2.5"><span class="sr-only">Actions</span></th>
                                    @endcan
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/10">
                                @foreach ($content as $item)
                                    <tr>
                                        <td class="px-4 sm:px-5 py-2.5">
                                            <div class="flex items-center gap-3 min-w-0">
                                                @if ($item->thumbnail_url || $item->media_url)
                                                    <img src="{{ $item->thumbnail_url ?: $item->media_url }}" alt=""
                                                         onerror="this.remove()"
                                                         class="w-9 h-9 rounded-md object-cover ring-1 ring-white/10 shrink-0">
                                                @endif
                                                <div class="min-w-0">
                                                    @if ($item->permalink)
                                                        <a href="{{ $item->permalink }}" target="_blank" rel="noopener"
                                                           class="text-white font-medium hover:text-brand-300 truncate block max-w-xs">
                                                            {{ $item->shortCaption() }}
                                                        </a>
                                                    @else
                                                        <span class="text-white font-medium truncate block max-w-xs">{{ $item->shortCaption() }}</span>
                                                    @endif
                                                    <span class="text-xs text-brand-100/50">{{ $item->posted_at?->format('j M Y') }}</span>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-3 py-2.5 whitespace-nowrap">
                                            <x-badge color="{{ $item->isReel() ? 'bg-purple-400/15 text-purple-200' : 'bg-white/10 text-brand-100/70' }}">
                                                {{ $item->typeLabel() }}
                                            </x-badge>
                                        </td>
                                        <td class="px-3 py-2.5 text-right tabular-nums text-white">
                                            {{ $item->metricValue('reach') !== null ? number_format($item->metricValue('reach')) : '—' }}
                                        </td>
                                        <td class="px-3 py-2.5 text-right tabular-nums text-white">
                                            {{ $item->metricValue('views') !== null ? number_format($item->metricValue('views')) : '—' }}
                                        </td>
                                        <td class="px-3 py-2.5 text-right tabular-nums text-white">
                                            {{ $item->metricValue('total_interactions') !== null ? number_format($item->metricValue('total_interactions')) : '—' }}
                                        </td>
                                        @can('portfolio.create')
                                            <td class="px-3 py-2.5 whitespace-nowrap">
                                                <a href="{{ route('portfolio.create', ['client_id' => $client->id, 'media_id' => $item->id]) }}"
                                                   class="text-xs font-semibold text-brand-300 hover:text-brand-200">
                                                    Add to portfolio
                                                </a>
                                            </td>
                                        @endcan
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>
        </div>
    @endif
</x-app-layout>
