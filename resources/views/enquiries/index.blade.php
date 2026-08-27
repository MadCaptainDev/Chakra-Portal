@php
    $filters = [
        '' => 'All',
        'unread' => 'New'.($unreadCount ? " ({$unreadCount})" : ''),
        'open' => 'Awaiting reply'.($openCount ? " ({$openCount})" : ''),
    ];
@endphp

<x-app-layout title="Enquiries">
    <x-slot name="header">
        <x-page-header title="Enquiries" eyebrow="Website inbox"
                       :subtitle="$enquiries->total().' '.Str::plural('enquiry', $enquiries->total()).' received from the site.'" />
    </x-slot>

    <div class="space-y-4">
        <div class="flex flex-wrap gap-2" role="tablist" aria-label="Filter enquiries">
            @foreach ($filters as $value => $label)
                <a href="{{ route('enquiries.index', array_filter(['filter' => $value])) }}"
                   role="tab" @if ($filter === $value) aria-selected="true" @endif
                   class="inline-flex items-center min-h-[44px] px-4 rounded-lg text-sm font-semibold transition
                          {{ $filter === $value
                                ? 'bg-brand-500 text-white shadow-sm'
                                : 'bg-white/5 text-brand-100/70 ring-1 ring-white/15 hover:bg-white/[0.09] hover:text-white' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>

        @if ($enquiries->isEmpty())
            <x-empty-state message="No enquiries{{ $filter ? ' for this filter' : ' yet' }}." />
        @else
            {{-- Mobile: card list --}}
            <div class="md:hidden space-y-3">
                @foreach ($enquiries as $enquiry)
                    <a href="{{ route('enquiries.show', $enquiry) }}"
                       class="block bg-white/5 rounded-xl shadow-sm ring-1 ring-white/10 p-4 transition hover:shadow-md
                              {{ $enquiry->isUnread() ? 'border-l-4 border-brand-400' : '' }}">
                        <div class="flex items-start justify-between gap-2">
                            <p class="truncate {{ $enquiry->isUnread() ? 'font-bold text-white' : 'font-medium text-brand-100/80' }}">
                                {{ $enquiry->name }}
                            </p>
                            <x-badge :status="$enquiry->displayStatus()" class="shrink-0" />
                        </div>
                        <p class="text-sm text-brand-100/60 truncate mt-0.5">{{ $enquiry->email }}</p>
                        <p class="text-sm text-brand-100/70 mt-2 line-clamp-2">{{ $enquiry->message }}</p>
                        <p class="text-xs text-brand-100/50 mt-2">
                            {{ $enquiry->created_at->format('d/m/Y H:i') }}
                            @if ($enquiry->project) &middot; {{ $enquiry->project }} @endif
                            @if ($enquiry->hasSource()) &middot; from {{ $enquiry->sourceLabel() }} @endif
                        </p>
                    </a>
                @endforeach
            </div>

            {{-- Desktop: table --}}
            <x-card class="hidden md:block overflow-hidden">
                <table class="min-w-full">
                    <thead>
                        <tr class="border-b border-white/10 bg-brand-900/40">
                            <th class="px-6 py-3 text-left text-[11px] font-bold text-brand-100/60 uppercase tracking-wider">From</th>
                            <th class="px-6 py-3 text-left text-[11px] font-bold text-brand-100/60 uppercase tracking-wider">Looking for</th>
                            <th class="px-6 py-3 text-left text-[11px] font-bold text-brand-100/60 uppercase tracking-wider">Came from</th>
                            <th class="px-6 py-3 text-left text-[11px] font-bold text-brand-100/60 uppercase tracking-wider">Message</th>
                            <th class="px-6 py-3 text-left text-[11px] font-bold text-brand-100/60 uppercase tracking-wider">Received</th>
                            <th class="px-6 py-3 text-left text-[11px] font-bold text-brand-100/60 uppercase tracking-wider">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        @foreach ($enquiries as $enquiry)
                            <tr class="hover:bg-white/[0.09] transition cursor-pointer {{ $enquiry->isUnread() ? 'bg-white/5' : '' }}"
                                onclick="window.location='{{ route('enquiries.show', $enquiry) }}'">
                                <td class="px-6 py-3.5 text-sm">
                                    <a href="{{ route('enquiries.show', $enquiry) }}"
                                       class="{{ $enquiry->isUnread() ? 'font-bold text-white' : 'font-medium text-brand-100/80' }}">
                                        {{ $enquiry->name }}
                                    </a>
                                    <p class="text-brand-100/60">{{ $enquiry->email }}</p>
                                </td>
                                <td class="px-6 py-3.5 text-sm text-brand-100/60">{{ $enquiry->project ?: '—' }}</td>
                                <td class="px-6 py-3.5 text-sm whitespace-nowrap">
                                    @if ($enquiry->hasSource())
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-white/5 text-brand-200">
                                            {{ $enquiry->sourceLabel() }}
                                        </span>
                                    @else
                                        <span class="text-brand-100/50">—</span>
                                    @endif
                                </td>
                                <td class="px-6 py-3.5 text-sm text-brand-100/70 max-w-md">
                                    <span class="line-clamp-2">{{ $enquiry->message }}</span>
                                </td>
                                <td class="px-6 py-3.5 text-sm text-brand-100/60 whitespace-nowrap">
                                    {{ $enquiry->created_at->format('d/m/Y H:i') }}
                                </td>
                                <td class="px-6 py-3.5 text-sm"><x-badge :status="$enquiry->displayStatus()" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>

            {{ $enquiries->links() }}
        @endif
    </div>
</x-app-layout>
