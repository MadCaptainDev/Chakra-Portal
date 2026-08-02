<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Clients">
            <x-slot name="actions">
                <a href="{{ route('clients.create') }}" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-brand-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-brand-500">
                    + Add Client
                </a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-4">
        @if ($clients->isEmpty())
            <x-empty-state message="No clients yet.">
                <a href="{{ route('clients.create') }}" class="text-brand-500 font-semibold text-sm hover:text-brand-600">Add your first client &rarr;</a>
            </x-empty-state>
        @else
            {{-- Mobile: card list --}}
            <div class="md:hidden space-y-3">
                @foreach ($clients as $client)
                    <div class="bg-white shadow-sm rounded-lg p-4">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <a href="{{ route('clients.show', $client) }}" class="font-semibold text-gray-900 truncate hover:text-brand-500 block">{{ $client->name }}</a>
                                @if ($client->address)
                                    <p class="text-sm text-gray-500 truncate">{{ $client->address }}</p>
                                @endif
                                @if ($client->email)
                                    <p class="text-sm text-gray-500 truncate">{{ $client->email }}</p>
                                @endif
                                @if ($client->phone)
                                    <p class="text-sm text-gray-500">{{ $client->phone }}</p>
                                @endif
                            </div>
                            <div class="flex flex-col items-end gap-2 shrink-0">
                                <a href="{{ route('clients.edit', $client) }}" class="text-brand-500 font-semibold text-sm min-h-[44px] flex items-center">Edit</a>
                                <form method="POST" action="{{ route('clients.destroy', $client) }}" onsubmit="return confirm('Delete this client?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-red-600 font-semibold text-sm min-h-[44px] flex items-center">Delete</button>
                                </form>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Desktop: table --}}
            <x-card class="hidden md:block overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Address</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Phone</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach ($clients as $client)
                            <tr>
                                <td class="px-6 py-4 text-sm font-medium text-gray-900">
                                    <a href="{{ route('clients.show', $client) }}" class="hover:text-brand-500">{{ $client->name }}</a>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ $client->address }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ $client->email }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ $client->phone }}</td>
                                <td class="px-6 py-4 text-right text-sm space-x-3">
                                    <a href="{{ route('clients.edit', $client) }}" class="text-brand-500 hover:text-brand-600 font-semibold">Edit</a>
                                    <form method="POST" action="{{ route('clients.destroy', $client) }}" class="inline" onsubmit="return confirm('Delete this client?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-red-600 hover:text-red-900 font-semibold">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>
        @endif

        <div>
            {{ $clients->links() }}
        </div>
    </div>
</x-app-layout>
