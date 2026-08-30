<x-app-layout title="Templates">
    <x-slot name="header">
        <x-page-header title="Templates" eyebrow="WhatsApp CRM"
                       subtitle="Message templates Meta has approved for this account.">
            @if ($canSend)
                <x-slot name="actions">
                    <form method="POST" action="{{ route('whatsapp-crm.templates.refresh') }}">
                        @csrf
                        <x-btn type="submit" variant="secondary" icon="refresh">Refresh from Meta</x-btn>
                    </form>
                    <x-btn :href="route('whatsapp-crm.templates.create')" icon="plus">New Template</x-btn>
                </x-slot>
            @endif
        </x-page-header>
    </x-slot>

    <div class="space-y-4">
        @if (! $canSend)
            <x-empty-state message="Connect WhatsApp first to see your approved templates.">
                <x-btn :href="route('whatsapp.edit')" size="sm">Connect WhatsApp</x-btn>
            </x-empty-state>
        @elseif (empty($templates))
            <x-empty-state message="No approved templates yet. Create and submit one in Meta Business Manager, then refresh here.">
                <form method="POST" action="{{ route('whatsapp-crm.templates.refresh') }}">
                    @csrf
                    <x-btn type="submit" icon="refresh" size="sm">Refresh from Meta</x-btn>
                </form>
            </x-empty-state>
        @else
            {{-- Mobile: card list --}}
            <div class="md:hidden space-y-3">
                @foreach ($templates as $template)
                    <div class="bg-white/5 shadow-sm rounded-lg p-4">
                        <div class="flex items-start justify-between gap-2">
                            <p class="font-semibold text-white truncate">{{ $template['name'] ?? '—' }}</p>
                            <x-badge :status="$template['status'] ?? null" />
                        </div>
                        <p class="text-sm text-brand-100/60 mt-1">
                            {{ $template['language'] ?? '—' }} &middot; {{ $template['category'] ?? '—' }}
                        </p>
                    </div>
                @endforeach
            </div>

            {{-- Desktop: table --}}
            <x-card class="hidden md:block overflow-x-auto">
                <table class="min-w-full divide-y divide-white/10">
                    <thead class="bg-brand-900/40">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Language</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Category</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @foreach ($templates as $template)
                            <tr>
                                <td class="px-6 py-4 text-sm font-medium text-white">{{ $template['name'] ?? '—' }}</td>
                                <td class="px-6 py-4 text-sm text-brand-100/60">{{ $template['language'] ?? '—' }}</td>
                                <td class="px-6 py-4 text-sm text-brand-100/60">{{ $template['category'] ?? '—' }}</td>
                                <td class="px-6 py-4 text-sm"><x-badge :status="$template['status'] ?? null" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>
        @endif
    </div>
</x-app-layout>
