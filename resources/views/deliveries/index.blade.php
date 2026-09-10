<x-app-layout title="Deliveries">
    <x-slot name="header">
        <x-page-header title="Deliveries"
                       subtitle="Occasion clients — shoot-only, edit-only, or one-off jobs. The studio hands the video back; no targets, no posting." />
    </x-slot>

    <div class="space-y-5">
        @if ($rows->isEmpty())
            <x-card padding="lg">
                <x-empty-state message="No occasion clients yet. Mark a client 'Occasion' on their record to see them here." />
            </x-card>
        @else
            <x-card padding="none" class="overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-white/10 text-sm">
                        <thead class="bg-white/[0.03]">
                            <tr class="text-left text-[11px] font-semibold uppercase tracking-wider text-brand-100/50">
                                <th class="px-4 py-3">Client</th>
                                <th class="px-4 py-3">In progress</th>
                                <th class="px-4 py-3">Ready to deliver</th>
                                <th class="px-4 py-3">Delivered</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            @foreach ($rows as $row)
                                <tr class="hover:bg-white/[0.03]">
                                    <td class="px-4 py-3">
                                        <a href="{{ route('deliveries.show', $row['client']) }}" class="font-semibold text-white hover:text-brand-300">
                                            {{ $row['client']->name }}
                                        </a>
                                        @if ($row['client']->service_note)
                                            <span class="block text-[11px] text-brand-100/40">{{ $row['client']->service_note }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 tabular-nums text-brand-100/70">{{ $row['in_progress'] }}</td>
                                    <td class="px-4 py-3 tabular-nums">
                                        @if ($row['ready'] > 0)
                                            <span class="text-amber-300 font-semibold">{{ $row['ready'] }}</span>
                                        @else
                                            <span class="text-brand-100/40">0</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 tabular-nums text-brand-100/70">{{ $row['delivered'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-card>
        @endif
    </div>
</x-app-layout>
