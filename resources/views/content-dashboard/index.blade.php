@php
    /*
     * Pipeline tracking per content type, per account, for one month.
     *
     * Shows ALL items with published_date in the month (not just status=Published),
     * grouped by pipeline stage: Published, In Progress, Scheduled, Canceled.
     *
     * Reads the local Notion cache; the controller refreshes it on the way
     * in when stale. See NotionSyncRunner for why Notion is not read live.
     */
    $cell = function (array $t) {
        $published = $t['actual'];
        $planned = $t['planned'] ?? $published;
        $target = $t['target'];

        if ($planned === 0 && $target === null) {
            return ['text' => '—', 'class' => 'text-gray-300'];
        }

        if ($target !== null) {
            $hit = $published >= $target;
            $close = ! $hit && $target > 0 && $published / $target >= 0.6;
            return [
                'text' => $published . ' / ' . $target,
                'class' => $hit ? 'text-green-700 font-semibold'
                    : ($close ? 'text-amber-700 font-semibold' : 'text-red-700 font-semibold'),
                'sub' => $planned > $published ? '+' . ($planned - $published) . ' pending' : null,
            ];
        }

        return [
            'text' => $published . ($planned > $published ? ' / ' . $planned : ''),
            'class' => $planned > $published ? 'text-amber-700' : 'text-gray-600',
            'sub' => null,
        ];
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
            <div class="bg-white rounded-lg ring-1 ring-gray-200 p-4">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm font-medium text-gray-700">Pipeline Progress</span>
                    <span class="text-sm tabular-nums text-gray-500">
                        {{ $pipeline['published'] }} of {{ $pipeline['total'] }} published
                        ({{ round($pipeline['published'] / $pipeline['total'] * 100) }}%)
                    </span>
                </div>
                <div class="h-3 rounded-full bg-gray-100 overflow-hidden flex">
                    @php
                        $pPub = $pipeline['published'] / $pipeline['total'] * 100;
                        $pSch = $pipeline['scheduled'] / $pipeline['total'] * 100;
                        $pProg = $pipeline['in_progress'] / $pipeline['total'] * 100;
                        $pIdea = $pipeline['idea'] / $pipeline['total'] * 100;
                    @endphp
                    <div class="bg-green-500 h-full" style="width: {{ $pPub }}%"></div>
                    <div class="bg-blue-400 h-full" style="width: {{ $pSch }}%"></div>
                    <div class="bg-amber-400 h-full" style="width: {{ $pProg }}%"></div>
                    <div class="bg-gray-300 h-full" style="width: {{ $pIdea }}%"></div>
                </div>
                <div class="flex flex-wrap gap-4 mt-2 text-xs text-gray-500">
                    <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-green-500"></span> Published</span>
                    <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-blue-400"></span> Scheduled</span>
                    <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-amber-400"></span> In Progress</span>
                    @if ($pipeline['idea'] > 0)
                        <span class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-gray-300"></span> Idea</span>
                    @endif
                </div>
            </div>
        @endif

        <x-card padding="none">
            <div class="p-4 sm:p-5 pb-0 flex flex-wrap items-start justify-between gap-3">
                <x-section-heading title="{{ $month->format('F Y') }}"
                                   subtitle="Published / target per account. Click an account to see item-level details." />
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
                                <th class="px-3 py-2.5 text-right whitespace-nowrap">Published</th>
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
                                        {{ $group['total'] }}
                                        @if ($group['planned'] > $group['total'])
                                            <span class="text-amber-600 font-normal">/ {{ $group['planned'] }}</span>
                                        @elseif ($group['target'])
                                            <span class="text-gray-400 font-normal">/ {{ $group['target'] }}</span>
                                        @endif
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
                                            <td class="px-3 py-2.5 text-right tabular-nums whitespace-nowrap {{ $c['class'] }}">
                                                {{ $c['text'] }}
                                                @if (! empty($c['sub']))
                                                    <span class="block text-[10px] text-amber-500">{{ $c['sub'] }}</span>
                                                @endif
                                            </td>
                                        @endforeach
                                        <td class="px-3 py-2.5 text-right tabular-nums text-gray-400">{{ $row['stories'] ?: '—' }}</td>
                                        <td class="px-3 py-2.5 text-right tabular-nums">
                                            <span class="font-semibold text-gray-900">{{ $row['total'] }}</span>
                                            @if ($row['planned'] > $row['total'])
                                                <span class="text-amber-600">/ {{ $row['planned'] }}</span>
                                            @elseif ($row['target'])
                                                <span class="text-gray-400">/ {{ $row['target'] }}</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2.5">
                                            @php
                                                $denominator = $row['planned'] > 0 ? $row['planned'] : ($row['target'] ?? 0);
                                                $p = $denominator > 0 ? min((int) round($row['total'] / $denominator * 100), 999) : 0;
                                            @endphp
                                            @if ($denominator === 0)
                                                <span class="text-[11px] text-gray-300">—</span>
                                            @else
                                                <div class="flex items-center gap-2">
                                                    <div class="flex-1 h-1.5 rounded-full bg-gray-100 overflow-hidden">
                                                        <div @class([
                                                            'h-full rounded-full',
                                                            'bg-green-500' => $p >= 100,
                                                            'bg-amber-400' => $p >= 60 && $p < 100,
                                                            'bg-red-400' => $p < 60,
                                                        ]) style="width: {{ min($p, 100) }}%"></div>
                                                    </div>
                                                    <span class="text-[11px] tabular-nums text-gray-500 w-9 text-right">{{ $p }}%</span>
                                                </div>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2.5 text-right tabular-nums whitespace-nowrap">
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
