<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Team">
            <x-slot name="actions">
                <a href="{{ route('home') }}" target="_blank" rel="noopener"
                   class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50">
                    View site
                </a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="max-w-4xl space-y-4" x-data="{ adding: false }">
        <div class="flex items-start justify-between gap-3">
            <p class="text-sm text-gray-500">
                Everyone ticked here appears in the Team section of the landing page, in this order.
                Nothing from Salaries is shown to the public.
            </p>
            <button type="button" @click="adding = ! adding"
                    class="shrink-0 text-sm font-semibold text-brand-500 hover:text-brand-600 min-h-[44px]">
                <span x-show="! adding">+ Add person</span>
                <span x-show="adding" x-cloak>Cancel</span>
            </button>
        </div>

        <div x-show="adding" x-cloak>
            <x-card class="p-4 sm:p-6">
                @include('team._form')
            </x-card>
        </div>

        @if ($members->isEmpty())
            <x-empty-state message="Nobody on the website team yet.">
                <button type="button" @click="adding = true" class="text-brand-500 font-semibold text-sm hover:text-brand-600">
                    Add the first person &rarr;
                </button>
            </x-empty-state>
        @else
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                @foreach ($members as $member)
                    <x-card class="p-4" x-data="{ editing: false }">
                        <div class="flex items-start gap-3">
                            @if ($member->photoUrl())
                                <img src="{{ $member->photoUrl() }}" alt="{{ $member->name }}"
                                     class="w-16 h-16 rounded-full object-cover shrink-0">
                            @else
                                <x-avatar :name="$member->name" size="lg" />
                            @endif

                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <p class="font-semibold text-gray-900 truncate">{{ $member->name }}</p>
                                    <x-badge :status="$member->is_visible ? 'active' : 'inactive'" />
                                </div>
                                <p class="text-xs text-gray-500">{{ $member->role ?: 'No role set' }}</p>
                                @unless ($member->photo_path)
                                    <p class="text-[11px] text-amber-700 mt-1">No photo — initials are shown instead.</p>
                                @endunless
                            </div>
                        </div>

                        @if ($member->bio)
                            <p class="mt-3 text-sm text-gray-600">{{ $member->bio }}</p>
                        @endif

                        <div class="mt-3 pt-3 border-t border-gray-100 flex items-center justify-between">
                            <span class="text-xs text-gray-400">Order {{ $member->sort_order }}</span>
                            <div class="flex items-center gap-2">
                                <button type="button" @click="editing = ! editing"
                                        class="min-h-[44px] px-2 text-xs font-semibold text-brand-500 hover:text-brand-600">
                                    <span x-show="! editing">Edit</span>
                                    <span x-show="editing" x-cloak>Cancel</span>
                                </button>
                                <form method="POST" action="{{ route('team.destroy', $member) }}"
                                      onsubmit="return confirm('Remove {{ $member->name }} from the website?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="min-h-[44px] px-2 text-xs font-semibold text-red-600 hover:text-red-800">Remove</button>
                                </form>
                            </div>
                        </div>

                        <div x-show="editing" x-cloak class="mt-3 pt-3 border-t border-gray-200">
                            @include('team._form', ['member' => $member])
                        </div>
                    </x-card>
                @endforeach
            </div>
        @endif
    </div>
</x-app-layout>
