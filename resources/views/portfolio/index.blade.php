@php
    $visibleCount = $items->where('is_visible', true)->count();
    $featuredCount = $items->where('is_featured', true)->count();
    $missingVideo = $items->filter(fn ($item) => ! $item->playbackUrl())->count();
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Portfolio">
            <x-slot name="actions">
                <a href="{{ route('portfolio-categories.index') }}"
                   class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50">
                    Categories
                </a>
                <a href="{{ route('portfolio') }}" target="_blank" rel="noopener"
                   class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50">
                    View page
                </a>
                <a href="{{ route('portfolio.create') }}"
                   class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-brand-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-brand-500">
                    + Add video
                </a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-4">
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <x-stat-card label="Pieces" value="{{ $items->count() }}" accent="gray" />
            <x-stat-card label="Live on site" value="{{ $visibleCount }}" accent="green" />
            <x-stat-card label="Featured" value="{{ $featuredCount }}" accent="brand">
                Lead the landing page
            </x-stat-card>
            <x-stat-card label="Categories" value="{{ $categories->count() }}" accent="gray" />
        </div>

        @if ($missingVideo > 0)
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                {{ $missingVideo }} {{ Str::plural('piece', $missingVideo) }} {{ $missingVideo === 1 ? 'has' : 'have' }}
                no video file or link yet, so {{ $missingVideo === 1 ? 'it shows' : 'they show' }} as a still only.
            </div>
        @endif

        @if ($items->isEmpty())
            <x-empty-state message="No work published yet.">
                <a href="{{ route('portfolio.create') }}" class="text-brand-500 font-semibold text-sm hover:text-brand-600">
                    Upload your first video &rarr;
                </a>
            </x-empty-state>
        @else
            {{-- Mobile: cards --}}
            <div class="space-y-3 md:hidden">
                @foreach ($items as $item)
                    <x-card class="p-3">
                        <div class="flex gap-3">
                            <div class="w-28 shrink-0 aspect-video rounded-md bg-gray-100 overflow-hidden flex items-center justify-center">
                                @if ($item->thumbnailUrl())
                                    <img src="{{ $item->thumbnailUrl() }}" alt="" class="w-full h-full object-cover">
                                @else
                                    <span class="text-[10px] text-gray-400">No still</span>
                                @endif
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-gray-900 truncate">{{ $item->title }}</p>
                                <p class="text-xs text-gray-500 truncate">
                                    {{ $item->category?->name ?? 'Uncategorised' }}
                                    @if ($item->client_name) &middot; {{ $item->client_name }} @endif
                                </p>
                                <div class="mt-1.5 flex flex-wrap gap-1.5">
                                    <x-badge :status="$item->is_visible ? 'active' : 'inactive'" />
                                    @if ($item->is_featured)
                                        <x-badge status="featured" color="bg-brand-100 text-brand-800">Featured</x-badge>
                                    @endif
                                    @if ($item->isUploaded())
                                        <x-badge status="uploaded" color="bg-green-100 text-green-800">Uploaded</x-badge>
                                    @elseif ($item->video_url)
                                        <x-badge status="linked" color="bg-blue-100 text-blue-800">Linked</x-badge>
                                    @else
                                        <x-badge status="no_video" color="bg-amber-100 text-amber-800">No video</x-badge>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="mt-3 pt-3 border-t border-gray-100 flex items-center justify-end gap-2">
                            <a href="{{ route('portfolio.edit', $item) }}"
                               class="inline-flex items-center min-h-[44px] px-3 text-xs font-semibold text-brand-500 hover:text-brand-600">Edit</a>
                            <form method="POST" action="{{ route('portfolio.destroy', $item) }}"
                                  onsubmit="return confirm('Delete “{{ $item->title }}”? The uploaded video is deleted too.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="inline-flex items-center min-h-[44px] px-3 text-xs font-semibold text-red-600 hover:text-red-800">Delete</button>
                            </form>
                        </div>
                    </x-card>
                @endforeach
            </div>

            {{-- Desktop: table over the same data --}}
            <x-card class="hidden md:block overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Piece</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Category</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Video</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Status</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide">Order</th>
                            <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($items as $item)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="w-24 shrink-0 aspect-video rounded bg-gray-100 overflow-hidden flex items-center justify-center">
                                            @if ($item->thumbnailUrl())
                                                <img src="{{ $item->thumbnailUrl() }}" alt="" class="w-full h-full object-cover">
                                            @else
                                                <span class="text-[10px] text-gray-400">No still</span>
                                            @endif
                                        </div>
                                        <div class="min-w-0">
                                            <p class="font-medium text-gray-900 truncate">{{ $item->title }}</p>
                                            @if ($item->client_name)
                                                <p class="text-xs text-gray-500 truncate">{{ $item->client_name }}</p>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-600">{{ $item->category?->name ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    @if ($item->isUploaded())
                                        <a href="{{ asset($item->video_path) }}" target="_blank" rel="noopener"
                                           class="text-xs font-semibold text-green-700 hover:text-green-900 underline">Uploaded</a>
                                    @elseif ($item->video_url)
                                        <a href="{{ $item->video_url }}" target="_blank" rel="noopener"
                                           class="text-xs font-semibold text-blue-700 hover:text-blue-900 underline">Linked</a>
                                    @else
                                        <span class="text-xs font-semibold text-amber-700">None</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-1.5">
                                        <x-badge :status="$item->is_visible ? 'active' : 'inactive'" />
                                        @if ($item->is_featured)
                                            <x-badge status="featured" color="bg-brand-100 text-brand-800">Featured</x-badge>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right text-sm text-gray-500">{{ $item->sort_order }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="{{ route('portfolio.edit', $item) }}"
                                           class="inline-flex items-center min-h-[44px] px-2 text-xs font-semibold text-brand-500 hover:text-brand-600">Edit</a>
                                        <form method="POST" action="{{ route('portfolio.destroy', $item) }}"
                                              onsubmit="return confirm('Delete “{{ $item->title }}”? The uploaded video is deleted too.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="inline-flex items-center min-h-[44px] px-2 text-xs font-semibold text-red-600 hover:text-red-800">Delete</button>
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
