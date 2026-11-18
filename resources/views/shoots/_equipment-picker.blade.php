@php
    /*
    | Everything the old plain <select> made someone squint at, computed once
    | here instead of once per option: free count, what's promised elsewhere,
    | and condition -- grouped by category so "what's left in Camera" is a
    | glance, not a scroll through an alphabetical list of forty things.
    | $available, $committed, $shortfalls, $alreadyOn come from shoots.show's
    | own scope (this partial is @include'd, not a component).
    */
    $pickerItems = $available
        ->reject(fn ($item) => in_array($item->id, $alreadyOn, true))
        ->map(function ($item) use ($committed, $shortfalls, $available, $alreadyOn) {
            $short = (int) ($shortfalls[$item->id] ?? 0);
            $stock = max(0, $item->quantity - $short);
            $taken = (int) ($committed[$item->id]->committed ?? 0);
            $free = max(0, $stock - $taken);

            // Accessories that always travel with this item -- see
            // EquipmentItem::accessories(). Already-on-the-shoot ones are
            // dropped the same way the top-level list is: nothing to
            // auto-add if it's already sitting on the kit.
            $accessoryIds = $item->bundle->pluck('id')
                ->reject(fn ($id) => in_array($id, $alreadyOn, true))
                ->values();

            $pairedWithName = $item->bundledWith->pluck('name')->implode(', ') ?: null;

            return [
                'id' => $item->id,
                'name' => $item->name,
                'identifier' => $item->identifier,
                'category' => $item->categoryLabel(),
                'total' => $item->quantity,
                'free' => $free,
                'available' => $item->isAvailable(),
                'statusLabel' => $item->isAvailable() ? null : $item->statusLabel(),
                'accessoryIds' => $accessoryIds,
                'pairedWithName' => $pairedWithName,
                'image' => $item->thumbUrl(),
                'brings' => $item->bundle->pluck('name')->implode(', '),
                'order' => $item->category?->sort_order ?? 99,
            ];
        })
        ->values();

    $pickerGroups = $pickerItems->groupBy('category')->sortKeys();
@endphp

<div x-data="{ open: false }">
    <div class="flex flex-wrap items-center gap-2">
        <x-btn type="button" size="sm" @click="open = true">
            <x-icon name="plus" class="w-4 h-4" />
            Add equipment
        </x-btn>

        @if (($usualKit ?? collect())->isNotEmpty())
            {{-- What this client's last shoots actually took -- see KitSuggestion. --}}
            <form method="POST" action="{{ route('shoots.kit.usual', $shoot) }}">
                @csrf
                <x-btn type="submit" size="sm" variant="secondary">
                    Add usual kit for {{ $shoot->clientLabel() ?? 'this client' }} ({{ $usualKit->count() }})
                </x-btn>
            </form>
        @endif
    </div>
    @if (($usualKit ?? collect())->isNotEmpty())
        <p class="mt-1.5 text-xs text-brand-100/50">
            Usually: {{ $usualKit->map(fn ($row) => $row['item']->name)->take(8)->implode(', ') }}@if ($usualKit->count() > 8) and {{ $usualKit->count() - 8 }} more @endif
        </p>
    @endif
    @if ($shoot->notionShoot?->gear_needed)
        <p class="mt-1 text-xs text-brand-100/40">Notion says: {{ $shoot->notionShoot->gear_needed }}</p>
    @endif

    {{-- The photo grid in a sheet: tap pictures, then Add. --}}
    <div x-show="open" x-cloak x-transition.opacity class="fixed inset-0 z-[70] flex items-end justify-center sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-label="Add equipment">
        <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm" @click="open = false"></div>
        <div @keydown.escape.window="open = false"
             class="relative flex h-[90vh] w-full flex-col overflow-hidden rounded-t-2xl bg-brand-800 px-4 pb-4 pt-3 shadow-2xl ring-1 ring-white/10 sm:h-[85vh] sm:max-w-3xl sm:rounded-xl sm:px-5">
            <div class="flex shrink-0 items-center justify-between pb-2">
                <p class="text-sm font-semibold text-white">Add equipment</p>
                <button type="button" @click="open = false" class="flex h-9 w-9 items-center justify-center rounded-lg text-brand-100/60 hover:bg-white/10 hover:text-white" aria-label="Close">✕</button>
            </div>
            @include('shoots._kit-grid', ['gridItems' => $pickerItems, 'action' => route('shoots.kit.store-many', $shoot), 'style' => 'rows'])
        </div>
    </div>
</div>
