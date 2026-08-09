<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Add to portfolio">
            <x-slot name="actions">
                <a href="{{ route('portfolio.index') }}"
                   class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50">
                    Back
                </a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="max-w-3xl">
        <x-card class="p-4 sm:p-6">
            @include('portfolio._form')
        </x-card>
    </div>
</x-app-layout>
