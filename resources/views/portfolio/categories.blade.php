<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Portfolio categories">
            <x-slot name="actions">
                <a href="{{ route('portfolio.index') }}"
                   class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-white/5 border border-white/15 rounded-md font-semibold text-xs text-brand-100/80 uppercase tracking-widest hover:bg-white/[0.09]">
                    Back to portfolio
                </a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="max-w-3xl space-y-4" x-data="{ adding: false }">
        <div class="flex items-start justify-between gap-3">
            <p class="text-sm text-brand-100/60">
                These are the tabs across the top of the public portfolio. Hidden categories,
                and everything filed under them, stay off the website.
            </p>
            <button type="button" @click="adding = ! adding"
                    class="shrink-0 text-sm font-semibold text-brand-500 hover:text-brand-300 min-h-[44px]">
                <span x-show="! adding">+ New</span>
                <span x-show="adding" x-cloak>Cancel</span>
            </button>
        </div>

        <div x-show="adding" x-cloak>
            <x-card class="p-4 sm:p-6">
                <form method="POST" action="{{ route('portfolio-categories.store') }}">
                    @csrf
                    @include('portfolio._category-fields')
                </form>
            </x-card>
        </div>

        @if ($categories->isEmpty())
            <x-empty-state message="No categories yet — every piece shows under one “All” tab.">
                <button type="button" @click="adding = true" class="text-brand-500 font-semibold text-sm hover:text-brand-300">
                    Add the first category &rarr;
                </button>
            </x-empty-state>
        @else
            <x-card class="divide-y divide-white/10">
                @foreach ($categories as $category)
                    <div class="p-3 sm:p-4" x-data="{ editing: false }">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <p class="font-semibold text-white">{{ $category->name }}</p>
                                    <x-badge :status="$category->is_visible ? 'active' : 'inactive'" />
                                </div>
                                <p class="text-xs text-brand-100/60 mt-0.5">
                                    /portfolio?category={{ $category->slug }}
                                    &middot; {{ $category->items_count }} {{ Str::plural('piece', $category->items_count) }}
                                    &middot; order {{ $category->sort_order }}
                                </p>
                            </div>

                            <div class="flex items-center gap-2 shrink-0">
                                <button type="button" @click="editing = ! editing"
                                        class="min-h-[44px] px-2 text-xs font-semibold text-brand-500 hover:text-brand-300">
                                    <span x-show="! editing">Edit</span>
                                    <span x-show="editing" x-cloak>Cancel</span>
                                </button>
                                <form method="POST" action="{{ route('portfolio-categories.destroy', $category) }}"
                                      onsubmit="return confirm('Delete “{{ $category->name }}”? Its videos stay, but become uncategorised.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="min-h-[44px] px-2 text-xs font-semibold text-red-300 hover:text-red-200">Delete</button>
                                </form>
                            </div>
                        </div>

                        <div x-show="editing" x-cloak class="mt-3 pt-3 border-t border-white/10">
                            <form method="POST" action="{{ route('portfolio-categories.update', $category) }}">
                                @csrf
                                @method('PUT')
                                @include('portfolio._category-fields', ['category' => $category])
                            </form>
                        </div>
                    </div>
                @endforeach
            </x-card>
        @endif
    </div>
</x-app-layout>
