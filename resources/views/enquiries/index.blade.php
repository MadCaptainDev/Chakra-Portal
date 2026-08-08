@php
    $filters = [
        '' => 'All',
        'unread' => 'New'.($unreadCount ? " ({$unreadCount})" : ''),
        'open' => 'Awaiting reply'.($openCount ? " ({$openCount})" : ''),
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Enquiries">
            <x-slot name="actions">
                <span class="text-sm text-gray-500">
                    {{ $enquiries->total() }} {{ Str::plural('enquiry', $enquiries->total()) }} from the website
                </span>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-4">
        <div class="flex flex-wrap gap-2">
            @foreach ($filters as $value => $label)
                <a href="{{ route('enquiries.index', array_filter(['filter' => $value])) }}"
                   class="px-3 py-1.5 rounded-full text-xs font-semibold {{ $filter === $value ? 'bg-brand-400 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
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
                       class="block bg-white shadow-sm rounded-lg p-4 {{ $enquiry->isUnread() ? 'border-l-4 border-brand-400' : '' }}">
                        <div class="flex items-start justify-between gap-2">
                            <p class="font-semibold text-gray-900 truncate {{ $enquiry->isUnread() ? '' : 'font-medium' }}">
                                {{ $enquiry->name }}
                            </p>
                            <x-badge :status="$enquiry->displayStatus()" class="shrink-0" />
                        </div>
                        <p class="text-sm text-gray-500 truncate mt-0.5">{{ $enquiry->email }}</p>
                        <p class="text-sm text-gray-600 mt-2 line-clamp-2">{{ $enquiry->message }}</p>
                        <p class="text-xs text-gray-400 mt-2">
                            {{ $enquiry->created_at->format('d/m/Y H:i') }}
                            @if ($enquiry->project) &middot; {{ $enquiry->project }} @endif
                        </p>
                    </a>
                @endforeach
            </div>

            {{-- Desktop: table --}}
            <x-card class="hidden md:block overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">From</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Looking for</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Message</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Received</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach ($enquiries as $enquiry)
                            <tr class="hover:bg-gray-50 cursor-pointer"
                                onclick="window.location='{{ route('enquiries.show', $enquiry) }}'">
                                <td class="px-6 py-4 text-sm">
                                    <a href="{{ route('enquiries.show', $enquiry) }}"
                                       class="{{ $enquiry->isUnread() ? 'font-semibold text-gray-900' : 'font-medium text-gray-700' }}">
                                        {{ $enquiry->name }}
                                    </a>
                                    <p class="text-gray-500">{{ $enquiry->email }}</p>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ $enquiry->project ?: '—' }}</td>
                                <td class="px-6 py-4 text-sm text-gray-600 max-w-md">
                                    <span class="line-clamp-2">{{ $enquiry->message }}</span>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500 whitespace-nowrap">
                                    {{ $enquiry->created_at->format('d/m/Y H:i') }}
                                </td>
                                <td class="px-6 py-4 text-sm"><x-badge :status="$enquiry->displayStatus()" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>

            {{ $enquiries->links() }}
        @endif
    </div>
</x-app-layout>
