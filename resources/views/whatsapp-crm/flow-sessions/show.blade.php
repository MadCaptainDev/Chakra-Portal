<x-app-layout title="Flow Session">
    <x-slot name="header">
        <x-page-header :title="$session->wa_id" eyebrow="WhatsApp CRM" :subtitle="$session->flow?->name ?? 'Deleted flow'">
            <x-slot name="actions">
                @if ($session->flow)
                    <x-btn :href="route('whatsapp-crm.flows.edit', $session->flow)" variant="secondary" size="sm">Open flow</x-btn>
                @endif
                <x-btn :href="route('whatsapp-crm.flow-sessions.index')" variant="secondary" size="sm">Back to sessions</x-btn>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <x-card class="p-4 sm:p-6 lg:col-span-2">
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <dt class="text-xs font-medium text-brand-100/60 uppercase">Status</dt>
                    <dd class="mt-1"><x-badge :status="$session->status" /></dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-brand-100/60 uppercase">Current node</dt>
                    <dd class="mt-1 text-white font-mono text-sm">{{ $session->current_node_id ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-brand-100/60 uppercase">Iterations</dt>
                    <dd class="mt-1 text-white">{{ $session->iteration_count }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-brand-100/60 uppercase">Started</dt>
                    <dd class="mt-1 text-white">{{ $session->started_at?->format('d M Y, h:i A') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-brand-100/60 uppercase">Last advanced</dt>
                    <dd class="mt-1 text-white">{{ $session->last_advanced_at?->format('d M Y, h:i A') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-brand-100/60 uppercase">Expires</dt>
                    <dd class="mt-1 text-white">{{ $session->expires_at?->format('d M Y, h:i A') ?? '—' }}</dd>
                </div>
                @if ($session->last_error)
                    <div class="sm:col-span-2">
                        <dt class="text-xs font-medium text-brand-100/60 uppercase">Last error</dt>
                        <dd class="mt-1 text-red-300 text-sm">{{ $session->last_error }}</dd>
                    </div>
                @endif
            </dl>
        </x-card>

        <x-card class="p-4 sm:p-6">
            <p class="text-xs font-medium text-brand-100/60 uppercase mb-2">Variables</p>
            <pre class="text-xs text-brand-100/80 whitespace-pre-wrap break-words bg-black/20 rounded-lg p-3 overflow-x-auto">{{ json_encode($session->variables ?? [], JSON_PRETTY_PRINT) }}</pre>
        </x-card>
    </div>
</x-app-layout>
