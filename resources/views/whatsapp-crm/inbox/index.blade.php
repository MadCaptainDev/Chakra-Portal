<x-app-layout title="Inbox">
    <x-slot name="header">
        <x-page-header title="Inbox" eyebrow="WhatsApp CRM"
                       subtitle="Every 2-way conversation the studio has open." />
    </x-slot>

    <div class="space-y-4">
        <form method="GET" action="{{ route('whatsapp-crm.inbox.index') }}" class="flex flex-wrap items-center gap-3">
            <x-text-input type="search" name="q" :value="$search" placeholder="Search name, number or message&hellip;"
                          class="max-w-xs" />
            <x-select name="label" class="max-w-xs" onchange="this.form.submit()">
                <option value="">All labels</option>
                @foreach ($labels as $label)
                    <option value="{{ $label->id }}" @selected($selectedLabelId == $label->id)>{{ $label->name }}</option>
                @endforeach
            </x-select>
            <x-btn type="submit" variant="secondary" size="sm">Search</x-btn>
            @if ($search !== '' || $selectedLabelId)
                <a href="{{ route('whatsapp-crm.inbox.index') }}" class="text-sm text-brand-100/70 hover:text-white">Clear</a>
            @endif
        </form>

        @if ($conversations->isEmpty())
            <x-empty-state message="No conversations yet." />
        @else
            {{-- Mobile: card list --}}
            <div class="md:hidden space-y-3">
                @foreach ($conversations as $conversation)
                    <a href="{{ route('whatsapp-crm.inbox.show', $conversation) }}" class="block bg-white/5 shadow-sm rounded-lg p-4">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-semibold text-white truncate">{{ $conversation->contact?->name ?: $conversation->wa_id }}</p>
                                <p class="text-sm text-brand-100/60 truncate">{{ $conversation->last_message_summary ?? '—' }}</p>
                            </div>
                            @if ($conversation->unread_count > 0)
                                <x-badge status="unread">{{ $conversation->unread_count }}</x-badge>
                            @endif
                        </div>
                        <div class="mt-2 flex flex-wrap items-center gap-1">
                            @foreach ($conversation->labels as $label)
                                <x-badge color="bg-brand-400/15 text-brand-200">{{ $label->name }}</x-badge>
                            @endforeach
                        </div>
                        <p class="mt-2 text-xs text-brand-100/60">{{ $conversation->last_message_at?->format('d M Y, h:i A') ?? '—' }}</p>
                    </a>
                @endforeach
            </div>

            {{-- Desktop: table --}}
            <x-card class="hidden md:block overflow-x-auto">
                <table class="min-w-full divide-y divide-white/10">
                    <thead class="bg-brand-900/40">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Contact</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Last message</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Labels</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">Assigned</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-brand-100/60 uppercase">When</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @foreach ($conversations as $conversation)
                            <tr>
                                <td class="px-6 py-4 text-sm font-medium text-white">
                                    <a href="{{ route('whatsapp-crm.inbox.show', $conversation) }}" class="hover:text-brand-300 flex items-center gap-2">
                                        {{ $conversation->contact?->name ?: $conversation->wa_id }}
                                        @if ($conversation->unread_count > 0)
                                            <x-badge status="unread">{{ $conversation->unread_count }}</x-badge>
                                        @endif
                                    </a>
                                </td>
                                <td class="px-6 py-4 text-sm text-brand-100/60 max-w-xs truncate">{{ $conversation->last_message_summary ?? '—' }}</td>
                                <td class="px-6 py-4 text-sm">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($conversation->labels as $label)
                                            <x-badge color="bg-brand-400/15 text-brand-200">{{ $label->name }}</x-badge>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-brand-100/60 whitespace-nowrap">{{ $conversation->assignedTo?->name ?? '—' }}</td>
                                <td class="px-6 py-4 text-sm text-brand-100/60 whitespace-nowrap">{{ $conversation->last_message_at?->format('d M Y, h:i A') ?? '—' }}</td>
                                <td class="px-6 py-4 text-right text-sm">
                                    <a href="{{ route('whatsapp-crm.inbox.show', $conversation) }}" class="text-brand-500 hover:text-brand-300 font-semibold">Open</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>
        @endif

        <div>
            {{ $conversations->links() }}
        </div>
    </div>
</x-app-layout>
