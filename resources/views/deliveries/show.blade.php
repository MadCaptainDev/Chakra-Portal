<x-app-layout title="{{ $client->name }} — Deliveries">
    <x-slot name="header">
        <x-page-header :title="$client->name"
                       :subtitle="'Occasion client'.($client->service_note ? ' — '.$client->service_note : '')"
                       eyebrow="Deliveries">
            <x-slot name="actions">
                <x-btn :href="route('deliveries.index')" variant="secondary">All occasion clients</x-btn>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-5">
        <x-card padding="none" class="overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] font-semibold uppercase tracking-wider text-brand-100/60 border-b border-white/10">
                            <th class="px-4 sm:px-5 py-2.5">Piece</th>
                            <th class="px-3 py-2.5">Status</th>
                            <th class="px-3 py-2.5">Type</th>
                            <th class="px-3 py-2.5">Shot</th>
                            <th class="px-3 py-2.5">Script</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @forelse ($items as $item)
                            <tr>
                                <td class="px-4 sm:px-5 py-2.5">
                                    @if ($item->notion_url)
                                        <a href="{{ $item->notion_url }}" target="_blank" rel="noopener"
                                           class="text-white hover:text-brand-300 font-medium truncate block max-w-sm">
                                            {{ $item->title ?: '(untitled)' }}
                                        </a>
                                    @else
                                        <span class="text-white truncate block max-w-sm">{{ $item->title ?: '(untitled)' }}</span>
                                    @endif
                                    @if ($item->notionShoot)
                                        <span class="text-[11px] text-brand-100/40 block">{{ $item->linkedShootLabel() }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2.5 whitespace-nowrap">
                                    @if ($item->status === $readyStatus)
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-400/15 text-amber-200">Ready to deliver</span>
                                    @else
                                        <x-badge :status="$item->status" />
                                    @endif
                                </td>
                                <td class="px-3 py-2.5 whitespace-nowrap text-brand-100/70">{{ $item->sourceLabel() }}</td>
                                <td class="px-3 py-2.5 whitespace-nowrap text-brand-100/70">{{ $item->shoot_date?->format('j M') ?? '—' }}</td>
                                <td class="px-3 py-2.5 whitespace-nowrap">
                                    @if ($item->scriptRecord)
                                        <a href="{{ route('scripts.show', $item->scriptRecord) }}" class="text-brand-300 hover:text-white">
                                            <x-badge :status="$item->scriptRecord->status" />
                                        </a>
                                    @elseif ($item->source === \App\Models\ContentItem::SOURCE_REEL)
                                        <a href="{{ route('scripts.create', ['content_item_id' => $item->id]) }}" class="text-xs font-semibold text-brand-300 hover:text-white">+ Write script</a>
                                    @else
                                        <span class="text-brand-100/30">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5">
                                    <x-empty-state message="Nothing here yet." />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>
    </div>
</x-app-layout>
