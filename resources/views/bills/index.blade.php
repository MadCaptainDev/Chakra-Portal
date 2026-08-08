@php
    $prev = $month->copy()->subMonthNoOverflow()->format('Y-m');
    $next = $month->copy()->addMonthNoOverflow()->format('Y-m');
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Bills" />
    </x-slot>

    <div class="space-y-4" x-data="{ adding: false }">
        {{-- Month navigation --}}
        <div class="flex items-center justify-between gap-2">
            <a href="{{ route('bills.index', ['month' => $prev]) }}"
               class="inline-flex items-center min-h-[44px] px-3 rounded-md bg-white border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50">&larr; Prev</a>
            <div class="text-center">
                <p class="font-semibold text-gray-900">{{ $month->format('F Y') }}</p>
                @if (! $month->isSameMonth(now()))
                    <a href="{{ route('bills.index') }}" class="text-xs text-brand-500 hover:text-brand-600">Back to this month</a>
                @endif
            </div>
            <a href="{{ route('bills.index', ['month' => $next]) }}"
               class="inline-flex items-center min-h-[44px] px-3 rounded-md bg-white border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50">Next &rarr;</a>
        </div>

        <div class="grid grid-cols-3 gap-3">
            <x-card class="p-3 sm:p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Budgeted</p>
                <p class="text-lg sm:text-2xl font-bold text-gray-900">{{ number_format($totalDue, 2) }}</p>
            </x-card>
            <x-card class="p-3 sm:p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Paid</p>
                <p class="text-lg sm:text-2xl font-bold text-green-600">{{ number_format($totalPaid, 2) }}</p>
            </x-card>
            <x-card class="p-3 sm:p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Pending</p>
                <p class="text-lg sm:text-2xl font-bold {{ $outstanding > 0 ? 'text-red-600' : 'text-gray-400' }}">{{ number_format($outstanding, 2) }}</p>
            </x-card>
        </div>

        <div class="flex justify-end">
            <button type="button" @click="adding = ! adding" class="text-sm font-semibold text-brand-500 hover:text-brand-600 min-h-[44px]">
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
            <x-empty-state message="No bills set up yet.">
                <button type="button" @click="adding = true" class="text-brand-500 font-semibold text-sm hover:text-brand-600">Add your first bill &rarr;</button>
            </x-empty-state>
        @else
            <x-card class="divide-y divide-gray-200">
                @foreach ($rows as $row)
                    @php
                        $bill = $row['expense'];
                        $isPaid = $row['paid'] > 0;
                        $diff = $row['paid'] - $row['due'];
                    @endphp

                    <div class="p-3 sm:p-4" x-data="{ editing: false }">
                        <div class="flex items-center gap-3">
                            <div class="min-w-0 flex-1">
                                <p class="font-medium text-gray-900 truncate">{{ $bill->name }}</p>
                                <p class="text-xs text-gray-500">
                                    Budget {{ number_format($row['due'], 0) }}
                                    @if ($isPaid && abs($diff) > 0.001)
                                        <span class="{{ $diff < 0 ? 'text-green-600' : 'text-amber-600' }} font-semibold">
                                            &middot; {{ $diff < 0 ? 'under' : 'over' }} by {{ number_format(abs($diff), 0) }}
                                        </span>
                                    @endif
                                </p>
                            </div>

                            <form method="POST" action="{{ route('bills.pay', $bill) }}" class="flex items-center gap-2 shrink-0">
                                @csrf
                                <input type="hidden" name="month" value="{{ $month->format('Y-m-d') }}">
                                <input type="number" step="0.01" min="0" name="amount_paid"
                                       value="{{ $isPaid ? number_format($row['paid'], 2, '.', '') : '' }}"
                                       placeholder="{{ number_format($row['due'], 2, '.', '') }}"
                                       class="w-24 sm:w-28 rounded-md border-gray-300 shadow-sm text-sm text-right focus:border-brand-400 focus:ring-brand-400 min-h-[44px]">
                                <button type="submit"
                                        class="min-h-[44px] px-3 rounded-md text-xs font-semibold uppercase tracking-wider {{ $isPaid ? 'bg-green-100 text-green-800 hover:bg-green-200' : 'bg-brand-400 text-white hover:bg-brand-500' }}">
                                    {{ $isPaid ? 'Paid' : 'Pay' }}
                                </button>
                            </form>
                        </div>

                        <div class="flex items-center justify-end gap-3">
                            <button type="button" @click="editing = ! editing"
                                    class="min-h-[44px] px-2 text-xs font-semibold text-brand-500 hover:text-brand-600">
                                <span x-show="! editing">Edit</span>
                                <span x-show="editing" x-cloak>Cancel</span>
                            </button>
                            <form method="POST" action="{{ route('bills.destroy', $bill) }}"
                                  onsubmit="return confirm('Delete {{ $bill->name }}? Any recorded payments against it go too.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="min-h-[44px] px-2 text-xs font-semibold text-red-600 hover:text-red-800">
                                    Delete
                                </button>
                            </form>
                        </div>

                        <div x-show="editing" x-cloak class="mt-2 pt-3 border-t border-gray-200">
                            @include('bills._form', ['bill' => $bill])
                        </div>
                    </div>
                @endforeach
            </x-card>
        @endif

        @if ($paused->isNotEmpty())
            <div>
                <h3 class="font-semibold text-gray-900 mb-2">Paused ({{ $paused->count() }})</h3>
                <x-card class="divide-y divide-gray-200">
                    @foreach ($paused as $bill)
                        <div class="p-3 flex items-center justify-between">
                            <span class="text-sm text-gray-600">{{ $bill->name }}</span>
                            <span class="text-xs text-gray-400">{{ number_format($bill->amount, 0) }}/mo</span>
                        </div>
                    @endforeach
                </x-card>
            </div>
        @endif
    </div>
</x-app-layout>
