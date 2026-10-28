@php
    $displayName = $conversation->contact?->name ?: '+'.$conversation->wa_id;
    $firstName = $conversation->contact?->name ? str($conversation->contact->name)->explode(' ')->first() : 'This contact';

    $lastId = optional($messages->last())['id'] ?? 0;

    $availableLabels = $labels->reject(fn ($label) => $conversation->labels->contains('id', $label->id));

    $keep = array_filter(['q' => $search ?: null, 'filter' => $filter !== 'all' ? $filter : null, 'label' => $selectedLabelId]);

    // Everything the Alpine component needs, server-rendered once -- the
    // polling script (resources/js/whatsapp-inbox.js) never queries the DOM
    // for what is already on screen.
    $config = [
        'messages' => $messages,
        'lastId' => $lastId,
        'messagesUrl' => route('whatsapp-crm.inbox.messages', $conversation),
        'readUrl' => route('whatsapp-crm.inbox.read', $conversation),
        'replyUrl' => route('whatsapp-crm.inbox.reply', $conversation),
        'templateUrl' => route('whatsapp-crm.inbox.template', $conversation),
        'windowOpen' => $windowOpen,
        'windowClosesAt' => $windowClosesAt?->toIso8601String(),
        'templates' => $templates,
        'quickReplies' => $quickReplies->map(fn ($reply) => ['id' => $reply->id, 'title' => $reply->title, 'content' => $reply->content])->values(),
        'canReply' => auth()->user()->can('whatsapp-crm.edit'),
        // Back from a details-drawer form (assign, label, rename, note):
        // reopen the drawer it was sent from.
        'detailsOpen' => $errors->getBag('note')->any()
            || (session('status') && ! in_array(session('status'), ['Message sent.', 'Template sent.'], true)),
    ];
@endphp

<x-app-layout :title="$displayName">
    <div class="-mx-4 sm:mx-0 -my-6 sm:my-0 h-[calc(100dvh-4rem)] sm:h-[calc(100dvh-7rem)] lg:h-[calc(100dvh-6rem)] flex sm:rounded-2xl overflow-hidden sm:ring-1 sm:ring-white/10 bg-white/[0.03]">
        {{-- Conversations: beside the thread on a wide screen, behind the
             back arrow on a phone. --}}
        <aside class="hidden lg:block lg:w-[22rem] xl:w-96 border-r border-white/10 min-h-0">
            @include('whatsapp-crm.inbox._sidebar')
        </aside>

        <section class="relative flex-1 min-w-0 flex flex-col min-h-0"
                 x-data="whatsappInboxThread(@js($config))">
            {{-- Thread header --}}
            <header class="flex items-center gap-3 px-3 sm:px-4 h-16 shrink-0 border-b border-white/10 bg-brand-900/60">
                <a href="{{ route('whatsapp-crm.inbox.index', $keep) }}"
                   class="lg:hidden -ml-1 inline-flex items-center justify-center w-10 h-10 rounded-lg text-brand-100/70 hover:bg-white/10 hover:text-white"
                   aria-label="Back to chats">
                    <x-icon name="chevron-left" class="w-5 h-5" />
                </a>
                <button type="button" @click="details = true" class="flex items-center gap-3 min-w-0 flex-1 text-left">
                    <x-avatar :name="$conversation->contact?->name ?: $conversation->wa_id" />
                    <span class="min-w-0">
                        <span class="block truncate font-semibold text-white">{{ $displayName }}</span>
                        <span class="block truncate text-xs text-brand-100/60">
                            +{{ $conversation->wa_id }}
                            @if ($conversation->assignedTo) &middot; {{ $conversation->assignedTo->name }} @endif
                        </span>
                    </span>
                </button>

                {{-- The 24-hour window, always in view: it decides what the
                     reply box below can send. --}}
                <span class="hidden sm:inline-flex items-center gap-1.5 px-2.5 h-7 rounded-full text-[11px] font-semibold"
                      :class="windowOpen ? 'bg-emerald-400/15 text-emerald-200' : 'bg-amber-400/15 text-amber-200'">
                    <span class="w-1.5 h-1.5 rounded-full" :class="windowOpen ? 'bg-emerald-300' : 'bg-amber-300'"></span>
                    <span x-text="windowOpen ? `Reply window: ${windowLeft()}` : 'Template only'"></span>
                </span>

                <button type="button" @click="details = ! details"
                        class="inline-flex items-center justify-center w-10 h-10 rounded-lg text-brand-100/70 hover:bg-white/10 hover:text-white"
                        :class="details && 'bg-white/10 text-white'" aria-label="Contact details">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                    </svg>
                </button>
            </header>

            {{-- Messages --}}
            <div x-ref="thread" class="flex-1 min-h-0 overflow-y-auto px-3 sm:px-6 py-4 bg-brand-900/30" @scroll.passive="onScroll()">
                <template x-if="messages.length === 0">
                    <div class="h-full flex items-center justify-center">
                        <p class="text-sm text-brand-100/50 text-center">No messages yet.</p>
                    </div>
                </template>

                <template x-for="(message, index) in messages" :key="message.id">
                    <div>
                        <template x-if="startsDay(index)">
                            <div class="flex justify-center my-3">
                                <span class="px-3 py-1 rounded-full bg-white/10 text-[11px] font-medium text-brand-100/70" x-text="message.day_label"></span>
                            </div>
                        </template>

                        <div class="flex mb-1.5" :class="message.type === 'outgoing' ? 'justify-end' : 'justify-start'">
                            <div class="max-w-[85%] sm:max-w-[70%]">
                                <div class="rounded-2xl px-3 py-2 text-sm shadow-sm"
                                     :class="{
                                         'bg-brand-400/25 text-white rounded-br-md': message.type === 'outgoing' && message.status !== 'failed',
                                         'bg-red-500/15 text-white ring-1 ring-red-400/40 rounded-br-md': message.status === 'failed',
                                         'bg-white/10 text-white rounded-bl-md': message.type !== 'outgoing',
                                     }">
                                    <template x-if="message.kind === 'template'">
                                        <span class="mb-1 inline-flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wider text-brand-200/80">
                                            <x-icon name="template" class="w-3 h-3" /> Template
                                        </span>
                                    </template>
                                    <p class="whitespace-pre-line break-words" x-text="message.summary"></p>
                                    <p class="mt-1 flex items-center justify-end gap-1 text-[10px] text-brand-100/50">
                                        <span x-text="message.time"></span>
                                        <template x-if="message.type === 'outgoing'">
                                            <span class="inline-flex items-center" :title="statusLabel(message)" :aria-label="statusLabel(message)">
                                                {{-- sent: one tick; delivered: two; read: two, lit; failed: alert --}}
                                                <template x-if="message.status === 'sent' || message.status === 'pending'">
                                                    <svg class="w-3.5 h-3.5" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 8.5l3 3 7-7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                                </template>
                                                <template x-if="message.status === 'delivered' || message.status === 'read'">
                                                    <svg class="w-4 h-3.5" viewBox="0 0 20 16" fill="none" stroke="currentColor" stroke-width="1.6" :class="message.status === 'read' && 'text-sky-300'"><path d="M1.5 8.5l3 3 7-7M8 11.5l7-7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                                </template>
                                                <template x-if="message.status === 'failed'">
                                                    <x-icon name="alert" class="w-3.5 h-3.5 text-red-300" />
                                                </template>
                                            </span>
                                        </template>
                                    </p>
                                </div>
                                <template x-if="message.status === 'failed'">
                                    <div class="mt-1 text-right">
                                        <p class="text-[11px] text-red-300" x-text="message.error || 'Not delivered.'"></p>
                                        <template x-if="canReply && templates.length > 0">
                                            <button type="button" @click="openTemplates()" class="mt-0.5 text-[11px] font-semibold text-brand-300 hover:text-white">Send a template instead &rarr;</button>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <button type="button" x-show="!atBottom" x-cloak @click="scrollToBottom(true)"
                    class="absolute right-4 bottom-36 w-10 h-10 rounded-full bg-brand-800 ring-1 ring-white/15 text-white shadow-lg flex items-center justify-center"
                    aria-label="Jump to latest">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
            </button>

            {{-- Composer --}}
            <footer class="shrink-0 border-t border-white/10 bg-brand-900/60 p-3 sm:p-4">
                @can('whatsapp-crm.edit')
                    {{-- Window closed: say so before anyone types a word. --}}
                    <div x-show="!windowOpen && !picking" x-cloak class="rounded-xl bg-amber-400/10 ring-1 ring-amber-400/30 p-3 sm:p-4">
                        <div class="flex items-start gap-3">
                            <x-icon name="clock" class="w-5 h-5 shrink-0 text-amber-300 mt-0.5" />
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold text-amber-100">Only a template can be sent now</p>
                                <p class="mt-0.5 text-xs text-amber-100/70">
                                    @if ($windowClosesAt)
                                        {{ $firstName }} last messaged {{ $windowClosesAt->copy()->subDay()->diffForHumans() }}.
                                    @else
                                        {{ $firstName }} has never messaged the studio.
                                    @endif
                                    WhatsApp only delivers free text within 24 hours of their last message &mdash; a typed reply would fail.
                                    Once they reply, you can chat freely again.
                                </p>
                            </div>
                        </div>
                        <div class="mt-3 flex justify-end">
                            <template x-if="templates.length > 0">
                                <x-btn type="button" icon="template" size="sm" x-on:click="openTemplates()">Choose a template</x-btn>
                            </template>
                            <template x-if="templates.length === 0">
                                <p class="text-xs text-amber-100/70">
                                    No approved templates could be loaded.
                                    <a href="{{ route('whatsapp-crm.templates.index') }}" class="font-semibold underline hover:text-white">Open Templates</a>
                                </p>
                            </template>
                        </div>
                    </div>

                    {{-- Template picker --}}
                    <div x-show="picking" x-cloak class="rounded-xl bg-white/5 ring-1 ring-white/10">
                        <div class="flex items-center justify-between gap-2 px-3 py-2 border-b border-white/10">
                            <button type="button" x-show="chosen" @click="chosen = null" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-200 hover:text-white">
                                <x-icon name="chevron-left" class="w-4 h-4" /> All templates
                            </button>
                            <p x-show="!chosen" class="text-xs font-semibold uppercase tracking-wider text-brand-100/60">Send a template</p>
                            <button type="button" @click="closeTemplates()" class="w-8 h-8 -mr-1 inline-flex items-center justify-center rounded-lg text-brand-100/60 hover:bg-white/10 hover:text-white" aria-label="Close templates">&times;</button>
                        </div>

                        <ul x-show="!chosen" class="max-h-64 overflow-y-auto divide-y divide-white/5">
                            <template x-for="template in templates" :key="template.name">
                                <li>
                                    <button type="button" @click="choose(template)" class="w-full text-left px-3 py-2.5 hover:bg-white/5">
                                        <span class="block text-sm font-medium text-white" x-text="template.label"></span>
                                        <span class="block text-xs text-brand-100/55 line-clamp-2" x-text="template.body"></span>
                                    </button>
                                </li>
                            </template>
                        </ul>

                        <form x-show="chosen" @submit.prevent="sendTemplate()" class="p-3 space-y-3">
                            <div class="rounded-xl bg-brand-400/15 px-3 py-2 text-sm text-white whitespace-pre-line break-words max-h-40 overflow-y-auto" x-text="preview()"></div>
                            <template x-for="(value, i) in params" :key="i">
                                <label class="block">
                                    <span class="text-[11px] font-semibold text-brand-100/60" x-text="`Blank ${i + 1}`"></span>
                                    <input type="text" x-model="params[i]" required maxlength="1024"
                                           class="mt-0.5 w-full h-10 rounded-lg bg-white/5 border-white/15 text-sm text-white placeholder-brand-100/40 focus:border-brand-400 focus:ring-brand-400" />
                                </label>
                            </template>
                            <template x-if="chosen && chosen.has_url_button">
                                <label class="block">
                                    <span class="text-[11px] font-semibold text-brand-100/60">End of the button's link</span>
                                    <span class="block text-[11px] text-brand-100/40 break-all" x-text="chosen.button_url"></span>
                                    <input type="text" x-model="buttonParam" required
                                           class="mt-0.5 w-full h-10 rounded-lg bg-white/5 border-white/15 text-sm text-white focus:border-brand-400 focus:ring-brand-400" />
                                </label>
                            </template>
                            <p x-show="error" x-text="error" class="text-sm text-red-300"></p>
                            <div class="flex justify-end">
                                <x-btn type="submit" icon="chat" size="sm" x-bind:disabled="sending">
                                    <span x-text="sending ? 'Sending…' : 'Send template'"></span>
                                </x-btn>
                            </div>
                        </form>
                    </div>

                    {{-- Free text, while the window is open. A plain form post
                         still works if the script never loads. --}}
                    <form x-show="windowOpen && !picking" method="POST" action="{{ route('whatsapp-crm.inbox.reply', $conversation) }}"
                          @submit.prevent="sendText()" @if (! $windowOpen) style="display: none" @endif>
                        @csrf
                        <div x-show="showQuick" x-cloak class="mb-2 rounded-xl bg-white/5 ring-1 ring-white/10 max-h-52 overflow-y-auto divide-y divide-white/5">
                            <template x-if="quickReplies.length === 0">
                                <p class="px-3 py-3 text-xs text-brand-100/60">No saved replies yet. <a href="{{ route('whatsapp-crm.quick-replies.create') }}" class="font-semibold text-brand-300 hover:text-white">Add one</a></p>
                            </template>
                            <template x-for="reply in quickReplies" :key="reply.id">
                                <button type="button" @click="useQuick(reply)" class="w-full text-left px-3 py-2 hover:bg-white/5">
                                    <span class="block text-sm font-medium text-white" x-text="reply.title"></span>
                                    <span class="block text-xs text-brand-100/55 truncate" x-text="reply.content"></span>
                                </button>
                            </template>
                        </div>

                        <div class="flex items-end gap-2">
                            <div class="flex shrink-0">
                                <button type="button" @click="showQuick = ! showQuick" :class="showQuick && 'bg-white/10 text-white'"
                                        class="w-11 h-11 inline-flex items-center justify-center rounded-lg text-brand-100/60 hover:bg-white/10 hover:text-white"
                                        title="Saved replies" aria-label="Saved replies">
                                    <x-icon name="sparkles" class="w-5 h-5" />
                                </button>
                                <button type="button" @click="openTemplates()" x-show="templates.length > 0"
                                        class="w-11 h-11 inline-flex items-center justify-center rounded-lg text-brand-100/60 hover:bg-white/10 hover:text-white"
                                        title="Send a template" aria-label="Send a template">
                                    <x-icon name="template" class="w-5 h-5" />
                                </button>
                            </div>
                            <textarea name="body" x-ref="body" x-model="body" rows="1" required maxlength="4096"
                                      @input="grow()" @keydown.enter="onEnter($event)"
                                      placeholder="Type a message"
                                      class="flex-1 min-h-[44px] max-h-40 resize-none rounded-2xl bg-white/5 border-white/15 py-2.5 text-sm text-white placeholder-brand-100/40 focus:bg-white/[0.07] focus:border-brand-400 focus:ring-brand-400"></textarea>
                            <button type="submit" :disabled="sending || body.trim() === ''"
                                    class="shrink-0 w-11 h-11 inline-flex items-center justify-center rounded-full bg-brand-400 text-brand-900 hover:bg-brand-500 disabled:opacity-40 transition"
                                    aria-label="Send">
                                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3.4 20.4l17.45-7.48a1 1 0 000-1.84L3.4 3.6a.99.99 0 00-1.39.91L2 9.12c0 .5.37.93.87.99L17 12 2.87 13.88c-.5.07-.87.5-.87 1l.01 4.61c0 .71.73 1.2 1.39.91z"/></svg>
                            </button>
                        </div>
                        <div class="mt-1.5 flex items-center justify-between gap-2 px-1 text-[11px]">
                            <p x-show="error" x-text="error" class="text-red-300"></p>
                            <x-input-error :messages="$errors->get('body')" />
                            <p x-show="!error" class="text-brand-100/40">
                                <span class="sm:hidden" x-text="`Free text for ${windowLeft()} more`"></span>
                                <span class="hidden sm:inline">Enter to send &middot; Shift+Enter for a new line &middot; free text allowed for <span x-text="windowLeft()"></span> more</span>
                            </p>
                        </div>
                    </form>
                @else
                    <p class="text-sm text-center text-brand-100/50 py-2">You can read this conversation but not reply to it.</p>
                @endcan
            </footer>

            {{-- Details: contact, assignee, labels, notes. A drawer over the
                 thread so the thread itself keeps the full width. --}}
            <div x-show="details" x-cloak class="absolute inset-0 z-20 flex justify-end">
                <div class="absolute inset-0 bg-brand-900/60" @click="details = false" x-transition.opacity></div>
                <aside class="relative w-full sm:w-96 h-full overflow-y-auto bg-brand-900 border-l border-white/10 shadow-2xl"
                       x-transition:enter="transition ease-out duration-150" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
                       @keydown.escape.window="details = false">
                    <div class="sticky top-0 z-10 flex items-center justify-between h-16 px-4 border-b border-white/10 bg-brand-900">
                        <p class="font-semibold text-white">Contact details</p>
                        <button type="button" @click="details = false" class="w-10 h-10 -mr-2 inline-flex items-center justify-center rounded-lg text-brand-100/70 hover:bg-white/10 hover:text-white" aria-label="Close details">&times;</button>
                    </div>

                    <div class="p-4 space-y-6">
                        <div class="text-center">
                            <x-avatar :name="$conversation->contact?->name ?: $conversation->wa_id" size="lg" class="mx-auto" />
                            <p class="mt-2 font-semibold text-white">{{ $displayName }}</p>
                            <a href="https://wa.me/{{ $conversation->wa_id }}" target="_blank" rel="noopener" class="text-xs text-brand-300 hover:text-white">+{{ $conversation->wa_id }}</a>
                            <p class="mt-2">
                                @if ($windowOpen)
                                    <x-badge color="bg-emerald-400/15 text-emerald-200">Can reply freely until {{ $windowClosesAt->format('d M, h:i A') }}</x-badge>
                                @else
                                    <x-badge color="bg-amber-400/15 text-amber-200">Template only</x-badge>
                                @endif
                            </p>
                        </div>

                        @can('whatsapp-crm.edit')
                            <div>
                                <p class="text-[11px] font-semibold text-brand-100/60 uppercase tracking-wider">Name</p>
                                <form method="POST" action="{{ route('whatsapp-crm.inbox.contact.update', $conversation) }}" class="mt-1 flex gap-2">
                                    @csrf
                                    <x-text-input name="name" class="flex-1" placeholder="Add a name&hellip;"
                                                  value="{{ $conversation->contact?->name }}" required />
                                    <x-btn type="submit" variant="secondary" size="sm">Save</x-btn>
                                </form>
                            </div>
                        @endcan

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

                        <div class="space-y-3">
                            <p class="text-[11px] font-semibold text-brand-100/60 uppercase tracking-wider">Team notes</p>

                            @can('whatsapp-crm.create')
                                <form method="POST" action="{{ route('whatsapp-crm.inbox.notes.store', $conversation) }}" class="space-y-2">
                                    @csrf
                                    <x-textarea name="body" rows="2" placeholder="Only the team sees notes&hellip;" required></x-textarea>
                                    <x-input-error :messages="$errors->getBag('note')->get('body')" />
                                    <div class="flex justify-end">
                                        <x-btn type="submit" variant="secondary" size="sm">Add note</x-btn>
                                    </div>
                                </form>
                            @endcan

                            @forelse ($conversation->notes->sortByDesc('created_at') as $note)
                                <div class="bg-amber-400/[0.07] ring-1 ring-amber-400/15 rounded-lg p-3">
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
                    </div>
                </aside>
            </div>
        </section>
    </div>
</x-app-layout>
