<x-app-layout title="Equipment">
    <x-slot name="header">
        <x-page-header title="Equipment" eyebrow="Production"
                       subtitle="What the studio owns, and what is currently out.">
            @can('equipment.create')
                <x-slot name="actions">
                    <x-btn type="button" icon="plus" @click="$dispatch('open-modal', 'add-equipment')">Add item</x-btn>
                </x-slot>
            @endcan
        </x-page-header>
    </x-slot>

    <div class="space-y-4">
        <div class="grid grid-cols-2 lg:grid-cols-3 gap-3 sm:gap-4">
            <x-stat-card label="Items owned" :value="$total" accent="brand" icon="briefcase" />
            <x-stat-card label="Categories" :value="$groups->count()" accent="gray" icon="template" />
            <x-stat-card label="Unaccounted for" :value="$missing"
                         :accent="$missing > 0 ? 'red' : 'green'" icon="alert"
                         class="col-span-2 lg:col-span-1">
                {{ $missing > 0 ? 'Went out and never came back' : 'Everything is accounted for' }}
            </x-stat-card>
        </div>

        <x-card class="p-4">
            <form method="GET" action="{{ route('equipment.index') }}" class="flex flex-wrap items-end gap-3">
                <div class="flex-1 min-w-[200px]">
                    <x-input-label for="q" value="Search" />
                    <x-text-input id="q" name="q" type="search" class="mt-1" :value="$filters['q']"
                                  placeholder="Name or asset tag" />
                </div>

                <label for="retired" class="inline-flex items-center gap-2 min-h-[44px] text-sm text-gray-700 cursor-pointer">
                    <input id="retired" name="retired" type="checkbox" value="1" @checked($filters['retired'])
                           class="rounded border-gray-300 text-brand-500 focus:ring-brand-400">
                    Show retired
                </label>

                <x-btn type="submit" size="sm">Apply</x-btn>
            </form>
        </x-card>

        @if ($groups->isEmpty())
            <x-empty-state message="Nothing in the register yet.">
                @can('equipment.create')
                    <x-btn type="button" size="sm" @click="$dispatch('open-modal', 'add-equipment')">Add the first item</x-btn>
                @endcan
            </x-empty-state>
        @else
            @foreach ($groups as $category => $items)
                <section>
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-gray-400 mb-2">{{ $category }}</p>

                    <x-card class="divide-y divide-gray-100 overflow-hidden">
                        @foreach ($items as $item)
                            @php $short = (int) ($shortfalls[$item->id] ?? 0); @endphp

                            <div class="p-4 flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-semibold text-gray-900 truncate">
                                        {{ $item->name }}
                                        @unless ($item->is_active)
                                            <span class="ml-1 text-xs font-normal text-gray-400">(retired)</span>
                                        @endunless
                                    </p>
                                    <p class="text-xs text-gray-500 mt-0.5">
                                        {{ $item->quantity }} owned
                                        @if ($item->identifier) &middot; {{ $item->identifier }} @endif
                                    </p>
                                    @if ($short > 0)
                                        <p class="text-xs text-red-600 font-semibold mt-1">
                                            {{ $short }} never came back
                                        </p>
                                    @endif
                                </div>

                                @can('equipment.edit')
                                    <div class="shrink-0 flex items-center gap-2">
                                        <button type="button"
                                                @click="$dispatch('open-modal', 'edit-equipment-{{ $item->id }}')"
                                                class="min-h-[44px] px-3 text-xs font-semibold text-brand-600 hover:text-brand-700">
                                            Edit
                                        </button>
                                    </div>
                                @endcan
                            </div>

                            @can('equipment.edit')
                                <x-modal :name="'edit-equipment-'.$item->id" :show="false" maxWidth="lg">
                                    <form method="POST" action="{{ route('equipment.update', $item) }}" class="p-6">
                                        @csrf
                                        @method('PUT')
                                        <h2 class="text-lg font-semibold text-gray-900 mb-4">Edit {{ $item->name }}</h2>
                                        @include('equipment._fields', ['item' => $item, 'categories' => $categories])

                                        <div class="mt-6 flex items-center justify-between gap-3">
                                            <x-btn type="submit">Save</x-btn>

                                            @can('equipment.delete')
                                                <button type="submit" form="delete-equipment-{{ $item->id }}"
                                                        class="text-sm font-semibold text-red-600 hover:text-red-700">
                                                    Delete
                                                </button>
                                            @endcan
                                        </div>
                                    </form>
                                </x-modal>

                                @can('equipment.delete')
                                    <form id="delete-equipment-{{ $item->id }}" method="POST"
                                          action="{{ route('equipment.destroy', $item) }}" class="hidden">
                                        @csrf
                                        @method('DELETE')
                                    </form>
                                @endcan
                            @endcan
                        @endforeach
                    </x-card>
                </section>
            @endforeach
        @endif
    </div>

    @can('equipment.create')
        <x-modal name="add-equipment" :show="$errors->any()" maxWidth="lg">
            <form method="POST" action="{{ route('equipment.store') }}" class="p-6">
                @csrf
                <h2 class="text-lg font-semibold text-gray-900 mb-1">Add to the register</h2>
                <p class="text-sm text-gray-500 mb-4">
                    One entry per kind of thing. Twelve identical batteries are one entry with a quantity of twelve.
                </p>

                @include('equipment._fields', ['item' => null, 'categories' => $categories])

                <div class="mt-6 flex items-center gap-3">
                    <x-btn type="submit">Add item</x-btn>
                    <button type="button" @click="$dispatch('close-modal', 'add-equipment')"
                            class="text-sm text-gray-600 hover:text-gray-900 min-h-[44px] px-2">Cancel</button>
                </div>
            </form>
        </x-modal>
    @endcan
</x-app-layout>
