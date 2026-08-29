<x-app-layout title="Phonebooks">
    <x-slot name="header">
        <x-page-header title="Phonebooks" eyebrow="WhatsApp CRM"
                       subtitle="Named contact lists a campaign can be sent to.">
            <x-slot name="actions">
                <x-btn :href="route('whatsapp-crm.phonebooks.create')" icon="plus">New phonebook</x-btn>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-4">
        @if ($phonebooks->isEmpty())
            <x-empty-state message="No phonebooks yet.">
                <x-btn :href="route('whatsapp-crm.phonebooks.create')" icon="plus" size="sm">Create your first phonebook</x-btn>
            </x-empty-state>
        @else
            {{-- Mobile: card list --}}
            <div class="md:hidden space-y-3">
                @foreach ($phonebooks as $phonebook)
                    <div class="bg-white/5 shadow-sm rounded-lg p-4">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-semibold text-white truncate">{{ $phonebook->name }}</p>
                                @if ($phonebook->description)
                                    <p class="text-sm text-brand-100/60 truncate">{{ $phonebook->description }}</p>
                                @endif
                            </div>
                            <a href="{{ route('whatsapp-crm.contacts.index', ['phonebook_id' => $phonebook->id]) }}">
                                <x-badge color="bg-brand-400/15 text-brand-200">{{ $phonebook->contacts_count }} contact{{ $phonebook->contacts_count === 1 ? '' : 's' }}</x-badge>
                            </a>
                        </div>
                        <div class="mt-3 flex items-center gap-4">
                            <a href="{{ route('whatsapp-crm.phonebooks.edit', $phonebook) }}" class="text-brand-500 font-semibold text-sm min-h-[44px] flex items-center">Edit</a>
                            <form method="POST" action="{{ route('whatsapp-crm.phonebooks.destroy', $phonebook) }}" onsubmit="return confirm('Delete this phonebook? Its contacts stay in the CRM.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-300 font-semibold text-sm min-h-[44px]">Delete</button>
                            </form>
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
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Description</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Contacts</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @foreach ($phonebooks as $phonebook)
                            <tr>
                                <td class="px-6 py-4 text-sm font-medium text-white">{{ $phonebook->name }}</td>
                                <td class="px-6 py-4 text-sm text-brand-100/60">{{ $phonebook->description }}</td>
                                <td class="px-6 py-4 text-sm">
                                    <a href="{{ route('whatsapp-crm.contacts.index', ['phonebook_id' => $phonebook->id]) }}">
                                        <x-badge color="bg-brand-400/15 text-brand-200">{{ $phonebook->contacts_count }}</x-badge>
                                    </a>
                                </td>
                                <td class="px-6 py-4 text-right text-sm space-x-3 whitespace-nowrap">
                                    <a href="{{ route('whatsapp-crm.phonebooks.edit', $phonebook) }}" class="text-brand-500 hover:text-brand-300 font-semibold">Edit</a>
                                    <form method="POST" action="{{ route('whatsapp-crm.phonebooks.destroy', $phonebook) }}" class="inline" onsubmit="return confirm('Delete this phonebook? Its contacts stay in the CRM.');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-red-300 hover:text-red-200 font-semibold">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>
        @endif

        <div>
            {{ $phonebooks->links() }}
        </div>
    </div>
</x-app-layout>
