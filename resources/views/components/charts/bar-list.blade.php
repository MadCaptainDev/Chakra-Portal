@props([
    'items' => [],
    'limit' => 8,
    'empty' => 'Nothing to show.',
    'decimals' => 0,
])

@php
    // Generic money sibling of charts/horizontal-bars, which formats minutes and
    // is therefore timesheet-only. A ranked bar list beats the doughnut it
    // replaced: lengths off a shared baseline are actually comparable, and it
    // costs no JavaScript.
    $all = collect($items)->filter(fn ($i) => (float) ($i['value'] ?? 0) > 0)->sortByDesc('value')->values();
    $rows = $all->take($limit);
    $hidden = max(0, $all->count() - $rows->count());
    $max = max(1, (float) $all->max('value'));
@endphp

@if ($rows->isEmpty())
    <p class="text-sm text-gray-400 py-6 text-center">{{ $empty }}</p>
@else
    <ul {{ $attributes->merge(['class' => 'space-y-3']) }}>
        @foreach ($rows as $item)
            @php
                $value = (float) $item['value'];
                // A 2% floor keeps a real-but-tiny amount visible instead of
                // rendering as nothing at all.
                $pct = max(2, (int) round(($value / $max) * 100));
                $colour = $item['color'] ?? '#4FA9C4';
                $href = $item['href'] ?? null;
            @endphp
            <li>
                @if ($href)
                    <a href="{{ $href }}" class="block group rounded-md -mx-1 px-1 py-0.5 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400">
                @endif

                <div class="flex items-baseline justify-between gap-3 mb-1.5">
                    <span class="text-sm text-gray-700 truncate min-w-0 {{ $href ? 'group-hover:text-brand-700 font-medium' : '' }}">
                        {{ $item['label'] }}
                    </span>
                    <span class="text-sm font-semibold text-gray-900 shrink-0 tabular-nums">
                        {{ number_format($value, $decimals) }}
                    </span>
                </div>

                <div class="h-2 rounded-full bg-gray-100 overflow-hidden">
                    {{-- Inline style, not a Tailwind class: JIT only extracts
                         literal class strings, so a computed w-[x%] never exists. --}}
                    <div class="h-full rounded-full transition-all duration-500"
                         style="width: {{ $pct }}%; background-color: {{ $colour }}"></div>
                </div>

                @if (! empty($item['hint']))
                    <p class="mt-1 text-[11px] text-gray-400">{{ $item['hint'] }}</p>
                @endif

                @if ($href)
                    </a>
                @endif
            </li>
        @endforeach
    </ul>

    @if ($hidden > 0)
        <p class="mt-3 text-[11px] text-gray-400">+{{ $hidden }} more</p>
    @endif
@endif
