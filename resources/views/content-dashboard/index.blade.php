@php
    /*
     * Pipeline tracking per content type, per account, for one month.
     *
     * Reads the local Notion cache; the controller refreshes it on the way
     * in when stale. See NotionSyncRunner for why Notion is not read live.
     *
     * Which platform a content type actually is -- Reels and Posts are
     * Instagram, Shorts are YouTube. Used to put the real brand mark next
     * to each row instead of asking someone to remember that "Insta Reel"
     * and "Insta Post" are the same platform by reading the label.
     */
    $platformIcon = [
        \App\Models\ContentItem::SOURCE_REEL => 'instagram',
        \App\Models\ContentItem::SOURCE_POST => 'instagram',
        \App\Models\ContentItem::SOURCE_YOUTUBE => 'youtube',
    ];

    // Deliberately three states, not a percentage, for the same reason the
    // per-account dashboard cards use a pace verdict rather than a number:
    // "hit" / "close" / "behind" is the decision this cell exists to
    // support, and a raw 91% invites arguing with the metric instead.
    $verdict = function (int $actual, ?int $target): ?string {
        if ($target === null || $target <= 0) {
            return null;
        }

        if ($actual >= $target) {
            return 'hit';
        }

        return $actual / $target >= 0.6 ? 'close' : 'behind';
    };
@endphp

<x-app-layout title="Content Dashboard">
    <x-slot name="header">
        <x-page-header title="Content Dashboard"
                       subtitle="Track pipeline progress — published, in progress, and scheduled.">
            <x-slot name="actions">
                <form method="POST" action="{{ route('content-dashboard.refresh') }}">
                    @csrf
                    <input type="hidden" name="return_to" value="{{ request()->fullUrl() }}">
                    <x-secondary-button type="submit">Refresh from Notion</x-secondary-button>
                </form>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-5">

        <div class="flex flex-wrap items-center justify-between gap-3">
            <form method="GET" action="{{ route('content-dashboard.index') }}" class="flex items-center gap-2">
                <label for="month" class="text-xs font-semibold uppercase tracking-wider text-brand-100/60">Month</label>
                <select id="month" name="month" onchange="this.form.submit()"
                        class="rounded-md border-white/15 text-sm py-1.5 pr-8">
                    @foreach ($months as $m)
                        <option value="{{ $m->format('Y-m') }}" @selected($m->format('Y-m') === $month->format('Y-m'))>
                            {{ $m->format('F Y') }}
                        </option>
                    @endforeach
                </select>
                <noscript><x-secondary-button type="submit">Go</x-secondary-button></noscript>
            </form>

            <p class="text-xs text-brand-100/60">Notion synced {{ $lastSynced?->diffForHumans() ?? 'never' }}</p>
        </div>

        @if ($unmappedThisMonth > 0)
            <div class="rounded-lg bg-amber-400/10 ring-1 ring-amber-400/20 px-4 py-3 flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-amber-200">
                    <span class="font-semibold">{{ number_format($unmappedThisMonth) }} item(s) this month</span>
                    belong to {{ $unmapped->count() }} venture(s) with no account, so they are not counted below.
                </p>
                <a href="{{ route('content-accounts.edit') }}"
                   class="shrink-0 text-xs font-semibold uppercase tracking-widest text-amber-200 hover:text-amber-200">Assign them →</a>
            </div>
        @endif

        {{-- Pipeline overview cards --}}
        <div class="grid grid-cols-2 lg:grid-cols-5 gap-3">
            <x-stat-card label="Published"
                         :value="(string) $pipeline['published']"
                         icon="check-circle"
                         accent="green" />
            <x-stat-card label="In Progress"
                         :value="(string) $pipeline['in_progress']"
                         icon="refresh"
                         accent="amber" />
            <x-stat-card label="Scheduled"
                         :value="(string) $pipeline['scheduled']"
                         icon="clock"
                         accent="blue" />
            <x-stat-card label="Planned Total"
                         :value="(string) $pipeline['total']"
                         icon="collection"
                         accent="brand" />
            @if ($pipeline['canceled'] > 0)
                <x-stat-card label="Canceled"
                             :value="(string) $pipeline['canceled']"
                             icon="x-circle"
                             accent="red" />
            @else
                <x-stat-card label="vs Target"
                             :value="$grandTarget !== null ? $grandTotal.' / '.$grandTarget : '—'"
                             icon="trending-up"
                             :accent="$grandTarget === null ? 'gray' : ($grandTotal >= $grandTarget ? 'green' : 'red')" />
            @endif
        </div>

        {{-- Pipeline progress bar --}}
        @if ($pipeline['total'] > 0)
            <div class="bg-white/5 rounded-lg ring-1 ring-white/10 p-4">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm font-medium text-brand-100/80">Pipeline Progress</span>
                    <span class="text-sm tabular-nums text-brand-100/60">
                        {{ $pipeline['published'] }} of {{ $pipeline['total'] }} published
                        ({{ round($pipeline['published'] / $pipeline['total'] * 100) }}%)
                    </span>
                </div>
                <div class="h-3 rounded-full bg-white/10 overflow-hidden flex">
                    @php
                        $pPub = $pipeline['published'] / $pipeline['total'] * 100;
                        $pSch = $pipeline['scheduled'] / $pipeline['total'] * 100;
                        $pProg = $pipeline['in_progress'] / $pipeline['total'] * 100;
                        $pIdea = $pipeline['idea'] / $pipeline['total'] * 100;
                    @endphp
                    <div class="bg-green-500 h-full" style="width: {{ $pPub }}%"></div>
                    <div class="bg-blue-400 h-full" style="width: {{ $pSch }}%"></div>
                    <div class="bg-amber-400 h-full" style="width: {{ $pProg }}%"></div>
                    <div class="bg-white/20 h-full" style="width: {{ $pIdea }}%"></div>
                </div>
                <div class="flex flex-wrap gap-4 mt-2 text-xs text-brand-100/60">
                    <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-green-500"></span> Published</span>
                    <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-blue-400"></span> Scheduled</span>
                    <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-amber-400"></span> In Progress</span>
                    @if ($pipeline['idea'] > 0)
                        <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-white/20"></span> Idea</span>
                    @endif
                </div>
            </div>
        @endif

        <div class="flex flex-wrap items-start justify-between gap-3">
            <x-section-heading title="{{ $month->format('F Y') }}"
                               subtitle="Published against target per account, split by platform. Open an account for item-level detail." />
            @if ($untargetedAccounts > 0)
                <a href="{{ route('content-accounts.edit') }}"
                   class="shrink-0 text-xs font-semibold uppercase tracking-widest text-brand-300 hover:text-brand-200">
                    {{ $untargetedAccounts }} account(s) without a target →
                </a>
            @endif
        </div>

        @if ($clients->isEmpty())
            <x-card class="p-4 sm:p-5">
                <x-empty-state message="No account has a target set yet.">
                    <a href="{{ route('content-accounts.edit') }}"
                       class="text-xs font-semibold uppercase tracking-widest text-brand-300 hover:text-brand-200">
                        Set targets →
                    </a>
                </x-empty-state>
            </x-card>
        @else
            <div class="space-y-6">
                @foreach ($clients as $group)
                    <section>
                        <div class="flex items-baseline justify-between gap-3 mb-3">
                            <h3 class="font-semibold text-white">{{ $group['client']?->name ?? 'Unknown client' }}</h3>
                            <p class="text-xs text-brand-100/50 tabular-nums">
                                {{ $group['total'] }}@if ($group['target'])<span class="text-brand-100/40"> / {{ $group['target'] }}</span>@endif published
                            </p>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
                            @foreach ($group['rows'] as $row)
                                <x-card class="p-5 space-y-4">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <a href="{{ route('content-dashboard.show', [$row['account'], 'month' => $month->format('Y-m')]) }}"
                                               class="text-sm font-semibold text-white hover:text-brand-300 truncate block">
                                                {{ $row['account']->name }}
                                            </a>
                                            @if ($row['account']->ventures->isEmpty())
                                                <span class="block text-[11px] text-amber-300 mt-0.5">No ventures assigned</span>
                                            @endif
                                        </div>
                                        @if ($row['performance'])
                                            <span class="shrink-0 text-right">
                                                <span class="block text-xs font-semibold text-white tabular-nums">{{ number_format($row['performance']['reach']) }}</span>
                                                <span class="block text-[10px] text-brand-100/40">IG reach</span>
                                            </span>
                                        @endif
                                    </div>

                                    {{-- One row per platform: the split this whole
                                         redesign exists for. A bare "8 published"
                                         used to hide whether that was eight reels
                                         or eight stories. --}}
                                    <div class="space-y-3">
                                        @foreach ($targeted as $source => $label)
                                            @php
                                                $t = $row['types'][$source];
                                                $v = $verdict($t['actual'], $t['target']);
                                            @endphp
                                            <div class="flex items-center gap-3">
                                                <x-brand-icon :name="$platformIcon[$source]" class="w-6 h-6 shrink-0" />
                                                <div class="flex-1 min-w-0">
                                                    <div class="flex items-baseline justify-between gap-2">
                                                        <span class="text-xs text-brand-100/70">{{ $label }}</span>
                                                        <span @class([
                                                            'text-sm font-semibold tabular-nums',
                                                            'text-green-300' => $v === 'hit',
                                                            'text-amber-300' => $v === 'close',
                                                            'text-red-300' => $v === 'behind',
                                                            'text-white' => $v === null,
                                                        ])>
                                                            {{ $t['actual'] }}@if ($t['target'] !== null)<span class="text-brand-100/40">/{{ $t['target'] }}</span>@endif
                                                            @if ($t['planned'] > $t['actual'])
                                                                <span class="text-brand-100/40 font-normal">(+{{ $t['planned'] - $t['actual'] }} pending)</span>
                                                            @endif
                                                        </span>
                                                    </div>
                                                    @if ($t['target'] !== null)
                                                        <div class="h-1.5 rounded-full bg-white/[0.07] overflow-hidden mt-1">
                                                            <div @class([
                                                                'h-full rounded-full',
                                                                'bg-green-400' => $v === 'hit',
                                                                'bg-amber-400' => $v === 'close',
                                                                'bg-red-400' => $v === 'behind',
                                                            ]) style="width: {{ min(100, $t['pct'] ?? 0) }}%"></div>
                                                        </div>
                                                    @endif
                                                </div>
                                            </div>
                                        @endforeach

                                        @if ($row['stories'] > 0)
                                            <div class="flex items-center gap-3 pt-1">
                                                <x-brand-icon name="instagram" class="w-6 h-6 shrink-0 opacity-60" />
                                                <div class="flex-1 flex items-baseline justify-between gap-2">
                                                    <span class="text-xs text-brand-100/70">Stories</span>
                                                    <span class="text-sm font-semibold tabular-nums text-white">{{ $row['stories'] }}</span>
                                                </div>
                                            </div>
                                        @endif
                                    </div>

                                    <div class="flex items-baseline justify-between gap-2 pt-3 border-t border-white/5 text-xs">
                                        <span class="text-brand-100/50">Total</span>
                                        <span class="font-semibold tabular-nums text-white">
                                            {{ $row['total'] }}
                                            @if ($row['target'])
                                                <span class="text-brand-100/40 font-normal">/ {{ $row['target'] }}</span>
                                            @endif
                                        </span>
                                    </div>
                                </x-card>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>
        @endif
    </div>
</x-app-layout>
