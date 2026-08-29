<x-app-layout title="Campaigns">
    <x-slot name="header">
        <x-page-header title="Campaigns" eyebrow="WhatsApp CRM"
                       subtitle="Broadcasts sent from one approved template to a whole phonebook.">
            @can('whatsapp-crm.create')
                <x-slot name="actions">
                    <x-btn :href="route('whatsapp-crm.campaigns.create')" icon="plus">New campaign</x-btn>
                </x-slot>
            @endcan
        </x-page-header>
    </x-slot>

    <div class="space-y-4">
        @if ($campaigns->isEmpty())
            <x-empty-state message="No campaigns yet.">
                @can('whatsapp-crm.create')
                    <x-btn :href="route('whatsapp-crm.campaigns.create')" icon="plus" size="sm">Create your first campaign</x-btn>
                @endcan
            </x-empty-state>
        @else
            {{-- Mobile: card list --}}
            <div class="md:hidden space-y-3">
                @foreach ($campaigns as $campaign)
                    @php($progress = $campaign->progress())
                    <a href="{{ route('whatsapp-crm.campaigns.show', $campaign) }}" class="block bg-white/5 shadow-sm rounded-lg p-4">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-semibold text-white truncate">{{ $campaign->name }}</p>
                                <p class="text-sm text-brand-100/60 truncate">{{ $campaign->meta_template_name }} &middot; {{ $campaign->phonebook->name }}</p>
                            </div>
                            <x-badge :status="$campaign->status" />
                        </div>
                        <p class="text-xs text-brand-100/60 mt-2">{{ $progress['sent'] + $progress['delivered'] + $progress['read'] + $progress['failed'] }} / {{ $progress['total'] }} processed</p>
                    </a>
                @endforeach
            </div>

            {{-- Desktop: table --}}
            <x-card class="hidden md:block overflow-x-auto">
                <table class="min-w-full divide-y divide-white/10">
                    <thead class="bg-brand-900/40">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Template</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Phonebook</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Progress</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @foreach ($campaigns as $campaign)
                            @php($progress = $campaign->progress())
                            <tr>
                                <td class="px-6 py-4 text-sm font-medium text-white">
                                    <a href="{{ route('whatsapp-crm.campaigns.show', $campaign) }}" class="hover:text-brand-300">{{ $campaign->name }}</a>
                                </td>
                                <td class="px-6 py-4 text-sm text-brand-100/60">{{ $campaign->meta_template_name }}</td>
                                <td class="px-6 py-4 text-sm text-brand-100/60">{{ $campaign->phonebook->name }}</td>
                                <td class="px-6 py-4 text-sm"><x-badge :status="$campaign->status" /></td>
                                <td class="px-6 py-4 text-sm text-brand-100/60">
                                    {{ $progress['sent'] + $progress['delivered'] + $progress['read'] + $progress['failed'] }} / {{ $progress['total'] }}
                                </td>
                                <td class="px-6 py-4 text-right text-sm">
                                    <a href="{{ route('whatsapp-crm.campaigns.show', $campaign) }}" class="text-brand-500 hover:text-brand-300 font-semibold">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>
        @endif

        <div>
            {{ $campaigns->links() }}
        </div>
    </div>
</x-app-layout>
