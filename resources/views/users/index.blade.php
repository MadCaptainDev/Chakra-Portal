<x-app-layout title="Users">
    <x-slot name="header">
        <x-page-header title="Users" eyebrow="Team access"
                       :subtitle="$users->count().' '.Str::plural('account', $users->count()).' can sign in to the portal.'">
            <x-slot name="actions">
                <x-btn :href="route('users.create')" icon="plus">Add staff</x-btn>
            </x-slot>
        </x-page-header>
    </x-slot>

    {{-- Mobile: card list --}}
    <div class="md:hidden space-y-3">
        @foreach ($users as $user)
            <x-card padding="sm">
                <div class="flex items-start gap-3">
                    <x-avatar :name="$user->name" :src="$user->avatarUrl()" />

                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 flex-wrap">
                            <p class="font-semibold text-white truncate">{{ $user->name }}</p>
                            @if ($user->id === auth()->id())
                                <span class="text-[11px] font-medium text-brand-100/50">(you)</span>
                            @endif
                        </div>
                        <p class="text-sm text-brand-100/60 truncate">{{ $user->email }}</p>

                        <div class="mt-2">
                            <x-badge :status="$user->isAdmin() ? 'active' : 'pending'">
                                {{ $user->isAdmin() ? 'Admin' : 'Employee' }}
                            </x-badge>
                        </div>

                        @if ($user->bio)
                            <p class="text-xs text-brand-100/60 mt-2 line-clamp-2">{{ $user->bio }}</p>
                        @endif
                    </div>
                </div>

                <div class="flex items-center gap-2 mt-3 pt-3 border-t border-white/10">
                    <x-btn :href="route('users.edit', $user)" variant="secondary" size="sm" class="flex-1">Edit</x-btn>
                    @unless ($user->id === auth()->id())
                        <form method="POST" action="{{ route('users.destroy', $user) }}" class="flex-1"
                              onsubmit="return confirm('Remove this staff account?');">
                            @csrf
                            @method('DELETE')
                            <x-btn variant="secondary" size="sm" class="w-full !text-red-300 hover:!bg-red-400/10">Remove</x-btn>
                        </form>
                    @endunless
                </div>
            </x-card>
        @endforeach
    </div>

    {{-- Desktop: table --}}
    <x-card class="hidden md:block overflow-hidden">
        <table class="min-w-full">
            <thead>
                <tr class="border-b border-white/10 bg-brand-900/40">
                    <th class="px-6 py-3 text-left text-[11px] font-bold text-brand-100/60 uppercase tracking-wider">Name</th>
                    <th class="px-6 py-3 text-left text-[11px] font-bold text-brand-100/60 uppercase tracking-wider">Email</th>
                    <th class="px-6 py-3 text-left text-[11px] font-bold text-brand-100/60 uppercase tracking-wider">Access</th>
                    <th class="px-6 py-3 text-right text-[11px] font-bold text-brand-100/60 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/10">
                @foreach ($users as $user)
                    <tr class="hover:bg-white/[0.09] transition">
                        <td class="px-6 py-3.5">
                            <div class="flex items-center gap-3">
                                <x-avatar :name="$user->name" :src="$user->avatarUrl()" size="sm" />
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-white truncate">
                                        {{ $user->name }}
                                        @if ($user->id === auth()->id())
                                            <span class="text-xs font-normal text-brand-100/50">(you)</span>
                                        @endif
                                    </p>
                                    @if ($user->bio)
                                        <p class="text-xs text-brand-100/60 truncate max-w-xs">{{ $user->bio }}</p>
                                    @endif
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-3.5 text-sm text-brand-100/60">{{ $user->email }}</td>
                        <td class="px-6 py-3.5">
                            <x-badge :status="$user->isAdmin() ? 'active' : 'pending'">
                                {{ $user->isAdmin() ? 'Admin' : 'Employee' }}
                            </x-badge>
                        </td>
                        <td class="px-6 py-3.5">
                            <div class="flex items-center justify-end gap-3">
                                <a href="{{ route('users.edit', $user) }}"
                                   class="text-sm font-semibold text-brand-300 hover:text-brand-200">Edit</a>
                                @unless ($user->id === auth()->id())
                                    <form method="POST" action="{{ route('users.destroy', $user) }}"
                                          onsubmit="return confirm('Remove this staff account?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-sm font-semibold text-red-300 hover:text-red-200">Remove</button>
                                    </form>
                                @endunless
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-card>
</x-app-layout>
