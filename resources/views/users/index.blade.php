<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Users">
            <x-slot name="actions">
                <a href="{{ route('users.create') }}" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-brand-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-brand-500">
                    + Add Staff
                </a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-4">
        {{-- Mobile: card list --}}
        <div class="md:hidden space-y-3">
            @foreach ($users as $user)
                <div class="bg-white shadow-sm rounded-lg p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <p class="font-semibold text-gray-900 truncate">{{ $user->name }}</p>
                                <x-badge :status="$user->isAdmin() ? 'active' : 'pending'">
                                    {{ $user->isAdmin() ? 'Admin' : 'Employee' }}
                                </x-badge>
                            </div>
                            <p class="text-sm text-gray-500 truncate">{{ $user->email }}</p>
                            @if ($user->employeeRecord)
                                <p class="text-xs text-gray-400 truncate">Linked to {{ $user->employeeRecord->name }} in Salaries</p>
                            @endif
                        </div>
                        @unless ($user->id === auth()->id())
                            <form method="POST" action="{{ route('users.destroy', $user) }}" onsubmit="return confirm('Remove this staff account?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-600 font-semibold text-sm min-h-[44px]">Remove</button>
                            </form>
                        @endunless
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
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Access</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Employee</th>
                        <th class="px-6 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach ($users as $user)
                        <tr>
                            <td class="px-6 py-4 text-sm font-medium text-gray-900">
                                {{ $user->name }}
                                @if ($user->id === auth()->id())
                                    <span class="text-xs text-gray-400">(you)</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-500">{{ $user->email }}</td>
                            <td class="px-6 py-4 text-sm">
                                <x-badge :status="$user->isAdmin() ? 'active' : 'pending'">
                                    {{ $user->isAdmin() ? 'Admin' : 'Employee' }}
                                </x-badge>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-500">
                                {{ $user->employeeRecord?->name ?? '—' }}
                            </td>
                            <td class="px-6 py-4 text-right text-sm">
                                @unless ($user->id === auth()->id())
                                    <form method="POST" action="{{ route('users.destroy', $user) }}" onsubmit="return confirm('Remove this staff account?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-red-600 hover:text-red-900 font-semibold">Remove</button>
                                    </form>
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-card>
    </div>
</x-app-layout>
