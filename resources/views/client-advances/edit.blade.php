<x-app-layout title="Edit advance">
    <x-slot name="header">
        <x-page-header :title="$advance->name" eyebrow="Client Advances"
                       :subtitle="$advance->client?->name" />
    </x-slot>

    <x-card class="p-5 sm:p-6 max-w-3xl">
        <form method="POST" action="{{ route('client-advances.update', $advance) }}" enctype="multipart/form-data">
            @method('PUT')
            @include('client-advances._form')

            <div class="flex items-center justify-between gap-3 mt-7">
                <div class="flex items-center gap-3">
                    <x-btn type="submit">Save</x-btn>
                    <a href="{{ route('client-advances.index') }}" class="text-sm text-brand-100/60 hover:text-white">Cancel</a>
                </div>
            </div>
        </form>

        @can('client-advances.delete')
            <form method="POST" action="{{ route('client-advances.destroy', $advance) }}" class="mt-6 pt-5 border-t border-white/10"
                  onsubmit="return confirm('Delete this advance? The receipt goes with it.')">
                @csrf @method('DELETE')
                <button type="submit" class="text-sm text-red-300/80 hover:text-red-200">Delete this advance</button>
            </form>
        @endcan
    </x-card>
</x-app-layout>
