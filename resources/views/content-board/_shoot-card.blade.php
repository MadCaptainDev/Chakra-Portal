@php
    $team = $item->teamMembers();
    $shown = array_slice($team, 0, 3);
    $overflow = count($team) - count($shown);
@endphp

<x-card padding="sm" class="hover:ring-gray-900/10 transition-shadow">
    @if ($item->notion_url)
        <a href="{{ $item->notion_url }}" target="_blank" rel="noopener"
           class="text-sm font-semibold text-gray-900 hover:text-brand-600 line-clamp-2">
            {{ $item->title ?: '(untitled)' }}
        </a>
    @else
        <p class="text-sm font-semibold text-gray-900 line-clamp-2">{{ $item->title ?: '(untitled)' }}</p>
    @endif

    @if ($item->clientLabel())
        <p class="mt-1 text-xs text-gray-500 truncate">{{ $item->clientLabel() }}</p>
    @endif

    <div class="mt-2 space-y-1 text-[11px] text-gray-500">
        @if ($item->shoot_date)
            <p class="flex items-center gap-1">
                <x-icon name="calendar" class="w-3 h-3 shrink-0" />
                {{ $item->shoot_date->format('j M Y') }}
                @if (! $item->isPast())
                    <span class="text-brand-600 font-medium">({{ $item->shoot_date->diffForHumans() }})</span>
                @endif
            </p>
        @endif
        @if ($item->location)
            <p class="flex items-center gap-1 truncate">
                <x-icon name="map-pin" class="w-3 h-3 shrink-0" />
                <span class="truncate">{{ $item->location }}</span>
            </p>
        @endif
    </div>

    {{--
        photo_url is intentionally never rendered as an <img> here. Notion's
        internal ('file') URLs are S3 pre-signed and expire in about an hour
        -- an image tag would just be broken for anyone loading the board
        later than that. The value is still stored/synced in case a future
        need re-fetches it fresh from Notion at render time instead.
    --}}

    @if ($shown !== [])
        <div class="mt-2.5 flex flex-wrap items-center gap-1">
            @foreach ($shown as $name)
                <x-avatar :name="$name" size="sm" />
            @endforeach
            @if ($overflow > 0)
                <span class="text-[11px] text-gray-400 font-medium">+{{ $overflow }}</span>
            @endif
        </div>
    @endif

    @if ($item->duration || $item->video_count)
        <p class="mt-2 pt-2 border-t border-gray-100 text-[11px] text-gray-400">
            @if ($item->duration) {{ rtrim(rtrim((string) $item->duration, '0'), '.') }}h @endif
            @if ($item->duration && $item->video_count) · @endif
            @if ($item->video_count) {{ $item->video_count }} video(s) @endif
        </p>
    @endif
</x-card>
