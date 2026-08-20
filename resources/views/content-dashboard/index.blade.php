@php
    /*
     * Videos published per client account, per month, against target.
     *
     * Reads the local Notion cache. The controller refreshes it on the way
     * in when it is stale -- see NotionSyncRunner for why Notion is not
     * read live.
     */
    $pct = fn (?int $p) => $p === null ? null : min($p, 999);
@endphp

<x-app-layout title="Content Dashboard">
    <x-slot name="header">
        <x-page-header title="Content Dashboard"
                       subtitle="What each client account published, against its monthly target.">
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

        {{-- Month picker + freshness. --}}
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

            <p class="text-xs text-gray-500">
                Notion synced {{ $lastSynced?->diffForHumans() ?? 'never' }}
            </p>
        </div>

        {{-- Content nobody has assigned to an account is work no target is
             measuring. Said plainly with a way to fix it, rather than
             quietly missing from every total below. --}}
        @if ($unmappedThisMonth > 0)
            <div class="rounded-lg bg-amber-50 ring-1 ring-amber-100 px-4 py-3 flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-amber-800">
                    <span class="font-semibold">{{ number_format($unmappedThisMonth) }} item(s) this month</span>
                    belong to {{ $unmapped->count() }} venture(s) not assigned to any account, so they are not counted below.
                </p>
                <a href="{{ route('content-accounts.edit') }}"
                   class="shrink-0 text-xs font-semibold uppercase tracking-widest text-amber-900 hover:text-amber-700">
                    Assign them →
                </a>
            </div>
        @endif

        {{-- Month totals. --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <x-stat-card label="Published" :value="number_format($grandTotal)" icon="check-circle" accent="brand" />
            <x-stat-card label="Target"
                         :value="$grandTarget !== null ? number_format($grandTarget) : '—'"
                         icon="trending-up" accent="gray" />
            <x-stat-card label="vs target"
                         :value="$grandTarget !== null ? ($grandTotal - $grandTarget >= 0 ? '+' : '').number_format($grandTotal - $grandTarget) : '—'"
                         icon="sparkles"
                         :accent="$grandTarget === null ? 'gray' : ($grandTotal >= $grandTarget ? 'green' : 'red')" />
            <x-stat-card label="vs last month"
                         :value="($grandTotal - $grandPrevious >= 0 ? '+' : '').number_format($grandTotal - $grandPrevious)"
                         icon="trending-up"
                         :accent="$grandTotal >= $grandPrevious ? 'green' : 'red'" />
        </div>

        <x-card padding="none">
            <div class="p-4 sm:p-5 pb-0">
                <x-section-heading title="{{ $month->format('F Y') }}"
                                   subtitle="One row per account. A client with two accounts is two rows, each with its own target." />
            </div>

            @if ($clients->isEmpty())
                <div class="p-4 sm:p-5">
                    <x-empty-state message="No content accounts set up yet.">
                        <a href="{{ route('content-accounts.edit') }}"
                           class="text-xs font-semibold uppercase tracking-widest text-brand-600 hover:text-brand-800">
                            Set up accounts and targets →
                        </a>
                    </x-empty-state>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-[11px] font-semibold uppercase tracking-wider text-gray-500 border-b border-gray-200">
                                <th class="px-4 sm:px-5 py-2.5">Client / Account</th>
                                @foreach ($sources as $label)
                                    <th class="px-3 py-2.5 text-right">{{ $label }}</th>
                                @endforeach
                                <th class="px-3 py-2.5 text-right">Total</th>
                                <th class="px-3 py-2.5 text-right">Prev</th>
                                <th class="px-3 py-2.5 text-right">Target</th>
                                <th class="px-3 py-2.5 w-40">Progress</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($clients as $group)
                                {{-- Client subtotal row, then its accounts. A
                                     one-account client still gets both, so the
                                     shape of the table never changes with the
                                     data. --}}
                                <tr class="bg-gray-50/70">
                                    <td class="px-4 sm:px-5 py-2 font-semibold text-gray-900">
                                        {{ $group['client']?->name ?? 'Unknown client' }}
                                    </td>
                                    @foreach ($sources as $source => $label)
                                        <td class="px-3 py-2 text-right tabular-nums text-gray-400">
                                            {{ $group['rows']->sum(fn ($r) => $r['counts'][$source] ?? 0) ?: '' }}
                                        </td>
                                    @endforeach
                                    <td class="px-3 py-2 text-right tabular-nums font-semibold text-gray-900">{{ $group['total'] }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums text-gray-400">{{ $group['previous'] }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums text-gray-500">{{ $group['target'] ?? '—' }}</td>
                                    <td class="px-3 py-2"></td>
                                </tr>

                                @foreach ($group['rows'] as $row)
                                    <tr>
                                        <td class="px-4 sm:px-5 py-2.5 pl-8 sm:pl-10">
                                            <span class="text-gray-900">{{ $row['account']->name }}</span>
                                            @if ($row['account']->ventures->isNotEmpty())
                                                <span class="block text-[11px] text-gray-400 truncate max-w-xs"
                                                      title="{{ implode(', ', $row['account']->ventureNames()) }}">
                                                    {{ implode(', ', $row['account']->ventureNames()) }}
                                                </span>
                                            @else
                                                <span class="block text-[11px] text-amber-600">No ventures assigned</span>
                                            @endif
                                        </td>
                                        @foreach ($sources as $source => $label)
                                            <td class="px-3 py-2.5 text-right tabular-nums text-gray-600">
                                                {{ $row['counts'][$source] ?? '—' }}
                                            </td>
                                        @endforeach
                                        <td class="px-3 py-2.5 text-right tabular-nums font-semibold text-gray-900">{{ $row['total'] }}</td>
                                        <td class="px-3 py-2.5 text-right tabular-nums text-gray-400">{{ $row['previous'] }}</td>
                                        <td class="px-3 py-2.5 text-right tabular-nums text-gray-600">{{ $row['target'] ?? '—' }}</td>
                                        <td class="px-3 py-2.5">
                                            @if ($row['target'] === null)
                                                <span class="text-[11px] text-gray-300">no target set</span>
                                            @else
                                                @php $p = $pct($row['pct']); @endphp
                                                <div class="flex items-center gap-2">
                                                    <div class="flex-1 h-1.5 rounded-full bg-gray-100 overflow-hidden">
                                                        <div @class([
                                                                'h-full rounded-full',
                                                                'bg-green-500' => $row['total'] >= $row['target'],
                                                                'bg-amber-400' => $row['total'] < $row['target'] && $p >= 60,
                                                                'bg-red-400' => $row['total'] < $row['target'] && $p < 60,
                                                            ])
                                                             style="width: {{ min($p, 100) }}%"></div>
                                                    </div>
                                                    <span class="text-[11px] tabular-nums text-gray-500 w-10 text-right">{{ $p }}%</span>
                                                </div>
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
