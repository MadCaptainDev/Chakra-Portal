<x-app-layout title="Contacts">
    <x-slot name="header">
        <x-page-header title="Contacts" eyebrow="WhatsApp CRM"
                       subtitle="Everyone a campaign or a quick reply can reach.">
            <x-slot name="actions">
                <x-btn :href="route('whatsapp-crm.phonebooks.index')" variant="secondary" size="sm">Phonebooks</x-btn>
                <x-btn :href="route('whatsapp-crm.contacts.import.form')" variant="secondary" icon="inbox">Import CSV</x-btn>
                <x-btn :href="route('whatsapp-crm.contacts.create')" icon="plus">New contact</x-btn>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-4">
        <form method="GET" action="{{ route('whatsapp-crm.contacts.index') }}" class="flex flex-wrap items-center gap-3">
            <x-select name="phonebook_id" class="max-w-xs" onchange="this.form.submit()">
                <option value="">All phonebooks</option>
                @foreach ($phonebooks as $phonebook)
                    <option value="{{ $phonebook->id }}" @selected($selectedPhonebookId == $phonebook->id)>{{ $phonebook->name }}</option>
                @endforeach
            </x-select>
            @if ($selectedPhonebookId)
                <a href="{{ route('whatsapp-crm.contacts.index') }}" class="text-sm text-brand-100/70 hover:text-white">Clear filter</a>
            @endif
        </form>

        @if ($contacts->isEmpty())
            <x-empty-state :message="$selectedPhonebookId ? 'No contacts in this phonebook yet.' : 'No contacts yet.'">
                <x-btn :href="route('whatsapp-crm.contacts.create')" icon="plus" size="sm">Add your first contact</x-btn>
            </x-empty-state>
        @else
            {{-- Mobile: card list --}}
            <div class="md:hidden space-y-3">
                @foreach ($contacts as $contact)
                    <div class="bg-white/5 shadow-sm rounded-lg p-4">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-semibold text-white truncate">{{ $contact->name ?: $contact->phone }}</p>
                                @if ($contact->name)
                                    <p class="text-sm text-brand-100/60">{{ $contact->phone }}</p>
                                @endif
                            </div>
                        </div>
                        @if ($contact->phonebooks->isNotEmpty())
                            <div class="mt-2 flex flex-wrap gap-1">
                                @foreach ($contact->phonebooks as $phonebook)
                                    <x-badge color="bg-brand-400/15 text-brand-200">{{ $phonebook->name }}</x-badge>
                                @endforeach
                            </div>
                        @endif
                        <p class="mt-2 text-xs text-brand-100/60">
                            Last interacted: {{ $contact->last_interacted_at?->format('d/m/Y H:i') ?? '—' }}
                        </p>
                        <div class="mt-3 flex items-center gap-4">
                            <a href="{{ route('whatsapp-crm.contacts.edit', $contact) }}" class="text-brand-500 font-semibold text-sm min-h-[44px] flex items-center">Edit</a>
                            <form method="POST" action="{{ route('whatsapp-crm.contacts.destroy', $contact) }}" onsubmit="return confirm('Delete this contact?');">
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
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Phone</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Phonebooks</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Last Interacted</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @foreach ($contacts as $contact)
                            <tr>
                                <td class="px-6 py-4 text-sm font-medium text-white whitespace-nowrap">{{ $contact->phone }}</td>
                                <td class="px-6 py-4 text-sm text-brand-100/60">{{ $contact->name }}</td>
                                <td class="px-6 py-4 text-sm">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($contact->phonebooks as $phonebook)
                                            <x-badge color="bg-brand-400/15 text-brand-200">{{ $phonebook->name }}</x-badge>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-brand-100/60 whitespace-nowrap">{{ $contact->last_interacted_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td class="px-6 py-4 text-right text-sm space-x-3 whitespace-nowrap">
                                    <a href="{{ route('whatsapp-crm.contacts.edit', $contact) }}" class="text-brand-500 hover:text-brand-300 font-semibold">Edit</a>
                                    <form method="POST" action="{{ route('whatsapp-crm.contacts.destroy', $contact) }}" class="inline" onsubmit="return confirm('Delete this contact?');">
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
            {{ $contacts->links() }}
        </div>
    </div>
</x-app-layout>
