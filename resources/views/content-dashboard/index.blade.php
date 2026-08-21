@php
    /*
     * Published-vs-target per content type, per account, for one month.
     *
     * Only accounts carrying at least one target appear -- an account with
     * nothing committed has no verdict to show. The count of the rest is
     * surfaced as a link rather than hidden entirely.
     *
     * Reads the local Notion cache; the controller refreshes it on the way
     * in when stale. See NotionSyncRunner for why Notion is not read live.
     */
    $cell = function (array $t) {
        if ($t['target'] === null) {
            return ['text' => (string) $t['actual'], 'class' => 'text-gray-400'];
        }
        $hit = $t['actual'] >= $t['target'];
        $close = ! $hit && $t['target'] > 0 && $t['actual'] / $t['target'] >= 0.6;

        return [
            'text' => $t['actual'].' / '.$t['target'],
            'class' => $hit ? 'text-green-700 font-semibold'
                : ($close ? 'text-amber-700 font-semibold' : 'text-red-700 font-semibold'),
        ];
    };
@endphp

<x-app-layout title="Content Dashboard">
    <x-slot name="header">
        <x-page-header title="Content Dashboard"
                       subtitle="Published against target, per content type.">
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
                <label for="month" class="text-xs font-semibold uppercase tracking-wider text-gray-500">Month</label>
                <select id="month" name="month" onchange="this.form.submit()"
                        class="rounded-md border-gray-300 text-sm py-1.5 pr-8">
                    @foreach ($months as $m)
                        <option value="{{ $m->format('Y-m') }}" @selected($m->format('Y-m') === $month->format('Y-m'))>
                            {{ $m->format('F Y') }}
                        </option>
                    @endforeach
                </select>
                <noscript><x-secondary-button type="submit">Go</x-secondary-button></noscript>
            </form>

            <p class="text-xs text-gray-500">Notion synced {{ $lastSynced?->diffForHumans() ?? 'never' }}</p>
        </div>

        {{-- Content nobody has assigned to an account is work no target is
             measuring. Said plainly with a way to fix it, rather than
             quietly missing from every total below. --}}
        @if ($unmappedThisMonth > 0)
            <div class="rounded-lg bg-amber-50 ring-1 ring-amber-100 px-4 py-3 flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-amber-800">
                    <span class="font-semibold">{{ number_format($unmappedThisMonth) }} item(s) this month</span>
                    belong to {{ $unmapped->count() }} venture(s) with no account, so they are not counted below.
                </p>
                <a href="{{ route('content-accounts.edit') }}"
                   class="shrink-0 text-xs font-semibold uppercase tracking-widest text-amber-900 hover:text-amber-700">Assign them →</a>
            </div>
        @endif

        {{-- Month totals, one tile per targeted type. --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            @foreach ($typeTotals as $t)
                <x-stat-card :label="$t['label']"
                             :value="$t['target'] !== null ? $t['actual'].' / '.$t['target'] : (string) $t['actual']"
                             icon="check-circle"
                             :accent="$t['target'] === null ? 'gray' : ($t['actual'] >= $t['target'] ? 'green' : 'red')" />
            @endforeach
            <x-stat-card label="All types"
                         :value="$grandTarget !== null ? $grandTotal.' / '.$grandTarget : (string) $grandTotal"
                         icon="trending-up"
                         :accent="$grandTarget === null ? 'gray' : ($grandTotal >= $grandTarget ? 'green' : 'red')" />
        </div>

        <x-card padding="none">
            <div class="p-4 sm:p-5 pb-0 flex flex-wrap items-start justify-between gap-3">
                <x-section-heading title="{{ $month->format('F Y') }}"
                                   subtitle="Published / target. One row per account — a client running two accounts is two rows." />
                @if ($untargetedAccounts > 0)
                    <a href="{{ route('content-accounts.edit') }}"
                       class="shrink-0 text-xs font-semibold uppercase tracking-widest text-brand-600 hover:text-brand-800">
                        {{ $untargetedAccounts }} account(s) without a target →
                    </a>
                @endif
            </div>

            @if ($clients->isEmpty())
                <div class="p-4 sm:p-5">
                    <x-empty-state message="No account has a target set yet.">
                        <a href="{{ route('content-accounts.edit') }}"
                           class="text-xs font-semibold uppercase tracking-widest text-brand-600 hover:text-brand-800">
                            Set targets →
                        </a>
                    </x-empty-state>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-[11px] font-semibold uppercase tracking-wider text-gray-500 border-b border-gray-200">
                                <th class="px-4 sm:px-5 py-2.5">Client / Account</th>
                                @foreach ($targeted as $label)
                                    <th class="px-3 py-2.5 text-right whitespace-nowrap">{{ $label }}</th>
                                @endforeach
                                <th class="px-3 py-2.5 text-right">Stories</th>
                                <th class="px-3 py-2.5 text-right">Total</th>
                                <th class="px-3 py-2.5 w-32">Progress</th>
                                <th class="px-3 py-2.5 text-right whitespace-nowrap">IG reach</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($clients as $group)
                                <tr class="bg-gray-50/70">
                                    <td class="px-4 sm:px-5 py-2 font-semibold text-gray-900">
                                        {{ $group['client']?->name ?? 'Unknown client' }}
                                    </td>
                                    @foreach ($targeted as $source => $label)
                                        <td class="px-3 py-2 text-right tabular-nums text-gray-400">
                                            {{ $group['rows']->sum(fn ($r) => $r['types'][$source]['actual']) ?: '' }}
                                        </td>
                                    @endforeach
                                    <td class="px-3 py-2 text-right tabular-nums text-gray-400">{{ $group['rows']->sum('stories') ?: '' }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums font-semibold text-gray-900">
                                        {{ $group['total'] }}@if ($group['target']) <span class="text-gray-400 font-normal">/ {{ $group['target'] }}</span>@endif
                                    </td>
                                    <td class="px-3 py-2"></td>
                                    <td class="px-3 py-2"></td>
                                </tr>

                                @foreach ($group['rows'] as $row)
                                    <tr>
                                        <td class="px-4 sm:px-5 py-2.5 pl-8 sm:pl-10">
                                            <a href="{{ route('content-dashboard.show', [$row['account'], 'month' => $month->format('Y-m')]) }}"
                                               class="text-gray-900 hover:text-brand-600 font-medium">
                                                {{ $row['account']->name }}
                                            </a>
                                            @if ($row['account']->ventures->isEmpty())
                                                <span class="block text-[11px] text-amber-600">No ventures assigned</span>
                                            @endif
                                        </td>
                                        @foreach ($targeted as $source => $label)
                                            @php $c = $cell($row['types'][$source]); @endphp
                                            <td class="px-3 py-2.5 text-right tabular-nums whitespace-nowrap {{ $c['class'] }}">{{ $c['text'] }}</td>
                                        @endforeach
                                        <td class="px-3 py-2.5 text-right tabular-nums text-gray-400">{{ $row['stories'] ?: '—' }}</td>
                                        <td class="px-3 py-2.5 text-right tabular-nums font-semibold text-gray-900">
                                            {{ $row['total'] }}@if ($row['target']) <span class="text-gray-400 font-normal">/ {{ $row['target'] }}</span>@endif
                                        </td>
                                        <td class="px-3 py-2.5">
                                            @if ($row['target'] === null)
                                                <span class="text-[11px] text-gray-300">no target</span>
                                            @else
                                                @php $p = min($row['pct'] ?? 0, 999); @endphp
                                                <div class="flex items-center gap-2">
                                                    <div class="flex-1 h-1.5 rounded-full bg-gray-100 overflow-hidden">
                                                        <div @class([
                                                                'h-full rounded-full',
                                                                'bg-green-500' => $row['total'] >= $row['target'],
                                                                'bg-amber-400' => $row['total'] < $row['target'] && $p >= 60,
                                                                'bg-red-400' => $row['total'] < $row['target'] && $p < 60,
                                                            ]) style="width: {{ min($p, 100) }}%"></div>
                                                    </div>
                                                    <span class="text-[11px] tabular-nums text-gray-500 w-9 text-right">{{ $p }}%</span>
                                                </div>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2.5 text-right tabular-nums whitespace-nowrap">
                                            {{-- A dash, not a zero: no connected Instagram and no
                                                 reach are different answers. --}}
                                            @if ($row['performance'])
                                                <span class="text-gray-900">{{ number_format($row['performance']['reach']) }}</span>
                                                <span class="block text-[11px] text-gray-400">{{ $row['performance']['items'] }} matched</span>
                                            @else
                                                <span class="text-gray-300">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>
