{{-- ——— Delivery ———
     What the studio actually sells. Money and hours can both look
     healthy through a month where the content commitments were
     missed, and the first anyone hears of that is the client
     asking. --}}
<section class="space-y-6">
    @include('dashboard._content-pipeline', [
        'month' => $month,
        'contentAccounts' => $contentAccounts,
        'contentCards' => $contentCards,
        'pinnedAccountIds' => $pinnedAccountIds,
        'hasPinnedAccounts' => $hasPinnedAccounts,
        'routeName' => 'dashboard',
    ])

    <div>
    <div class="flex items-baseline justify-between gap-4 mb-4">
        <x-section-label dark>Delivery summary</x-section-label>
        <a href="{{ route('content-dashboard.index') }}"
           class="text-xs font-semibold uppercase tracking-widest text-brand-300 hover:text-white">Content Dashboard →</a>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3.5">
        @foreach ($content['types'] as $type)
            <x-card tone="dark" class="p-4">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-brand-100/50">{{ $type['label'] }}</p>
                <p class="mt-1 text-2xl font-bold tabular-nums
                    {{ $type['target'] === null ? 'text-white' : ($type['actual'] >= $type['target'] ? 'text-green-400' : 'text-amber-300') }}">
                    {{ $type['actual'] }}@if ($type['target'] !== null)<span class="text-base text-brand-100/40"> / {{ $type['target'] }}</span>@endif
                </p>
            </x-card>
        @endforeach
        <x-card tone="dark" class="p-4">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-brand-100/50">Upcoming shoots</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-white">{{ $content['upcomingShootCount'] }}</p>
        </x-card>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3.5 mt-3.5 items-start">
        <x-card tone="dark" class="p-5 sm:p-6">
            <p class="text-sm font-semibold text-white mb-3">Behind on reels this month</p>

            @forelse ($content['behind'] as $row)
                <a href="{{ route('content-dashboard.show', [$row['account'], 'month' => $month->format('Y-m')]) }}"
                   class="flex items-center justify-between gap-3 py-2 border-b border-white/5 last:border-0 group">
                    <span class="min-w-0">
                        <span class="block text-sm text-white truncate group-hover:text-brand-200">{{ $row['account']->name }}</span>
                        <span class="block text-[11px] text-brand-100/40 truncate">{{ $row['account']->client?->name }}</span>
                    </span>
                    <span class="shrink-0 text-right">
                        {{-- Reel specifically, not the blended total across
                             every type -- see DashboardController::contentPulse(). --}}
                        <span class="block text-sm tabular-nums text-amber-300">
                            {{ $row['types']['reel']['actual'] }} / {{ $row['types']['reel']['target'] }} reels
                        </span>
                        {{-- A deliberately simple suggestion, not a real
                             schedule -- see DashboardController::contentPulse(). --}}
                        <span class="block text-[10px] text-brand-100/40">
                            Shoot by {{ $row['suggestedShootBy']?->format('j M') ?? 'ASAP' }}
                        </span>
                    </span>
                </a>
            @empty
                <p class="text-sm text-brand-100/50">
                    {{ ($content['types']['reel']['target'] ?? null) === null
                        ? 'No reel targets set yet — nothing to measure against.'
                        : 'Every account with a reel target is on or ahead of it.' }}
                </p>
            @endforelse

            @if ($content['unmappedThisMonth'] > 0)
                <p class="mt-3 pt-3 border-t border-white/5 text-[11px] text-amber-300/80">
                    {{ number_format($content['unmappedThisMonth']) }} item(s) this month sit in
                    {{ $content['unmappedVentures'] }} unmapped venture(s) and are counted against nobody.
                    <a href="{{ route('content-accounts.edit') }}" class="underline hover:text-white">Map them</a>.
                </p>
            @endif
        </x-card>

        <x-card tone="dark" class="p-5 sm:p-6">
            <div class="flex items-baseline justify-between gap-3 mb-3">
                <p class="text-sm font-semibold text-white">Next shoots</p>
                <a href="{{ route('shoots.calendar') }}"
                   class="text-[11px] font-semibold uppercase tracking-widest text-brand-300 hover:text-white">All shoots →</a>
            </div>

            @forelse ($content['upcomingShoots'] as $shoot)
                <a href="{{ route('shoots.show', $shoot) }}"
                   class="flex items-center justify-between gap-3 py-2 border-b border-white/5 last:border-0 group">
                    <span class="min-w-0">
                        <span class="block text-sm text-white truncate group-hover:text-brand-200">{{ $shoot->title }}</span>
                        <span class="block text-[11px] text-brand-100/40 truncate">
                            {{ $shoot->client?->name ?? 'No client' }}@if ($shoot->location) · {{ $shoot->location }}@endif
                        </span>
                    </span>
                    <span class="shrink-0 text-xs tabular-nums text-brand-100/60">{{ $shoot->starts_at->format('j M') }}</span>
                </a>
            @empty
                <p class="text-sm text-brand-100/50">Nothing scheduled.</p>
            @endforelse

            @if ($content['shootsToImport'] > 0)
                <p class="mt-3 pt-3 border-t border-white/5 text-[11px] text-amber-300/80">
                    {{ $content['shootsToImport'] }} Notion shoot(s) waiting on the next sync.
                    <a href="{{ route('shoots.index') }}" class="underline hover:text-white">Sync now</a>.
                </p>
            @endif
        </x-card>
    </div>
    </div>
</section>
