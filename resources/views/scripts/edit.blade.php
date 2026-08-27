<x-app-layout :title="$script->title">
    <x-slot name="header">
        <x-page-header :title="$script->title" eyebrow="Script"
                       :subtitle="collect([$script->clientLabel(), $script->campaign])->filter()->implode(' · ') ?: 'No client set'">
            <x-slot name="actions">
                <x-btn :href="route('scripts.show', $script)" variant="secondary" icon="eye">Preview</x-btn>
                <x-btn :href="route('scripts.index')" variant="secondary">All scripts</x-btn>
            </x-slot>
        </x-page-header>
    </x-slot>

    {{-- The writing gets the width and the attention. Metadata is a collapsed
         drawer rather than a column beside it: on a phone a sidebar becomes a
         wall of selects above the thing you came here to do. --}}
    <div class="max-w-3xl mx-auto space-y-4" x-data="{ details: false }">

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2">
                <x-badge :status="$script->status" />
                @if ($script->due_on)
                    <span @class([
                        'text-xs font-semibold',
                        'text-red-300' => $script->isOverdue(),
                        'text-brand-100/60' => ! $script->isOverdue(),
                    ])>
                        {{ $script->isOverdue() ? 'Overdue' : 'Due' }} {{ $script->due_on->format('d M') }}
                    </span>
                @endif
                @if ($script->lastEditedBy)
                    <span class="text-xs text-brand-100/50">
                        Last edited by {{ Str::before($script->lastEditedBy->name, ' ') }}
                        {{ $script->last_edited_at?->diffForHumans() }}
                    </span>
                @endif
            </div>

            <button type="button" @click="details = ! details"
                    class="inline-flex items-center gap-1.5 min-h-[44px] text-sm font-semibold text-brand-300 hover:text-brand-200">
                <span x-show="! details">Details</span>
                <span x-show="details" x-cloak>Hide details</span>
                <x-icon name="chevron-right" class="w-4 h-4 transition-transform" ::class="details && 'rotate-90'" />
            </button>
        </div>

        {{-- Metadata is a normal form post. It is a handful of selects, it is
             not where work gets lost, and keeping it conventional halves the
             JavaScript on this page. --}}
        <div x-show="details" x-cloak>
            <x-card class="p-4 sm:p-6">
                <form method="POST" action="{{ route('scripts.update', $script) }}">
                    @method('PUT')
                    @include('scripts._form')

                    <div class="flex items-center gap-4 mt-6">
                        <x-btn type="submit">Save details</x-btn>

                        @can('scripts.delete')
                            <button type="button"
                                    @click="$dispatch('open-modal', 'delete-script')"
                                    class="text-sm font-semibold text-red-300 hover:text-red-200 ml-auto">
                                Delete script
                            </button>
                        @endcan
                    </div>
                </form>
            </x-card>
        </div>

        @include('scripts._brief-drawer')

        @include('scripts._editor')
    </div>

    @can('scripts.delete')
        <x-modal name="delete-script" :show="false" maxWidth="md">
            <div class="p-6">
                <h2 class="text-lg font-semibold text-white">Delete this script?</h2>
                <p class="mt-2 text-sm text-brand-100/70">
                    &ldquo;{{ $script->title }}&rdquo; and all {{ $script->sections->count() }}
                    {{ Str::plural('section', $script->sections->count()) }} of writing go with it. This cannot be undone.
                </p>

                <form method="POST" action="{{ route('scripts.destroy', $script) }}" class="mt-6 flex justify-end gap-3">
                    @csrf
                    @method('DELETE')
                    <button type="button" @click="$dispatch('close-modal', 'delete-script')"
                            class="text-sm font-semibold text-brand-100/70 hover:text-white min-h-[44px] px-3">Cancel</button>
                    <x-danger-button>Delete</x-danger-button>
                </form>
            </div>
        </x-modal>
    @endcan
</x-app-layout>
