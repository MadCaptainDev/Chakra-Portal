@php
    /*
     * A read-only mirror of Notion. Two tabs (Reels, Shoots), rendered from
     * content_items / notion_shoots -- both already synced by
     * ContentSyncService's own read-only calls, nothing here reads Notion
     * directly.
     *
     * Deliberately NOT drag-and-drop: a card that looked movable but did
     * nothing on drop would read as a bug, so the page says plainly up
     * front that it doesn't work that way.
     */
@endphp

<x-app-layout title="Content Board">
    <x-slot name="header">
        <x-page-header
            title="Content Board"
            subtitle="Reel planner and shoot schedule, as they stand in Notion." />
    </x-slot>

    <div x-data="{ tab: 'reel' }" class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <x-tab-nav :tabs="[
                    'reel' => ['label' => 'Reels', 'count' => $reelCount],
                    'shoot' => ['label' => 'Shoots', 'count' => $shootCount],
                ]" model="tab" />

            <p class="text-xs text-gray-500">
                Last synced {{ $lastSynced?->diffForHumans() ?? 'never' }} ·
                read-only mirror of Notion — changes are made there and appear here after the next sync.
            </p>
        </div>

        @if ($reelCount === 0 && $shootCount === 0)
            <x-card padding="md">
                <x-empty-state message="Nothing synced from Notion yet.">
                    <a href="{{ route('notion.edit') }}"
                       class="text-xs font-semibold uppercase tracking-widest text-brand-600 hover:text-brand-800">
                        Set up the Notion integration →
                    </a>
                </x-empty-state>
            </x-card>
        @else
            <div x-show="tab === 'reel'" x-cloak>
                @if ($reelCount === 0)
                    <x-card padding="md">
                        <x-empty-state message="No reel content synced from Notion yet." />
                    </x-card>
                @else
                    <div class="flex gap-4 overflow-x-auto pb-4">
                        @foreach ($reelColumns as $column)
                            @include('content-board._column', ['column' => $column, 'card' => 'content-board._reel-card'])
                        @endforeach
                    </div>
                @endif
            </div>

            <div x-show="tab === 'shoot'" x-cloak>
                @if ($shootCount === 0)
                    <x-card padding="md">
                        <x-empty-state message="No shoots synced from Notion yet." />
                    </x-card>
                @else
                    <div class="flex gap-4 overflow-x-auto pb-4">
                        @foreach ($shootColumns as $column)
                            @include('content-board._column', ['column' => $column, 'card' => 'content-board._shoot-card'])
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    </div>
</x-app-layout>
