@php
    use App\Models\Routine;
    use App\Models\RoutineField;
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Routines" subtitle="Repeating studio duties — definitions, not completions.">
            <x-slot name="actions">
                <x-btn :href="route('routines.calendar')" variant="secondary" icon="calendar">Calendar</x-btn>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="max-w-4xl space-y-4" x-data="{ adding: false }">
        <div class="flex items-start justify-between gap-3">
            <p class="text-sm text-gray-500">
                Each routine generates open occurrences on its schedule. Employees tick them on
                <a href="{{ route('my.routines') }}" class="font-semibold text-brand-600 hover:text-brand-700">My Routines</a>.
            </p>
            @can('routines.create')
                <button type="button" @click="adding = ! adding"
                        class="shrink-0 min-h-[44px] text-sm font-semibold text-brand-500 hover:text-brand-600">
                    <span x-show="! adding">+ New</span>
                    <span x-show="adding" x-cloak>Cancel</span>
                </button>
            @endcan
        </div>

        @can('routines.create')
            <div x-show="adding" x-cloak>
                <x-card class="p-4 sm:p-6">
                    <form method="POST" action="{{ route('routines.store') }}">
                        @csrf
                        @include('routines._form', ['routine' => null, 'staff' => $staff, 'socialAccounts' => $socialAccounts, 'contentAccounts' => $contentAccounts])
                    </form>
                </x-card>
            </div>
        @endcan

        @if ($routines->isEmpty())
            <x-empty-state message="No routines yet.">
                @can('routines.create')
                    <button type="button" @click="adding = true" class="text-brand-500 font-semibold text-sm hover:text-brand-600">
                        Add the first one &rarr;
                    </button>
                @endcan
            </x-empty-state>
        @else
            <x-card class="divide-y divide-gray-200">
                @foreach ($routines as $routine)
                    <div class="p-3 sm:p-4" x-data="{ editing: false }">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <p class="font-semibold {{ $routine->is_active ? 'text-gray-900' : 'text-gray-400' }}">
                                        {{ $routine->title }}
                                    </p>
                                    @unless ($routine->is_active)
                                        <x-badge status="retired" color="bg-gray-100 text-gray-600">Inactive</x-badge>
                                    @endunless
                                </div>
                                <p class="text-xs text-gray-500 mt-0.5">
                                    {{ $routine->scheduleLabel() }}
                                    &middot; {{ Routine::MODES[$routine->completion_mode] ?? $routine->completion_mode }}
                                    &middot; {{ $routine->checkpoints_count }} {{ Str::plural('checkpoint', $routine->checkpoints_count) }}
                                    &middot; {{ $routine->subjects_count }} {{ Str::plural('subject', $routine->subjects_count) }}
                                    &middot; {{ $routine->users_count }} {{ Str::plural('person', $routine->users_count) }}
                                </p>
                            </div>

                            <div class="flex items-center gap-2 shrink-0">
                                @can('routines.edit')
                                    <button type="button" @click="editing = ! editing"
                                            class="min-h-[44px] px-2 text-xs font-semibold text-brand-500 hover:text-brand-600">
                                        <span x-show="! editing">Edit</span>
                                        <span x-show="editing" x-cloak>Cancel</span>
                                    </button>
                                @endcan
                                @can('routines.delete')
                                    <form method="POST" action="{{ route('routines.destroy', $routine) }}"
                                          onsubmit="return confirm('Delete this routine and all its occurrences?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="min-h-[44px] px-2 text-xs font-semibold text-red-600 hover:text-red-700">
                                            Delete
                                        </button>
                                    </form>
                                @endcan
                            </div>
                        </div>

                        @can('routines.edit')
                            <div x-show="editing" x-cloak class="mt-3 pt-3 border-t border-gray-100">
                                <form method="POST" action="{{ route('routines.update', $routine) }}">
                                    @csrf
                                    @method('PUT')
                                    @include('routines._form', ['routine' => $routine, 'staff' => $staff, 'socialAccounts' => $socialAccounts, 'contentAccounts' => $contentAccounts])
                                </form>
                            </div>
                        @endcan
                    </div>
                @endforeach
            </x-card>
        @endif
    </div>
</x-app-layout>
