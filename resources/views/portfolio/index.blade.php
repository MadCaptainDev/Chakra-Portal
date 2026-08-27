@php
    use App\Support\Metric;

    $missingVideo = $totals['noVideo'];
    $isFiltered = $filters['q'] !== '' || $filters['category'] !== '' || $filters['status'] !== '';
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Portfolio">
            <x-slot name="actions">
                <x-btn :href="route('portfolio-categories.index')" variant="secondary">Categories</x-btn>
                <x-btn :href="route('taxonomy.index')" variant="secondary">Master data</x-btn>
                <x-btn :href="route('portfolio')" target="_blank" rel="noopener" variant="secondary" icon="eye">View page</x-btn>
                <x-btn :href="route('portfolio.create')" icon="plus">Add video</x-btn>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-4">
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <x-stat-card label="Pieces" value="{{ $totals['all'] }}" accent="gray" />
            <x-stat-card label="Live on site" value="{{ $totals['live'] }}" accent="green" />
            <x-stat-card label="Featured" value="{{ $totals['featured'] }}" accent="brand">
                Lead the landing page
            </x-stat-card>
            <x-stat-card label="Categories" value="{{ $categories->count() }}" accent="gray" />
        </div>

        {{-- Worth adding: synced Instagram posts/reels already outperforming
             the portfolio's own average that nobody has added yet. See
             App\Support\PortfolioSuggestions -- the bar is the portfolio's
             own average views/reach, not a fixed number. Independent of the
             filters below; this reads the whole catalogue every time. --}}
        @if ($suggestions->isNotEmpty())
            <x-card padding="md">
                <div class="flex items-center gap-2 mb-1">
                    <x-icon name="sparkles" class="w-4 h-4 text-brand-500" />
                    <h2 class="text-sm font-semibold text-white">Worth adding</h2>
                </div>
                <p class="text-xs text-brand-100/60 mb-3">
                    Synced from Instagram, already outperforming this portfolio's own average, and not added yet.
                </p>

                <div class="flex gap-3 overflow-x-auto pb-1">
                    @foreach ($suggestions as $suggestion)
                        @php $media = $suggestion['media']; @endphp
                        <a href="{{ route('portfolio.create', ['client_id' => $suggestion['clientId'], 'media_id' => $media->id]) }}"
                           class="shrink-0 w-40 text-left rounded-lg border border-white/10 hover:border-brand-300 hover:bg-white/10 overflow-hidden transition-colors">
                            <div class="{{ $media->isReel() ? 'aspect-[9/16]' : 'aspect-video' }} bg-white/10 relative">
                                @if ($media->thumbnail_url ?: $media->media_url)
                                    <img src="{{ $media->thumbnail_url ?: $media->media_url }}" alt="" loading="lazy"
                                         class="w-full h-full object-cover">
                                @endif
                                <span class="absolute left-1.5 bottom-1.5 inline-flex items-center gap-1 px-1.5 py-0.5 rounded bg-black/60 text-[10px] font-semibold text-white">
                                    <x-icon name="eye" class="w-3 h-3" />
                                    {{ Metric::count($suggestion['value']) }}
                                </span>
                            </div>
                            <div class="p-2">
                                <p class="text-[11px] font-semibold text-white truncate">{{ $media->shortCaption(40) }}</p>
                                <p class="mt-0.5 text-[10px] text-brand-100/60 truncate">
                                    {{ $suggestion['clientName'] ?? 'Unlinked client' }} &middot; {{ $media->typeLabel() }}
                                </p>
                            </div>
                        </a>
                    @endforeach
                </div>
            </x-card>
        @endif

        {{-- Find one piece without scrolling the lot. Plain GET, so a filtered
             view is a URL staff can bookmark or send to each other. --}}
        <x-card class="p-3 sm:p-4">
            <form method="GET" action="{{ route('portfolio.index') }}"
                  class="grid grid-cols-1 sm:grid-cols-[1fr_auto_auto_auto] gap-3 sm:items-end">
                <div>
                    <x-input-label for="q" value="Search" />
                    <x-text-input id="q" name="q" type="search" class="mt-1"
                                  value="{{ $filters['q'] }}" placeholder="Title or client" />
                </div>

                <div>
                    <x-input-label for="category" value="Category" />
                    <x-select id="category" name="category" class="mt-1">
                        <option value="">All categories</option>
                        <option value="none" @selected($filters['category'] === 'none')>Uncategorised</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected($filters['category'] === (string) $category->id)>
                                {{ $category->name }}
                            </option>
                        @endforeach
                    </x-select>
                </div>

                <div>
                    <x-input-label for="status" value="Status" />
                    <x-select id="status" name="status" class="mt-1">
                        @foreach (['' => 'Any status', 'live' => 'Live on site', 'draft' => 'Draft', 'featured' => 'Featured', 'no-video' => 'No video'] as $value => $label)
                            <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                        @endforeach
                    </x-select>
                </div>

                <div class="flex items-center gap-2">
                    <x-primary-button class="justify-center">Filter</x-primary-button>
                    @if ($isFiltered)
                        <a href="{{ route('portfolio.index') }}"
                           class="inline-flex items-center min-h-[44px] px-3 text-sm font-semibold text-brand-100/60 hover:text-white">Clear</a>
                    @endif
                </div>
            </form>
        </x-card>

        @if ($isFiltered)
            <p class="text-sm text-brand-100/60">
                Showing {{ $items->count() }} of {{ $totals['all'] }} {{ Str::plural('piece', $totals['all']) }}.
            </p>
        @endif

        @if ($missingVideo > 0)
            <div class="rounded-lg border border-amber-400/30 bg-amber-400/10 p-3 text-sm text-amber-200">
                {{ $missingVideo }} {{ Str::plural('piece', $missingVideo) }} {{ $missingVideo === 1 ? 'has' : 'have' }}
                no video file or link yet, so {{ $missingVideo === 1 ? 'it shows' : 'they show' }} as a still only.
            </div>
        @endif

        @if ($items->isEmpty())
            <x-empty-state message="{{ $isFiltered ? 'Nothing matches those filters.' : 'No work published yet.' }}">
                <a href="{{ route('portfolio.create') }}" class="text-brand-500 font-semibold text-sm hover:text-brand-300">
                    Upload your first video &rarr;
                </a>
            </x-empty-state>
        @else
            {{-- Mobile: cards --}}
            <div class="space-y-3 md:hidden">
                @foreach ($items as $item)
                    <x-card class="p-3">
                        <div class="flex gap-3">
                            <div class="w-28 shrink-0 {{ $item->isVertical() ? 'aspect-[9/16]' : 'aspect-video' }} rounded-md bg-white/10 overflow-hidden flex items-center justify-center">
                                @if ($item->thumbnailUrl())
                                    <img src="{{ $item->thumbnailUrl() }}" alt="" class="w-full h-full object-cover">
                                @else
                                    <span class="text-[10px] text-brand-100/50">No still</span>
                                @endif
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-white truncate">{{ $item->title }}</p>
                                <p class="text-xs text-brand-100/60 truncate">
                                    {{ $item->category?->name ?? 'Uncategorised' }}
                                    @if ($item->clientLabel()) &middot; {{ $item->clientLabel() }} @endif
                                </p>
                                <div class="mt-1.5 flex flex-wrap gap-1.5">
                                    <x-badge :status="$item->is_visible ? 'active' : 'inactive'" />
                                    @if ($item->is_featured)
                                        <x-badge status="featured" color="bg-brand-400/20 text-brand-200">Featured</x-badge>
                                    @endif
                                    @if ($item->isUploaded())
                                        <x-badge status="uploaded" color="bg-green-400/15 text-green-200">Uploaded</x-badge>
                                    @elseif ($item->video_url)
                                        <x-badge status="linked" color="bg-blue-400/15 text-blue-200">Linked</x-badge>
                                    @else
                                        <x-badge status="no_video" color="bg-amber-400/15 text-amber-200">No video</x-badge>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="mt-3 pt-3 border-t border-white/10 flex items-center justify-end gap-2">
                            <a href="{{ route('portfolio.edit', $item) }}"
                               class="inline-flex items-center min-h-[44px] px-3 text-xs font-semibold text-brand-500 hover:text-brand-300">Edit</a>
                            <form method="POST" action="{{ route('portfolio.destroy', $item) }}"
                                  onsubmit="return confirm('Delete “{{ $item->title }}”? The uploaded video is deleted too.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="inline-flex items-center min-h-[44px] px-3 text-xs font-semibold text-red-300 hover:text-red-200">Delete</button>
                            </form>
                        </div>
                    </x-card>
                @endforeach
            </div>

            {{-- Desktop: table over the same data --}}
            <x-card class="hidden md:block overflow-hidden">
                <table class="min-w-full divide-y divide-white/10">
                    <thead class="bg-brand-900/40">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-brand-100/60 uppercase tracking-wide">Piece</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-brand-100/60 uppercase tracking-wide">Category</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-brand-100/60 uppercase tracking-wide">Video</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-brand-100/60 uppercase tracking-wide">Status</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-brand-100/60 uppercase tracking-wide">Order</th>
                            <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @foreach ($items as $item)
                            <tr class="hover:bg-white/[0.09]">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="w-24 shrink-0 {{ $item->isVertical() ? 'aspect-[9/16]' : 'aspect-video' }} rounded bg-white/10 overflow-hidden flex items-center justify-center">
                                            @if ($item->thumbnailUrl())
                                                <img src="{{ $item->thumbnailUrl() }}" alt="" class="w-full h-full object-cover">
                                            @else
                                                <span class="text-[10px] text-brand-100/50">No still</span>
                                            @endif
                                        </div>
                                        <div class="min-w-0">
                                            <p class="font-medium text-white truncate">{{ $item->title }}</p>
                                            @if ($item->clientLabel())
                                                <p class="text-xs text-brand-100/60 truncate">
                                                    @if ($item->client)
                                                        <a href="{{ route('clients.show', $item->client) }}" class="text-brand-500 hover:text-brand-300">{{ $item->client->name }}</a>
                                                    @else
                                                        {{ $item->client_name }}
                                                    @endif
                                                </p>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-sm text-brand-100/70">{{ $item->category?->name ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    @if ($item->isUploaded())
                                        <a href="{{ asset($item->video_path) }}" target="_blank" rel="noopener"
                                           class="text-xs font-semibold text-green-200 hover:text-green-200 underline">Uploaded</a>
                                    @elseif ($item->video_url)
                                        <a href="{{ $item->video_url }}" target="_blank" rel="noopener"
                                           class="text-xs font-semibold text-blue-200 hover:text-blue-200 underline">Linked</a>
                                    @else
                                        <span class="text-xs font-semibold text-amber-200">None</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-1.5">
                                        <x-badge :status="$item->is_visible ? 'active' : 'inactive'" />
                                        @if ($item->is_featured)
                                            <x-badge status="featured" color="bg-brand-400/20 text-brand-200">Featured</x-badge>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right text-sm text-brand-100/60">{{ $item->sort_order }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="{{ route('portfolio.edit', $item) }}"
                                           class="inline-flex items-center min-h-[44px] px-2 text-xs font-semibold text-brand-500 hover:text-brand-300">Edit</a>
                                        <form method="POST" action="{{ route('portfolio.destroy', $item) }}"
                                              onsubmit="return confirm('Delete “{{ $item->title }}”? The uploaded video is deleted too.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="inline-flex items-center min-h-[44px] px-2 text-xs font-semibold text-red-300 hover:text-red-200">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>
        @endif
    </div>
</x-app-layout>
