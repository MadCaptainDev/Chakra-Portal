<x-app-layout title="{{ $client->name }} — Forecast">
    <x-slot name="header">
        <x-page-header :title="$client->name" subtitle="Content forecast" eyebrow="Forecast">
            <x-slot name="actions">
                <x-badge :status="$forecast['status']" class="text-sm" />
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-5">
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <x-stat-card label="Reels remaining" :value="$forecast['remaining']" />
            <x-stat-card label="Weekly cadence"
                         :value="$forecast['weekly_cadence'] !== null ? number_format($forecast['weekly_cadence'], 1) : '—'" />
            <x-stat-card label="Depletes" :value="$forecast['depletion_date']?->format('j M') ?? '—'" />
            <x-stat-card label="Next shoot" :value="$forecast['next_shoot_date']?->format('j M') ?? 'None booked'" />
        </div>

        @if ($forecast['status'] === \App\Support\ContentForecast::STATUS_CRITICAL || $forecast['status'] === \App\Support\ContentForecast::STATUS_WARNING)
            <div class="rounded-lg bg-red-400/10 ring-1 ring-red-400/20 px-4 py-3 text-sm text-red-100">
                @if ($forecast['remaining'] <= 0)
                    Already out of unposted content and nothing is booked before it.
                @else
                    Runs out around <strong>{{ $forecast['depletion_date']->format('j F Y') }}</strong>
                    with nothing booked before then.
                    @if ($forecast['book_by_date'])
                        Book a shoot by <strong>{{ $forecast['book_by_date']->format('j F Y') }}</strong> to stay ahead of it.
                    @endif
                @endif
            </div>
        @endif

        @if ($forecast['remaining'] > 0 && $forecast['fuzzy'] > 0)
            <p class="text-xs text-brand-100/50">
                {{ $forecast['reliable'] }} of {{ $forecast['remaining'] }} confirmed via a linked shoot;
                the rest are matched by venture only and may be less exact.
            </p>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
            <x-card padding="md">
                <x-section-label dark>Reels still in the pipeline</x-section-label>
                <div class="mt-3 divide-y divide-white/5">
                    @forelse ($items as $item)
                        <div class="py-2.5 flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm text-white truncate">{{ $item->title ?: 'Untitled' }}</p>
                                <p class="text-[11px] text-brand-100/40">
                                    {{ $item->sourceLabel() }}
                                    @if ($item->notionShoot)
                                        · linked to {{ $item->notionShoot->title }}
                                    @endif
                                </p>
                            </div>
                            <x-badge :status="$item->status" />
                        </div>
                    @empty
                        <p class="py-4 text-sm text-brand-100/50">Nothing in the pipeline right now.</p>
                    @endforelse
                </div>
            </x-card>

            <x-card padding="md">
                <x-section-label dark>Upcoming shoots</x-section-label>
                <div class="mt-3 divide-y divide-white/5">
                    @forelse ($upcomingShoots as $shoot)
                        <div class="py-2.5 flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm text-white truncate">{{ $shoot->title }}</p>
                                <p class="text-[11px] text-brand-100/40">{{ $shoot->starts_at->format('j M Y') }}</p>
                            </div>
                            <x-badge :status="$shoot->status" />
                        </div>
                    @empty
                        <p class="py-4 text-sm text-brand-100/50">Nothing booked yet.</p>
                    @endforelse
                </div>
                <a href="{{ route('shoots.create') }}"
                   class="mt-3 inline-block text-xs font-semibold uppercase tracking-widest text-brand-300 hover:text-white">
                    Book a shoot →
                </a>
            </x-card>
        </div>
    </div>
</x-app-layout>
