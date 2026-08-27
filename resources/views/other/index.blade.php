<x-app-layout title="One-time expenses">
    <x-slot name="header">
        <x-page-header title="Expenses" />
    </x-slot>

    <div class="space-y-4" x-data="{ adding: {{ $errors->any() && ! old('_editing') ? 'true' : 'false' }}, editingId: null }">
        @include('expenses._tabs')

        <x-month-nav route="other.index" :month="$month" suffix="one-time"
                     subtitle="Irregular company spends — not salary, bill, or EMI" />

        <div class="grid grid-cols-2 lg:grid-cols-3 gap-3">
            <x-card class="p-3 sm:p-4">
                <p class="text-xs text-brand-100/60 uppercase tracking-wide">Total spent</p>
                <p class="text-lg sm:text-2xl font-bold text-white">{{ number_format($total, 2) }}</p>
            </x-card>
            <x-card class="p-3 sm:p-4">
                <p class="text-xs text-brand-100/60 uppercase tracking-wide">Entries</p>
                <p class="text-lg sm:text-2xl font-bold text-white">{{ $items->count() }}</p>
            </x-card>
            <x-card class="p-3 sm:p-4 col-span-2 lg:col-span-1">
                <p class="text-xs text-brand-100/60 uppercase tracking-wide mb-2">By category</p>
                @forelse ($byCategory->take(3) as $row)
                    <div class="flex justify-between text-sm">
                        <span class="text-brand-100/70 truncate">{{ $row['category'] }}</span>
                        <span class="font-semibold text-white shrink-0 ml-2">{{ number_format($row['total'], 0) }}</span>
                    </div>
                @empty
                    <p class="text-sm text-brand-100/50">No spends yet</p>
                @endforelse
            </x-card>
        </div>

        <div class="flex items-center justify-between gap-3">
            <h3 class="font-semibold text-white">One-time expenses</h3>
            <button type="button" @click="adding = ! adding; editingId = null"
                    class="text-sm font-semibold text-brand-500 hover:text-brand-300 min-h-[44px]">
                <span x-show="! adding">+ Add expense</span>
                <span x-show="adding" x-cloak>Cancel</span>
            </button>
        </div>

        <div x-show="adding" x-cloak>
            @include('other._form', ['categories' => $categories])
        </div>

        @if ($items->isEmpty())
            <x-empty-state message="No one-time expenses in {{ $month->format('F Y') }}.">
                <button type="button" @click="adding = true" class="text-brand-500 font-semibold text-sm hover:text-brand-300">
                    Log a company spend &rarr;
                </button>
            </x-empty-state>
        @else
            <x-card class="divide-y divide-white/10">
                @foreach ($items as $item)
                    <div class="p-3 sm:p-4 space-y-3">
                        <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                            <div class="min-w-0 flex-1">
                                <p class="font-medium text-white truncate">{{ $item->name }}</p>
                                <p class="text-xs text-brand-100/60">
                                    <span class="inline-flex items-center rounded bg-white/10 text-brand-100/80 px-1.5 py-0.5 font-semibold">{{ $item->category }}</span>
                                    &middot; {{ $item->spent_on?->format('d M Y') }}
                                    @if ($item->payee)
                                        &middot; {{ $item->payee }}
                                    @endif
                                </p>
                                @if ($item->notes)
                                    <p class="text-xs text-brand-100/50 mt-1">{{ $item->notes }}</p>
                                @endif
                            </div>
                            <div class="flex items-center gap-3 shrink-0">
                                <p class="text-base font-semibold text-white">{{ number_format($item->amount, 2) }}</p>
                                <button type="button"
                                        @click="editingId = editingId === {{ $item->id }} ? null : {{ $item->id }}; adding = false"
                                        class="text-xs font-semibold text-brand-500 hover:text-brand-300 min-h-[44px] px-2">
                                    Edit
                                </button>
                            </div>
                        </div>
                        <div x-show="editingId === {{ $item->id }}" x-cloak>
                            @include('other._form', ['item' => $item, 'categories' => $categories])
                        </div>
                    </div>
                @endforeach
            </x-card>
        @endif
    </div>
</x-app-layout>
