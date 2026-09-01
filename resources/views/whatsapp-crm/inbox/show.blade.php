@php
    // Handed to Alpine as plain data -- the polling script (resources/js/
    // whatsapp-inbox.js) never queries the DOM for what is already rendered.
    $initialMessages = $messages->map(fn ($event) => [
        'id' => $event->id,
        'type' => $event->type,
        'summary' => $event->summary,
        'time' => $event->occurred_at?->format('h:i A'),
    ])->values();

    $lastId = optional($messages->last())->id ?? 0;

    $availableLabels = $labels->reject(fn ($label) => $conversation->labels->contains('id', $label->id));
@endphp

<x-app-layout :title="$conversation->contact?->name ?: $conversation->wa_id">
    <x-slot name="header">
        <x-page-header :title="$conversation->contact?->name ?: $conversation->wa_id" eyebrow="WhatsApp CRM"
                       :subtitle="$conversation->wa_id">
            <x-slot name="actions">
                <x-btn :href="route('whatsapp-crm.inbox.index')" variant="secondary" size="sm">Back to inbox</x-btn>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4"
         x-data="whatsappInboxThread({
             messages: @js($initialMessages),
             lastId: {{ $lastId }},
             messagesUrl: '{{ route('whatsapp-crm.inbox.messages', $conversation) }}',
             readUrl: '{{ route('whatsapp-crm.inbox.read', $conversation) }}',
         })">
        {{-- Thread --}}
        <div class="lg:col-span-2">
            <x-card padding="none" class="flex flex-col h-[65vh]">
                <div x-ref="thread" class="flex-1 overflow-y-auto p-4 space-y-3">
                    <template x-if="messages.length === 0">
                        <p class="text-sm text-brand-100/50 text-center py-8">No messages yet.</p>
                    </template>
                    <template x-for="message in messages" :key="message.id">
                        <div class="flex" :class="message.type === 'outgoing' ? 'justify-end' : 'justify-start'">
                            <div class="max-w-[75%] rounded-lg px-3 py-2 text-sm"
                                 :class="message.type === 'outgoing' ? 'bg-brand-400/20 text-white' : 'bg-white/10 text-white'">
                                <p class="whitespace-pre-line" x-text="message.summary"></p>
                                <p class="mt-1 text-[10px] text-brand-100/50" x-text="message.time"></p>
                            </div>
                        </div>
                    </template>
                </div>

                <div class="border-t border-white/10 p-4">
                    @can('whatsapp-crm.edit')
                        <form method="POST" action="{{ route('whatsapp-crm.inbox.reply', $conversation) }}" class="space-y-2">
                            @csrf
                            <x-textarea name="body" rows="2" placeholder="Type a message&hellip;" required></x-textarea>
                            <x-input-error :messages="$errors->get('body')" />
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-xs text-brand-100/50">Free text only reaches someone who messaged within the last 24 hours -- outside that window, send a template from Templates instead.</p>
                                <x-btn type="submit" icon="chat" size="sm">Send</x-btn>
                            </div>
                        </form>
                    @else
                        <p class="text-sm text-brand-100/50">You do not have permission to reply.</p>
                    @endcan
                </div>
            </x-card>
        </div>

        {{-- Sidebar --}}
        <div class="space-y-4">
            <x-card class="p-4 sm:p-6 space-y-4">
                <div>
                    <p class="text-[11px] font-semibold text-brand-100/60 uppercase tracking-wider">Contact</p>
                    <p class="mt-1 text-xs text-brand-100/50">{{ $conversation->wa_id }}</p>
                    @can('whatsapp-crm.edit')
                        <form method="POST" action="{{ route('whatsapp-crm.inbox.contact.update', $conversation) }}" class="mt-2 flex gap-2">
                            @csrf
                            <x-text-input name="name" class="flex-1" placeholder="Add a name&hellip;"
                                          value="{{ $conversation->contact?->name }}" required />
                            <x-btn type="submit" variant="secondary" size="sm">Save</x-btn>
                        </form>
                    @endcan
                </div>
            </x-card>

            <x-card class="p-4 sm:p-6 space-y-4">
                <div>
                    <p class="text-[11px] font-semibold text-brand-100/60 uppercase tracking-wider">Assigned to</p>
                    @can('whatsapp-crm.edit')
                        <form method="POST" action="{{ route('whatsapp-crm.inbox.assign', $conversation) }}" class="mt-1">
                            @csrf
                            <x-select name="assigned_to_id" onchange="this.form.submit()">
                                <option value="">Unassigned</option>
                                @foreach ($assignableUsers as $user)
                                    <option value="{{ $user->id }}" @selected($conversation->assigned_to_id === $user->id)>{{ $user->name }}</option>
                                @endforeach
                            </x-select>
                        </form>
                    @else
                        <p class="mt-1 text-white">{{ $conversation->assignedTo?->name ?? 'Unassigned' }}</p>
                    @endcan
                </div>

                <div>
                    <p class="text-[11px] font-semibold text-brand-100/60 uppercase tracking-wider">Labels</p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @forelse ($conversation->labels as $label)
                            <span class="inline-flex items-center gap-1">
                                <x-badge color="bg-brand-400/15 text-brand-200">{{ $label->name }}</x-badge>
                                @can('whatsapp-crm.edit')
                                    <form method="POST" action="{{ route('whatsapp-crm.inbox.labels.detach', [$conversation, $label]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-brand-100/40 hover:text-red-300 leading-none" aria-label="Remove {{ $label->name }}">&times;</button>
                                    </form>
                                @endcan
                            </span>
                        @empty
                            <p class="text-sm text-brand-100/50">No labels.</p>
                        @endforelse
                    </div>

                    @can('whatsapp-crm.edit')
                        @if ($availableLabels->isNotEmpty())
                            <form method="POST" class="mt-2 flex gap-2" x-data="{ url: '' }">
                                @csrf
                                <x-select x-model="url" class="text-sm">
                                    <option value="">Add label&hellip;</option>
                                    @foreach ($availableLabels as $label)
                                        <option value="{{ route('whatsapp-crm.inbox.labels.attach', [$conversation, $label]) }}">{{ $label->name }}</option>
                                    @endforeach
                                </x-select>
                                <x-btn type="submit" variant="secondary" size="sm" x-bind:formaction="url" x-bind:disabled="!url">Add</x-btn>
                            </form>
                        @endif
                    @endcan
                </div>
            </x-card>

            <x-card class="p-4 sm:p-6 space-y-3">
                <p class="text-[11px] font-semibold text-brand-100/60 uppercase tracking-wider">Notes</p>

                <div class="space-y-3 max-h-64 overflow-y-auto">
                    @forelse ($conversation->notes->sortByDesc('created_at') as $note)
                        <div class="bg-white/5 rounded-lg p-3">
                            <p class="text-sm text-white whitespace-pre-line">{{ $note->body }}</p>
                            <div class="mt-1 flex items-center justify-between text-xs text-brand-100/50">
                                <span>{{ $note->author?->name ?? 'Someone' }} &middot; {{ $note->created_at->format('d M, h:i A') }}</span>
                                @can('whatsapp-crm.delete')
                                    <form method="POST" action="{{ route('whatsapp-crm.inbox.notes.destroy', [$conversation, $note]) }}"
                                          onsubmit="return confirm('Delete this note?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-red-300 hover:text-red-200">Delete</button>
                                    </form>
                                @endcan
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-brand-100/50">No notes yet.</p>
                    @endforelse
                </div>

                @can('whatsapp-crm.create')
                    <form method="POST" action="{{ route('whatsapp-crm.inbox.notes.store', $conversation) }}" class="space-y-2">
                        @csrf
                        <x-textarea name="body" rows="2" placeholder="Leave a note for the team&hellip;" required></x-textarea>
                        <x-input-error :messages="$errors->getBag('note')->get('body')" />
                        <div class="flex justify-end">
                            <x-btn type="submit" variant="secondary" size="sm">Add note</x-btn>
                        </div>
                    </form>
                @endcan
            </x-card>
        </div>
    </div>
</x-app-layout>
