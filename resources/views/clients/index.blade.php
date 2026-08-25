<x-app-layout title="Clients">
    <x-slot name="header">
        <x-page-header title="Clients" eyebrow="Clients"
                       subtitle="Studios, brands, and the briefs attached to them.">
            <x-slot name="actions">
                <x-btn :href="route('clients.create')" icon="plus">Add client</x-btn>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-4">
        @if ($clients->isEmpty())
            <x-empty-state message="No clients yet.">
                <x-btn :href="route('clients.create')" icon="plus" size="sm">Add your first client</x-btn>
            </x-empty-state>
        @else
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
                @foreach ($clients as $client)
                    <article class="group bg-white rounded-xl ring-1 ring-gray-200/80 hover:ring-brand-300 hover:shadow-md transition-all duration-200 overflow-hidden flex flex-col">
                        <a href="{{ route('clients.show', $client) }}" class="flex-1 p-5 min-h-[44px] block focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 focus-visible:ring-inset">
                            <div class="flex items-start gap-4">
                                <div class="w-14 h-14 shrink-0 rounded-xl bg-brand-50 ring-1 ring-gray-100 flex items-center justify-center overflow-hidden">
                                    @if ($client->logoUrl())
                                        <img src="{{ $client->logoUrl() }}" alt="" loading="lazy"
                                             class="w-full h-full object-contain p-1.5">
                                    @else
                                        <span class="text-lg font-bold text-brand-600 tabular-nums">
                                            {{ mb_strtoupper(mb_substr($client->name, 0, 1)) }}
                                        </span>
                                    @endif
                                </div>

                                <div class="min-w-0 flex-1">
                                    <div class="flex items-start justify-between gap-2">
                                        <h2 class="font-semibold text-gray-900 group-hover:text-brand-700 truncate leading-snug">
                                            {{ $client->name }}
                                        </h2>
                                        @if ($client->brief?->isSubmitted())
                                            <x-badge color="bg-green-100 text-green-800">Done</x-badge>
                                        @elseif ($client->brief)
                                            <x-badge color="bg-amber-100 text-amber-800">{{ $client->brief->requiredAnswered() }}/{{ $client->brief->requiredTotal() }}</x-badge>
                                        @endif
                                    </div>

                                    @if ($client->address)
                                        <p class="mt-1.5 text-sm text-gray-500 line-clamp-2">{{ $client->address }}</p>
                                    @endif

                                    <div class="mt-3 space-y-1">
                                        @if ($client->email)
                                            <p class="text-sm text-gray-600 truncate">{{ $client->email }}</p>
                                        @endif
                                        @if ($client->phone)
                                            <p class="text-sm text-gray-600">{{ $client->phone }}</p>
                                        @endif
                                        @unless ($client->email || $client->phone || $client->address)
                                            <p class="text-sm text-gray-400">No contact details</p>
                                        @endunless
                                    </div>
                                </div>
                            </div>
                        </a>

                        <div class="px-5 py-3 border-t border-gray-100 bg-gray-50/60 flex items-center justify-end gap-1">
                            <a href="{{ route('clients.edit', $client) }}"
                               class="inline-flex items-center min-h-[44px] px-3 text-sm font-semibold text-brand-600 hover:text-brand-800">
                                Edit
                            </a>
                            <form method="POST" action="{{ route('clients.destroy', $client) }}"
                                  onsubmit="return confirm('Delete this client?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit"
                                        class="inline-flex items-center min-h-[44px] px-3 text-sm font-semibold text-red-600 hover:text-red-800">
                                    Delete
                                </button>
                            </form>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif

        <div>
            {{ $clients->links() }}
        </div>
    </div>
</x-app-layout>
