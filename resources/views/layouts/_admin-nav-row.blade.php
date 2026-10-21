{{-- One admin sidebar row, with its pin star (layouts._admin-nav). --}}
<div class="group/row relative">
    <x-sidebar-link :icon="$child ? null : $item['icon']" :href="$item['href']" :active="$item['active']" class="{{ $child ? '!min-h-[36px] !py-1.5 text-[13px]' : '' }} pr-9">
        <span class="min-w-0 flex-1 truncate">{{ $item['label'] }}</span>
        @if ($item['badge'] > 0)
            <span class="shrink-0 inline-flex items-center justify-center min-w-[22px] h-[22px] px-1.5 rounded-full bg-brand-400 text-brand-900 text-[11px] font-bold">{{ $item['badge'] > 99 ? '99+' : $item['badge'] }}</span>
        @endif
    </x-sidebar-link>
    @unless ($child)
        <button type="button" @click.prevent="toggleFav(@js($item['key']))"
                class="absolute right-1.5 top-1/2 -translate-y-1/2 flex h-7 w-7 items-center justify-center rounded-md text-sm transition {{ $pinned ? 'text-amber-300' : 'text-brand-100/40 opacity-0 group-hover/row:opacity-100 focus:opacity-100 hover:text-amber-300' }}"
                aria-label="{{ $pinned ? 'Unpin' : 'Pin' }} {{ $item['label'] }}" title="{{ $pinned ? 'Remove from Favourites' : 'Add to Favourites' }}">{{ $pinned ? '★' : '☆' }}</button>
    @endunless
</div>
