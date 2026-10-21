<x-app-layout title="Announcements">
    <x-slot name="header">
        <x-page-header title="Announcements" eyebrow="Team"
                       subtitle="Active announcements appear on every employee's dashboard. Reels crossing 10K, 25K, 50K and 100K views are announced on their own." />
    </x-slot>

    <div class="max-w-3xl space-y-4" x-data="{ adding: false }">
        <div class="flex justify-end">
            <x-btn type="button" variant="secondary" @click="adding = ! adding">
                <span x-show="! adding" class="inline-flex items-center gap-1.5">
                    <x-icon name="plus" class="w-4 h-4" /> New announcement
                </span>
                <span x-show="adding" x-cloak>Cancel</span>
            </x-btn>
        </div>

        <div x-show="adding" x-cloak>
            <x-card padding="md">
                @include('announcements._form')
            </x-card>
        </div>

        @if ($announcements->isEmpty())
            <x-empty-state message="Nothing posted yet.">
                <button type="button" @click="adding = true" class="text-brand-500 font-semibold text-sm hover:text-brand-300">Write the first one &rarr;</button>
            </x-empty-state>
        @else
            @foreach ($announcements as $announcement)
                <x-card class="p-4 sm:p-6 {{ $announcement->isMilestone() ? 'ring-1 ring-amber-300/25 bg-gradient-to-br from-amber-300/10 to-transparent' : '' }}" x-data="{ editing: false }">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <p class="font-semibold text-white">{{ $announcement->title }}</p>
                                @if ($announcement->hasEnded())
                                    <x-badge color="bg-white/10 text-brand-100/60">Ended</x-badge>
                                @else
                                    <x-badge :status="$announcement->is_active ? 'active' : 'inactive'" />
                                @endif
                                @if ($announcement->isMilestone())
                                    <x-badge color="bg-amber-300/15 text-amber-200">Views milestone</x-badge>
                                @endif
                                @if ($announcement->visible_to_clients)
                                    <x-badge color="bg-brand-400/15 text-brand-200">Visible to clients</x-badge>
                                @endif
                            </div>
                            <p class="text-[11px] text-brand-100/60 mt-0.5">
                                {{ $announcement->author?->name ?? ($announcement->isMilestone() ? 'Posted by the portal' : 'Unknown') }} &middot; {{ $announcement->created_at->diffForHumans() }}
                                @if ($announcement->expires_at && ! $announcement->hasEnded())
                                    &middot; shows until {{ $announcement->expires_at->format('j M') }}
                                @endif
                            </p>
                        </div>
                    </div>

                    <p class="text-sm text-brand-100/80 mt-3 whitespace-pre-line">{{ $announcement->body }}</p>
                    @if ($announcement->link_url)
                        <a href="{{ $announcement->link_url }}" target="_blank" rel="noopener" class="mt-2 inline-flex items-center gap-1 text-sm font-semibold text-amber-200 hover:text-white">
                            Watch on Instagram <x-icon name="chevron-right" class="w-4 h-4" />
                        </a>
                    @endif

                    <div class="mt-3 flex items-center justify-end gap-3">
                        <button type="button" @click="editing = ! editing"
                                class="min-h-[44px] px-2 text-xs font-semibold text-brand-500 hover:text-brand-300">
                            <span x-show="! editing">Edit</span>
                            <span x-show="editing" x-cloak>Cancel</span>
                        </button>
                        <form method="POST" action="{{ route('announcements.destroy', $announcement) }}"
                              onsubmit="return confirm('Delete this announcement?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="min-h-[44px] px-2 text-xs font-semibold text-red-300 hover:text-red-200">Delete</button>
                        </form>
                    </div>

                    <div x-show="editing" x-cloak class="mt-3 pt-3 border-t border-white/10">
                        @include('announcements._form', ['announcement' => $announcement])
                    </div>
                </x-card>
            @endforeach
        @endif
    </div>
</x-app-layout>
