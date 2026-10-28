{{--
    The conversation rows, on their own so whatsapp-inbox.js can swap in a
    fresh copy (index?partial=1) every few seconds without touching the
    search box or the open thread beside it.
--}}
@php
    $keep = array_filter(['q' => $search ?: null, 'filter' => $filter !== 'all' ? $filter : null, 'label' => $selectedLabelId]);
@endphp

@if ($conversations->isEmpty())
    <div class="px-6 py-12 text-center">
        <x-icon name="inbox" class="mx-auto w-8 h-8 text-brand-100/30" />
        <p class="mt-3 text-sm text-brand-100/60">
            @if ($search !== '' || $filter !== 'all' || $selectedLabelId)
                No conversations match.
            @else
                No conversations yet. They appear here the moment someone messages the studio.
            @endif
        </p>
    </div>
@else
    <ul class="divide-y divide-white/5">
        @foreach ($conversations as $row)
            @php
                $name = $row->contact?->name ?: '+'.$row->wa_id;
                $at = $row->last_message_at;
                $when = match (true) {
                    ! $at => '',
                    $at->isToday() => $at->format('h:i A'),
                    $at->isYesterday() => 'Yesterday',
                    $at->gt(now()->subDays(6)) => $at->format('D'),
                    default => $at->format('d M'),
                };
                $isActive = $active && $active->id === $row->id;
                $unread = $isActive ? 0 : $row->unread_count;
            @endphp
            <li>
                <a href="{{ route('whatsapp-crm.inbox.show', [$row] + $keep) }}"
                   @class([
                       'flex items-start gap-3 px-4 py-3 transition',
                       'bg-brand-400/15 ring-1 ring-inset ring-brand-400/30' => $isActive,
                       'hover:bg-white/5' => ! $isActive,
                   ])
                   @if ($isActive) aria-current="page" @endif>
                    <x-avatar :name="$row->contact?->name ?: $row->wa_id" />
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline justify-between gap-2">
                            <p @class(['truncate text-sm', 'font-semibold text-white' => $unread > 0, 'font-medium text-brand-50' => $unread === 0])>{{ $name }}</p>
                            <span @class(['shrink-0 text-[11px]', 'text-brand-300 font-semibold' => $unread > 0, 'text-brand-100/50' => $unread === 0])>{{ $when }}</span>
                        </div>
                        <div class="mt-0.5 flex items-center justify-between gap-2">
                            <p @class(['truncate text-sm', 'text-brand-100' => $unread > 0, 'text-brand-100/55' => $unread === 0])>{{ $row->last_message_summary ?? 'No messages yet' }}</p>
                            @if ($unread > 0)
                                <span class="shrink-0 min-w-[1.25rem] h-5 px-1.5 rounded-full bg-brand-400 text-brand-900 text-[11px] font-bold leading-5 text-center"
                                      aria-label="{{ $unread }} unread">{{ $unread > 99 ? '99+' : $unread }}</span>
                            @endif
                        </div>
                        @if ($row->labels->isNotEmpty() || $row->assignedTo)
                            <div class="mt-1.5 flex flex-wrap items-center gap-1">
                                @foreach ($row->labels as $label)
                                    <span class="px-1.5 py-0.5 rounded text-[10px] font-medium bg-brand-400/15 text-brand-200">{{ $label->name }}</span>
                                @endforeach
                                @if ($row->assignedTo)
                                    <span class="inline-flex items-center gap-1 text-[10px] text-brand-100/50">
                                        <x-icon name="user" class="w-3 h-3" />{{ $row->assignedTo->name }}
                                    </span>
                                @endif
                            </div>
                        @endif
                    </div>
                </a>
            </li>
        @endforeach
    </ul>

    @if ($conversations->hasPages())
        <div class="px-4 py-3 border-t border-white/5 flex items-center justify-between text-xs">
            @if ($conversations->onFirstPage())
                <span class="text-brand-100/30">Newer</span>
            @else
                <a href="{{ $conversations->previousPageUrl() }}" class="font-semibold text-brand-300 hover:text-white">&larr; Newer</a>
            @endif
            <span class="text-brand-100/50">Page {{ $conversations->currentPage() }} of {{ $conversations->lastPage() }}</span>
            @if ($conversations->hasMorePages())
                <a href="{{ $conversations->nextPageUrl() }}" class="font-semibold text-brand-300 hover:text-white">Older &rarr;</a>
            @else
                <span class="text-brand-100/30">Older</span>
            @endif
        </div>
    @endif
@endif
