<x-app-layout title="Forecast">
    <x-slot name="header">
        <x-page-header title="Forecast"
                       subtitle="How much unposted content each client has left, and whether a shoot is booked before it runs out." />
    </x-slot>

    <div class="space-y-5">
        @if ($rows->isEmpty())
            <x-card padding="lg">
                <x-empty-state message="No client has a content account with a target set yet." />
            </x-card>
        @else
            <x-card padding="none" class="overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-white/10 text-sm">
                        <thead class="bg-white/[0.03]">
                            <tr class="text-left text-[11px] font-semibold uppercase tracking-wider text-brand-100/50">
                                <th class="px-4 py-3">Client</th>
                                <th class="px-4 py-3">Remaining</th>
                                <th class="px-4 py-3">Weekly cadence</th>
                                <th class="px-4 py-3">Depletes</th>
                                <th class="px-4 py-3">Next shoot</th>
                                <th class="px-4 py-3">Book by</th>
                                <th class="px-4 py-3">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            @foreach ($rows as $row)
                                <tr class="hover:bg-white/[0.03]">
                                    <td class="px-4 py-3">
                                        <a href="{{ route('forecast.show', $row['client']) }}" class="font-semibold text-white hover:text-brand-300">
                                            {{ $row['client']->name }}
                                        </a>
                                    </td>
                                    <td class="px-4 py-3 tabular-nums">
                                        {{ $row['remaining'] }}
                                        @if ($row['remaining'] > 0 && $row['fuzzy'] > 0)
                                            <span class="text-[11px] text-brand-100/40">({{ $row['reliable'] }} confirmed)</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 tabular-nums text-brand-100/70">
                                        {{ $row['weekly_cadence'] !== null ? number_format($row['weekly_cadence'], 1).'/wk' : '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-brand-100/70">
                                        {{ $row['depletion_date']?->format('j M Y') ?? '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-brand-100/70">
                                        {{ $row['next_shoot_date']?->format('j M Y') ?? 'None booked' }}
                                    </td>
                                    <td class="px-4 py-3 text-brand-100/70">
                                        {{ $row['book_by_date']?->format('j M Y') ?? '—' }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <x-badge :status="$row['status']" />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-card>
        @endif
    </div>
</x-app-layout>
