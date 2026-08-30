<x-app-layout title="Automations">
    <x-slot name="header">
        <x-page-header title="Automations" eyebrow="WhatsApp CRM"
                       subtitle="Flows FlowEngine walks against inbound messages -- built visually, one node at a time.">
            @can('whatsapp-crm.create')
                <x-slot name="actions">
                    <x-btn :href="route('whatsapp-crm.flow-sessions.index')" variant="secondary" size="sm">Sessions</x-btn>
                    <x-btn :href="route('whatsapp-crm.flows.create')" icon="plus">New automation</x-btn>
                </x-slot>
            @endcan
        </x-page-header>
    </x-slot>

    <div class="space-y-4">
        @if ($flows->isEmpty())
            <x-empty-state message="No automations yet.">
                @can('whatsapp-crm.create')
                    <x-btn :href="route('whatsapp-crm.flows.create')" icon="plus" size="sm">Build your first automation</x-btn>
                @endcan
            </x-empty-state>
        @else
            @php
                $triggerSummary = function ($flow) {
                    return match ($flow->trigger_type) {
                        'keyword' => 'Keyword: '.($flow->trigger_config['keyword'] ?? '—'),
                        'label_applied' => 'Label applied',
                        default => 'Any inbound message',
                    };
                };
            @endphp

            {{-- Mobile: card list --}}
            <div class="md:hidden space-y-3">
                @foreach ($flows as $flow)
                    <div class="bg-white/5 shadow-sm rounded-lg p-4">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-semibold text-white truncate">{{ $flow->name }}</p>
                                <p class="text-sm text-brand-100/60 truncate">{{ $triggerSummary($flow) }}</p>
                            </div>
                            <x-badge :status="$flow->is_active ? 'active' : 'inactive'" />
                        </div>
                        <p class="text-xs text-brand-100/60 mt-2">{{ $flow->sessions_count }} session(s)</p>
                        <div class="mt-3 flex flex-wrap items-center gap-4">
                            <a href="{{ route('whatsapp-crm.flows.edit', $flow) }}" class="text-brand-500 font-semibold text-sm min-h-[44px] flex items-center">Edit</a>
                            @can('whatsapp-crm.edit')
                                @if ($flow->is_active)
                                    <form method="POST" action="{{ route('whatsapp-crm.flows.deactivate', $flow) }}">
                                        @csrf
                                        <button type="submit" class="text-amber-300 font-semibold text-sm min-h-[44px]">Deactivate</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('whatsapp-crm.flows.activate', $flow) }}">
                                        @csrf
                                        <button type="submit" class="text-emerald-300 font-semibold text-sm min-h-[44px]">Activate</button>
                                    </form>
                                @endif
                            @endcan
                            @can('whatsapp-crm.delete')
                                <form method="POST" action="{{ route('whatsapp-crm.flows.destroy', $flow) }}" onsubmit="return confirm('Delete this automation?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-red-300 font-semibold text-sm min-h-[44px]">Delete</button>
                                </form>
                            @endcan
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Desktop: table --}}
            <x-card class="hidden md:block overflow-x-auto">
                <table class="min-w-full divide-y divide-white/10">
                    <thead class="bg-brand-900/40">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Trigger</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Sessions</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @foreach ($flows as $flow)
                            <tr>
                                <td class="px-6 py-4 text-sm font-medium text-white">
                                    <a href="{{ route('whatsapp-crm.flows.edit', $flow) }}" class="hover:text-brand-300">{{ $flow->name }}</a>
                                </td>
                                <td class="px-6 py-4 text-sm text-brand-100/60">{{ $triggerSummary($flow) }}</td>
                                <td class="px-6 py-4 text-sm"><x-badge :status="$flow->is_active ? 'active' : 'inactive'" /></td>
                                <td class="px-6 py-4 text-sm text-brand-100/60">
                                    <a href="{{ route('whatsapp-crm.flow-sessions.index', ['flow' => $flow->id]) }}" class="hover:text-brand-300">{{ $flow->sessions_count }}</a>
                                </td>
                                <td class="px-6 py-4 text-right text-sm space-x-3 whitespace-nowrap">
                                    <a href="{{ route('whatsapp-crm.flows.edit', $flow) }}" class="text-brand-500 hover:text-brand-300 font-semibold">Edit</a>
                                    @can('whatsapp-crm.edit')
                                        @if ($flow->is_active)
                                            <form method="POST" action="{{ route('whatsapp-crm.flows.deactivate', $flow) }}" class="inline">
                                                @csrf
                                                <button type="submit" class="text-amber-300 hover:text-amber-200 font-semibold">Deactivate</button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('whatsapp-crm.flows.activate', $flow) }}" class="inline">
                                                @csrf
                                                <button type="submit" class="text-emerald-300 hover:text-emerald-200 font-semibold">Activate</button>
                                            </form>
                                        @endif
                                    @endcan
                                    @can('whatsapp-crm.delete')
                                        <form method="POST" action="{{ route('whatsapp-crm.flows.destroy', $flow) }}" class="inline" onsubmit="return confirm('Delete this automation?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-300 hover:text-red-200 font-semibold">Delete</button>
                                        </form>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>
        @endif

        <div>
            {{ $flows->links() }}
        </div>
    </div>
</x-app-layout>
