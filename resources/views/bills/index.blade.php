<x-app-layout title="Bills">
    <x-slot name="header">
        <x-page-header title="Expenses" />
    </x-slot>

    <div class="space-y-4" x-data="{ adding: false, editingId: null }">
        @include('expenses._tabs')

        <x-month-nav route="bills.index" :month="$month" suffix="bills"
                     subtitle="Budget vs what was paid this month" />

        <div class="grid grid-cols-3 gap-3">
            <x-card class="p-3 sm:p-4">
                <p class="text-xs text-brand-100/60 uppercase tracking-wide">Budgeted</p>
                <p class="text-lg sm:text-2xl font-bold text-white">{{ number_format($totalDue, 2) }}</p>
            </x-card>
            <x-card class="p-3 sm:p-4">
                <p class="text-xs text-brand-100/60 uppercase tracking-wide">Paid</p>
                <p class="text-lg sm:text-2xl font-bold text-green-300">{{ number_format($totalPaid, 2) }}</p>
            </x-card>
            <x-card class="p-3 sm:p-4">
                <p class="text-xs text-brand-100/60 uppercase tracking-wide">Pending</p>
                <p class="text-lg sm:text-2xl font-bold {{ $outstanding > 0 ? 'text-red-300' : 'text-brand-100/50' }}">{{ number_format($outstanding, 2) }}</p>
            </x-card>
        </div>

        <div class="flex items-center justify-between gap-3">
            <h3 class="font-semibold text-white">Active bills</h3>
            <button type="button" @click="adding = ! adding; editingId = null" class="text-sm font-semibold text-brand-500 hover:text-brand-300 min-h-[44px]">
                <span x-show="! adding">+ Add Bill</span>
                <span x-show="adding" x-cloak>Cancel</span>
            </button>
        </div>

        <div x-show="adding" x-cloak>
            <x-card class="p-4 sm:p-6">
                @include('bills._form')
            </x-card>
        </div>

        @if ($rows->isEmpty())
            <x-empty-state message="No active bills for this month.">
                <button type="button" @click="adding = true" class="text-brand-500 font-semibold text-sm hover:text-brand-300">Add your first bill &rarr;</button>
            </x-empty-state>
        @else
            <x-card class="divide-y divide-white/10">
                @foreach ($rows as $row)
                    @php
                        $bill = $row['expense'];
                        $isPaid = $row['paid'] > 0;
                        $diff = $row['paid'] - $row['due'];
                    @endphp

                    <div class="p-3 sm:p-4 space-y-3">
                        <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                            <div class="min-w-0 flex-1">
                                <p class="font-medium text-white truncate">{{ $bill->name }}</p>
                                <p class="text-xs text-brand-100/60">
                                    Budget {{ number_format($row['due'], 0) }}
                                    @if ($isPaid && abs($diff) > 0.001)
                                        <span class="{{ $diff < 0 ? 'text-green-300' : 'text-amber-300' }} font-semibold">
                                            &middot; {{ $diff < 0 ? 'under' : 'over' }} by {{ number_format(abs($diff), 0) }}
                                        </span>
                                    @endif
                                </p>
                            </div>

                            <div class="flex items-center gap-2 shrink-0">
                                <button type="button"
                                        @click="editingId = editingId === {{ $bill->id }} ? null : {{ $bill->id }}; adding = false"
                                        class="text-xs font-semibold text-brand-100/60 hover:text-brand-500 min-h-[44px] px-2">
                                    Edit
                                </button>
                                <form method="POST" action="{{ route('bills.destroy', $bill) }}" onsubmit="return confirm('Delete {{ $bill->name }}?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-xs font-semibold text-red-300 hover:text-red-200 min-h-[44px] px-2">Delete</button>
                                </form>
                                <x-pay-row :action="route('bills.pay', $bill)" :month="$month->format('Y-m-d')"
                                           :due="$row['due']" :paid="$row['paid']" />
                            </div>
                        </div>

                        <div x-show="editingId === {{ $bill->id }}" x-cloak>
                            @include('bills._form', ['bill' => $bill])
                        </div>
                    </div>
                @endforeach
            </x-card>
        @endif

        @if ($paused->isNotEmpty())
            <div>
                <h3 class="font-semibold text-white mb-2">Paused ({{ $paused->count() }})</h3>
                <x-card class="divide-y divide-white/10">
                    @foreach ($paused as $bill)
                        <div class="p-3 sm:p-4 space-y-3" x-data="{ open: false }">
                            <div class="flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-brand-100/70 truncate">{{ $bill->name }}</p>
                                    <p class="text-xs text-brand-100/50">{{ number_format($bill->amount, 0) }}/mo</p>
                                </div>
                                <button type="button" @click="open = ! open" class="text-xs font-semibold text-brand-500 hover:text-brand-300 min-h-[44px]">
                                    Edit
                                </button>
                            </div>
                            <div x-show="open" x-cloak>
                                @include('bills._form', ['bill' => $bill])
                            </div>
                        </div>
                    @endforeach
                </x-card>
            </div>
        @endif
    </div>
</x-app-layout>
