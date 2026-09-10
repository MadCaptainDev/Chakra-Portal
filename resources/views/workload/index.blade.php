<x-app-layout title="Workload">
    <x-slot name="header">
        <x-page-header title="Workload"
                       subtitle="Who has how much on their plate right now — active shoots, content in the pipeline, and what's overdue." />
    </x-slot>

    <div class="space-y-5" x-data="{ open: null }">
        <x-card padding="none" class="overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-white/10 text-sm">
                    <thead class="bg-white/[0.03]">
                        <tr class="text-left text-[11px] font-semibold uppercase tracking-wider text-brand-100/50">
                            <th class="px-4 py-3">Staff</th>
                            <th class="px-4 py-3">Active shoots</th>
                            <th class="px-4 py-3">Active content</th>
                            <th class="px-4 py-3" title="Reel/YouTube count 3x, Post 2x, Story 1x — a real edit weighs more than a phone clip.">
                                Load ⓘ
                            </th>
                            <th class="px-4 py-3">Overdue</th>
                            <th class="px-4 py-3">Delivered</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        @forelse ($rows as $row)
                            <tr class="hover:bg-white/[0.03] cursor-pointer"
                                @click="open = open === {{ $row['user']->id }} ? null : {{ $row['user']->id }}">
                                <td class="px-4 py-3 font-semibold text-white">{{ $row['user']->name }}</td>
                                <td class="px-4 py-3 tabular-nums text-brand-100/70">{{ $row['active_shoots'] }}</td>
                                <td class="px-4 py-3 tabular-nums text-brand-100/70">{{ $row['active_content'] }}</td>
                                <td class="px-4 py-3 tabular-nums text-white font-semibold">{{ $row['weighted_load'] }}</td>
                                <td class="px-4 py-3 tabular-nums">
                                    @if ($row['overdue'] > 0)
                                        <span class="text-red-300 font-semibold">{{ $row['overdue'] }}</span>
                                    @else
                                        <span class="text-brand-100/40">0</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 tabular-nums text-brand-100/70">{{ $row['delivered'] }}</td>
                            </tr>
                            <tr x-show="open === {{ $row['user']->id }}" x-cloak>
                                <td colspan="6" class="px-4 pb-3 bg-white/[0.02]">
                                    @if ($row['by_client']->isEmpty())
                                        <p class="text-xs text-brand-100/40">Nothing active right now.</p>
                                    @else
                                        <p class="text-[11px] font-semibold uppercase tracking-wider text-brand-100/40 mb-1.5">Active content by client</p>
                                        <div class="flex flex-wrap gap-2">
                                            @foreach ($row['by_client'] as $clientName => $count)
                                                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-md bg-white/5 ring-1 ring-white/10 text-xs text-brand-100/80">
                                                    {{ $clientName }} <span class="text-white font-semibold">{{ $count }}</span>
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <x-empty-state message="No staff to show." />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>

        <p class="text-xs text-brand-100/40">
            "Overdue" means still in the pipeline (not published) with a shoot date more than {{ $overdueAfterDays }} days past.
            "Delivered" counts everything ever published to this person, not just this month. Click a row for the client breakdown.
        </p>
    </div>
</x-app-layout>
