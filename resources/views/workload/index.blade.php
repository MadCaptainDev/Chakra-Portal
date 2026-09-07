<x-app-layout title="Workload">
    <x-slot name="header">
        <x-page-header title="Workload"
                       subtitle="Who has how much on their plate right now — active shoots, content in the pipeline, and what's overdue." />
    </x-slot>

    <div class="space-y-5">
        <x-card padding="none" class="overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-white/10 text-sm">
                    <thead class="bg-white/[0.03]">
                        <tr class="text-left text-[11px] font-semibold uppercase tracking-wider text-brand-100/50">
                            <th class="px-4 py-3">Staff</th>
                            <th class="px-4 py-3">Active shoots</th>
                            <th class="px-4 py-3">Active content</th>
                            <th class="px-4 py-3">Overdue</th>
                            <th class="px-4 py-3">Delivered</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        @forelse ($rows as $row)
                            <tr class="hover:bg-white/[0.03]">
                                <td class="px-4 py-3 font-semibold text-white">{{ $row['user']->name }}</td>
                                <td class="px-4 py-3 tabular-nums text-brand-100/70">{{ $row['active_shoots'] }}</td>
                                <td class="px-4 py-3 tabular-nums text-brand-100/70">{{ $row['active_content'] }}</td>
                                <td class="px-4 py-3 tabular-nums">
                                    @if ($row['overdue'] > 0)
                                        <span class="text-red-300 font-semibold">{{ $row['overdue'] }}</span>
                                    @else
                                        <span class="text-brand-100/40">0</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 tabular-nums text-brand-100/70">{{ $row['delivered'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5">
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
            "Delivered" counts everything ever published to this person, not just this month.
        </p>
    </div>
</x-app-layout>
