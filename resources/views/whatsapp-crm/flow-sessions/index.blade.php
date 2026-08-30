<x-app-layout title="Flow Sessions">
    <x-slot name="header">
        <x-page-header title="Sessions" eyebrow="WhatsApp CRM"
                       subtitle="Every walk through an automation, live or finished -- the &quot;why did this contact get stuck&quot; screen.">
            <x-slot name="actions">
                <x-btn :href="route('whatsapp-crm.flows.index')" variant="secondary" size="sm">Back to automations</x-btn>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-4">
        <x-card class="p-3 sm:p-4">
            <form method="GET" action="{{ route('whatsapp-crm.flow-sessions.index') }}" class="flex flex-wrap items-end gap-3">
                <div>
                    <x-input-label for="flow" value="Flow" />
                    <x-select id="flow" name="flow" class="mt-1">
                        <option value="">All flows</option>
                        @foreach ($flows as $flowOption)
                            <option value="{{ $flowOption->id }}" @selected((string) $selectedFlowId === (string) $flowOption->id)>{{ $flowOption->name }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div>
                    <x-input-label for="status" value="Status" />
                    <x-select id="status" name="status" class="mt-1">
                        <option value="">Any status</option>
                        @foreach (['active', 'completed', 'failed', 'expired'] as $statusOption)
                            <option value="{{ $statusOption }}" @selected($selectedStatus === $statusOption)>{{ ucfirst($statusOption) }}</option>
                        @endforeach
                    </x-select>
                </div>
                <x-btn type="submit" variant="secondary" size="sm">Filter</x-btn>
                @if ($selectedFlowId || $selectedStatus)
                    <a href="{{ route('whatsapp-crm.flow-sessions.index') }}" class="text-sm text-brand-100/70 hover:text-white">Clear</a>
                @endif
            </form>
        </x-card>

        @if ($sessions->isEmpty())
            <x-empty-state message="No sessions yet." />
        @else
            {{-- Mobile: card list --}}
            <div class="md:hidden space-y-3">
                @foreach ($sessions as $session)
                    <a href="{{ route('whatsapp-crm.flow-sessions.show', $session) }}" class="block bg-white/5 shadow-sm rounded-lg p-4">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-semibold text-white truncate">{{ $session->flow?->name ?? 'Deleted flow' }}</p>
                                <p class="text-sm text-brand-100/60 truncate">{{ $session->wa_id }}</p>
                            </div>
                            <x-badge :status="$session->status" />
                        </div>
                        <p class="text-xs text-brand-100/60 mt-2">Node: {{ $session->current_node_id ?? '—' }} &middot; {{ $session->iteration_count }} step(s)</p>
                    </a>
                @endforeach
            </div>

            {{-- Desktop: table --}}
            <x-card class="hidden md:block overflow-x-auto">
                <table class="min-w-full divide-y divide-white/10">
                    <thead class="bg-brand-900/40">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Flow</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Contact</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Node</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Steps</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Last advanced</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @foreach ($sessions as $session)
                            <tr>
                                <td class="px-6 py-4 text-sm font-medium text-white">{{ $session->flow?->name ?? 'Deleted flow' }}</td>
                                <td class="px-6 py-4 text-sm text-brand-100/60">{{ $session->wa_id }}</td>
                                <td class="px-6 py-4 text-sm text-brand-100/60">{{ $session->current_node_id ?? '—' }}</td>
                                <td class="px-6 py-4 text-sm"><x-badge :status="$session->status" /></td>
                                <td class="px-6 py-4 text-sm text-brand-100/60">{{ $session->iteration_count }}</td>
                                <td class="px-6 py-4 text-sm text-brand-100/60">{{ $session->last_advanced_at?->format('d M Y, h:i A') ?? '—' }}</td>
                                <td class="px-6 py-4 text-right text-sm">
                                    <a href="{{ route('whatsapp-crm.flow-sessions.show', $session) }}" class="text-brand-500 hover:text-brand-300 font-semibold">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>
        @endif

        <div>
            {{ $sessions->links() }}
        </div>
    </div>
</x-app-layout>
