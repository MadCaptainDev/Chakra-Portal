@php
    // Multi_select editors arrive comma-joined; the chip shows just the
    // first, same as ContentItem::editorInitials() already does.
    $firstEditor = $item->editor ? trim(explode(',', $item->editor)[0]) : null;
@endphp

<x-card padding="sm" class="hover:ring-gray-900/10 transition-shadow">
    <div class="flex items-start gap-2.5">
        @if ($firstEditor)
            <x-avatar :name="$firstEditor" size="sm" class="mt-0.5" />
        @endif
        <div class="min-w-0 flex-1">
            @if ($item->notion_url)
                <a href="{{ $item->notion_url }}" target="_blank" rel="noopener"
                   class="text-sm font-semibold text-gray-900 hover:text-brand-600 line-clamp-2">
                    {{ $item->title ?: '(untitled)' }}
                </a>
            @else
                <p class="text-sm font-semibold text-gray-900 line-clamp-2">{{ $item->title ?: '(untitled)' }}</p>
            @endif

            @if ($item->venture)
                <p class="mt-1 text-xs text-gray-500 truncate">{{ $item->venture }}</p>
            @endif

            <div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-gray-400">
                @if ($item->tier)
                    <span>{{ $item->tier }}</span>
                @endif
                @if ($item->published_date)
                    <span>{{ $item->published_date->format('j M') }}</span>
                @endif
            </div>
        </div>
    </div>
</x-card>
