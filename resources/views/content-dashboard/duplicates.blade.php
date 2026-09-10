<x-app-layout title="Possible duplicates">
    <x-slot name="header">
        <x-page-header title="Possible duplicates" eyebrow="Content Dashboard"
                       subtitle="Same title, source and venture, more than one row — almost always a page duplicated in Notion, or a fresh page created for a reschedule instead of editing the original's date in place.">
            <x-slot name="actions">
                <x-btn :href="route('content-dashboard.index')" variant="secondary">Content Dashboard</x-btn>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-5">
        <div class="rounded-lg bg-white/[0.03] ring-1 ring-white/10 px-4 py-3 text-sm text-brand-100/70">
            This can only be fixed in Notion — cancel or delete the extra page there. Once a duplicate no longer
            appears in Notion, the next sync flags it here automatically rather than leaving a ghost row behind.
        </div>

        @if ($groups->isEmpty())
            <x-card padding="lg">
                <x-empty-state message="No duplicates found — everything is one row per Notion page." />
            </x-card>
        @else
            @foreach ($groups as $key => $items)
                @php($ventures = $items->pluck('venture')->unique()->filter())
                <x-card class="p-4 sm:p-5">
                    <p class="font-semibold text-white mb-3">
                        {{ $items->first()->title ?: '(untitled)' }}
                        <span class="text-xs font-normal text-brand-100/50">· {{ $items->first()->sourceLabel() }}</span>
                        @if ($ventures->count() > 1)
                            <span class="text-xs font-normal text-amber-300">· ventures don't match: {{ $ventures->implode(', ') }}</span>
                        @endif
                    </p>

                    <div class="divide-y divide-white/5">
                        @foreach ($items as $item)
                            <div class="py-2 flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    @if ($item->notion_url)
                                        <a href="{{ $item->notion_url }}" target="_blank" rel="noopener"
                                           class="text-sm text-white hover:text-brand-300">Open in Notion ↗</a>
                                    @else
                                        <span class="text-sm text-white">No Notion link on record</span>
                                    @endif
                                    <p class="text-[11px] text-brand-100/40">
                                        Venture: {{ $item->venture ?: 'none set' }}
                                        · Created {{ $item->created_at->format('j M Y, g:ia') }}
                                        @if ($item->published_date) · published {{ $item->published_date->format('j M') }} @endif
                                        @if ($item->notionShoot) · linked to {{ $item->notionShoot->title }} @endif
                                    </p>
                                </div>
                                <x-badge :status="$item->status" />
                            </div>
                        @endforeach
                    </div>
                </x-card>
            @endforeach
        @endif
    </div>
</x-app-layout>
