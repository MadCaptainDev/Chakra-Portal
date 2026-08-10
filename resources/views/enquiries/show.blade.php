@php
    $replySubject = rawurlencode('Re: your enquiry to Chakra Productions');
@endphp

<x-app-layout title="Enquiry">
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3 min-w-0">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight truncate">
                    {{ $enquiry->name }}
                </h2>
                <x-badge :status="$enquiry->displayStatus()" class="shrink-0" />
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="mailto:{{ $enquiry->email }}?subject={{ $replySubject }}"
                   class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-brand-400 border border-transparent rounded-md font-semibold text-xs text-brand-900 uppercase tracking-widest hover:bg-brand-500">
                    Reply by email
                </a>

                <form method="POST" action="{{ route('enquiries.handled', $enquiry) }}">
                    @csrf
                    @method('PATCH')
                    <button type="submit"
                            class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50">
                        {{ $enquiry->isHandled() ? 'Reopen' : 'Mark handled' }}
                    </button>
                </form>

                <form method="POST" action="{{ route('enquiries.destroy', $enquiry) }}"
                      onsubmit="return confirm('Delete this enquiry? This cannot be undone.');">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                            class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-red-600 uppercase tracking-widest hover:bg-gray-50">
                        Delete
                    </button>
                </form>
            </div>
        </div>
    </x-slot>

    <div class="space-y-4 max-w-3xl">
        <x-card class="p-4 sm:p-6">
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-xs text-gray-500 uppercase">Email</dt>
                    <dd class="mt-0.5">
                        <a href="mailto:{{ $enquiry->email }}" class="font-medium text-brand-600 hover:text-brand-700 break-all">
                            {{ $enquiry->email }}
                        </a>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500 uppercase">Phone</dt>
                    <dd class="mt-0.5 font-medium text-gray-900">
                        @if ($enquiry->phone)
                            <a href="tel:{{ preg_replace('/[^0-9+]/', '', $enquiry->phone) }}" class="text-brand-600 hover:text-brand-700">
                                {{ $enquiry->phone }}
                            </a>
                        @else
                            <span class="text-gray-400">Not given</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500 uppercase">Looking for</dt>
                    <dd class="mt-0.5 font-medium text-gray-900">{{ $enquiry->project ?: 'Not specified' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500 uppercase">Received</dt>
                    <dd class="mt-0.5 font-medium text-gray-900">{{ $enquiry->created_at->format('d/m/Y \a\t H:i') }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500 uppercase">Came from</dt>
                    <dd class="mt-0.5 font-medium text-gray-900">{{ $enquiry->sourceLabel() }}</dd>
                </div>
                @if ($enquiry->prompted_by)
                    <div class="sm:col-span-2">
                        <dt class="text-xs text-gray-500 uppercase">What prompted them</dt>
                        <dd class="mt-0.5 font-medium text-gray-900 break-words">{{ $enquiry->prompted_by }}</dd>
                    </div>
                @endif
            </dl>
        </x-card>

        <x-card class="p-4 sm:p-6">
            <h3 class="font-semibold text-gray-800 mb-3">Message</h3>
            {{-- Plain text from a public form: escaped by Blade, wrapped so a
                 pasted URL cannot blow out the layout. --}}
            <p class="text-sm text-gray-700 leading-relaxed whitespace-pre-line break-words">{{ $enquiry->message }}</p>
        </x-card>

        <div class="flex items-center justify-between text-xs text-gray-400">
            <a href="{{ route('enquiries.index') }}" class="inline-flex items-center min-h-[44px] font-semibold text-brand-500 hover:text-brand-600">
                &larr; All enquiries
            </a>
            @if ($enquiry->ip_address)
                <span>Submitted from {{ $enquiry->ip_address }}</span>
            @endif
        </div>
    </div>
</x-app-layout>
