@props([
    'items' => [],
    'maxMinutes' => 1,
    'title' => 'Breakdown',
    'limit' => 8,
    'empty' => 'Nothing to show.',
    'linkable' => null,
])

@php
    $maxMinutes = max(1, (int) $maxMinutes);
    $all = collect($items)->values();
    $hidden = max(0, $all->count() - (int) $limit);
    // Admin charts can deep-link into the client page; employees cannot.
    $allowLinks = $linkable ?? (auth()->user()?->isAdmin() ?? false);
@endphp

<div {{ $attributes->merge(['class' => 'bg-white shadow-sm rounded-lg border border-brand-100/60 p-4 sm:p-5']) }}
     @if ($hidden > 0) x-data="{ expanded: false }" @endif>
    <h3 class="text-sm font-semibold text-brand-900 mb-3">{{ $title }}</h3>

    @if ($all->isEmpty() || $all->sum('minutes') <= 0)
        <p class="text-sm text-gray-500 py-6 text-center">{{ $empty }}</p>
    @else
        <ul class="space-y-2.5">
            @foreach ($all as $index => $item)
                @php
                    $pct = $item['minutes'] > 0 ? max(2, (int) round(($item['minutes'] / $maxMinutes) * 100)) : 0;
                    $href = $allowLinks ? ($item['href'] ?? null) : null;
                    $collapsed = $hidden > 0 && $index >= $limit;
                @endphp
                <li @if ($collapsed) x-show="expanded" x-cloak x-transition.opacity.duration.150ms @endif>
                    @if ($href)
                        <a href="{{ $href }}" class="block group rounded-md -mx-1 px-1 py-0.5 hover:bg-brand-50/80 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400">
                            <div class="flex items-center justify-between gap-2 mb-1">
                                <span class="text-xs sm:text-sm text-brand-800 group-hover:text-brand-600 truncate min-w-0 font-medium">{{ $item['label'] }}</span>
                                <span class="text-xs text-gray-500 shrink-0 tabular-nums">{{ \App\Models\TimesheetEntry::formatMinutes($item['minutes']) }}</span>
                            </div>
                            <div class="h-2 rounded-full bg-brand-100 overflow-hidden">
                                <div class="h-full rounded-full bg-brand-500" style="width: {{ $pct }}%"></div>
                            </div>
                        </a>
                    @else
                        <div class="flex items-center justify-between gap-2 mb-1">
                            <span class="text-xs sm:text-sm text-gray-800 truncate min-w-0">{{ $item['label'] }}</span>
                            <span class="text-xs text-gray-500 shrink-0 tabular-nums">{{ \App\Models\TimesheetEntry::formatMinutes($item['minutes']) }}</span>
                        </div>
                        <div class="h-2 rounded-full bg-brand-100 overflow-hidden">
                            <div class="h-full rounded-full bg-brand-500" style="width: {{ $pct }}%"></div>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
        @if ($hidden > 0)
            <button type="button"
                    class="mt-1 inline-flex items-center min-h-[44px] min-w-[44px] text-[11px] text-gray-400 hover:text-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 rounded-md px-1 -mx-1"
                    @click="expanded = ! expanded"
                    :aria-expanded="expanded.toString()">
                <span x-show="! expanded">+{{ $hidden }} more</span>
                <span x-show="expanded" x-cloak>Show less</span>
            </button>
        @endif
    @endif
</div>
