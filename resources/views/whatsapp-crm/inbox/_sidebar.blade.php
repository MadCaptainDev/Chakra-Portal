{{--
    The left pane: search, the All / Unread / Mine filter, labels, and the
    conversation rows themselves (_list), refreshed in place by
    whatsappInboxList in resources/js/whatsapp-inbox.js.
--}}
@php
    $base = array_filter(['q' => $search ?: null, 'label' => $selectedLabelId]);
    $partialUrl = route('whatsapp-crm.inbox.index', array_filter([
        'q' => $search ?: null,
        'filter' => $filter !== 'all' ? $filter : null,
        'label' => $selectedLabelId,
        'page' => $conversations->currentPage() > 1 ? $conversations->currentPage() : null,
        'partial' => 1,
        'active' => $active?->id,
    ]));
@endphp

<div class="flex flex-col min-h-0 h-full"
     x-data="whatsappInboxList({ url: @js($partialUrl) })">
    <div class="p-3 space-y-3 border-b border-white/10">
        <div class="flex items-center justify-between gap-2 px-1">
            <h1 class="text-lg font-semibold text-white">Chats</h1>
            <div class="flex items-center gap-1">
                <a href="{{ route('whatsapp-crm.templates.index') }}" class="inline-flex items-center justify-center w-9 h-9 rounded-lg text-brand-100/60 hover:bg-white/10 hover:text-white" title="Templates" aria-label="Templates">
                    <x-icon name="template" class="w-5 h-5" />
                </a>
                <a href="{{ route('whatsapp-crm.contacts.index') }}" class="inline-flex items-center justify-center w-9 h-9 rounded-lg text-brand-100/60 hover:bg-white/10 hover:text-white" title="Contacts" aria-label="Contacts">
                    <x-icon name="users" class="w-5 h-5" />
                </a>
            </div>
        </div>

        <form method="GET" action="{{ route('whatsapp-crm.inbox.index') }}" role="search"
              x-data x-on:input.debounce.400ms="$el.requestSubmit()">
            @if ($filter !== 'all') <input type="hidden" name="filter" value="{{ $filter }}"> @endif
            @if ($selectedLabelId) <input type="hidden" name="label" value="{{ $selectedLabelId }}"> @endif
            <label class="relative block">
                <span class="sr-only">Search conversations</span>
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-brand-100/40 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z" />
                </svg>
                <input type="search" name="q" value="{{ $search }}" placeholder="Search name, number or message"
                       autocomplete="off" @if ($search !== '') autofocus onfocus="this.setSelectionRange(this.value.length, this.value.length)" @endif
                       class="w-full h-10 pl-9 pr-3 rounded-lg bg-white/5 border-white/10 text-sm text-white placeholder-brand-100/40 focus:bg-white/[0.07] focus:border-brand-400 focus:ring-brand-400" />
            </label>
        </form>

        <div class="flex items-center gap-1.5 overflow-x-auto -mx-1 px-1 pb-0.5">
            @foreach (['all' => 'All', 'unread' => 'Unread', 'mine' => 'Assigned to me'] as $key => $text)
                <a href="{{ route('whatsapp-crm.inbox.index', $base + ($key !== 'all' ? ['filter' => $key] : [])) }}"
                   @class([
                       'shrink-0 inline-flex items-center gap-1.5 h-8 px-3 rounded-full text-xs font-semibold transition',
                       'bg-brand-400 text-brand-900' => $filter === $key,
                       'bg-white/5 text-brand-100/70 hover:bg-white/10 hover:text-white' => $filter !== $key,
                   ])>
                    {{ $text }}
                    @if ($key === 'unread' && $unreadTotal > 0)
                        <span @class(['px-1.5 rounded-full text-[10px]', 'bg-brand-900/20' => $filter === $key, 'bg-brand-400/25 text-brand-200' => $filter !== $key])>{{ $unreadTotal }}</span>
                    @endif
                </a>
            @endforeach

            @if ($labels->isNotEmpty())
                <form method="GET" action="{{ route('whatsapp-crm.inbox.index') }}" class="shrink-0">
                    @if ($search !== '') <input type="hidden" name="q" value="{{ $search }}"> @endif
                    @if ($filter !== 'all') <input type="hidden" name="filter" value="{{ $filter }}"> @endif
                    <select name="label" onchange="this.form.submit()" aria-label="Filter by label"
                            @class([
                                'h-8 pl-3 pr-8 rounded-full border-0 text-xs font-semibold focus:ring-brand-400',
                                'bg-brand-400 text-brand-900' => $selectedLabelId,
                                'bg-white/5 text-brand-100/70' => ! $selectedLabelId,
                            ])>
                        <option value="">All labels</option>
                        @foreach ($labels as $label)
                            <option value="{{ $label->id }}" @selected($selectedLabelId == $label->id)>{{ $label->name }}</option>
                        @endforeach
                    </select>
                </form>
            @endif
        </div>
    </div>

    <div class="flex-1 min-h-0 overflow-y-auto" x-ref="list">
        @include('whatsapp-crm.inbox._list')
    </div>
</div>
