<x-app-layout title="Payroll">
    <x-slot name="header">
        <x-page-header title="Expenses">
            <x-slot name="actions">
                @if ($outstanding > 0)
                    <form method="POST" action="{{ route('salaries.pay-all') }}" onsubmit="return confirm('Mark everyone unpaid this month as paid in full?');">
                        @csrf
                        <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
                        <button type="submit" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-brand-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-brand-500">
                            Pay Everyone
                        </button>
                    </form>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-4" x-data="{ adding: false }">
        @include('expenses._tabs')

        <x-month-nav route="salaries.index" :month="$month" suffix="payroll"
                     subtitle="Salaries are locked — change only from the employee record" />

        <div class="grid grid-cols-3 gap-3">
            <x-card class="p-3 sm:p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Payroll</p>
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

        <div class="flex items-center justify-between gap-3">
            <h3 class="font-semibold text-gray-900">Active employees</h3>
            <button type="button" @click="adding = ! adding" class="text-sm font-semibold text-brand-500 hover:text-brand-600 min-h-[44px]">
                <span x-show="! adding">+ Add Employee</span>
                <span x-show="adding" x-cloak>Cancel</span>
            </button>
        </div>

        <div x-show="adding" x-cloak>
            @include('salaries._form')
        </div>

        @if ($rows->isEmpty())
            <x-empty-state message="No active employees yet.">
                <button type="button" @click="adding = true" class="text-brand-500 font-semibold text-sm hover:text-brand-600">Add your first employee &rarr;</button>
            </x-empty-state>
        @else
            <x-card class="divide-y divide-gray-200">
                @foreach ($rows as $row)
                    @php
                        $employee = $row['expense'];
                        $isPaid = $row['paid'] > 0;
                        $short = $isPaid && $row['paid'] + 0.001 < $row['due'];
                    @endphp

                    <div class="p-3 sm:p-4 flex flex-col sm:flex-row sm:items-center gap-3">
                        <x-avatar :name="$employee->name" :src="$employee->user?->avatarUrl()" />

                        <div class="min-w-0 flex-1">
                            <a href="{{ route('salaries.show', $employee) }}" class="font-medium text-gray-900 hover:text-brand-500 truncate block">
                                {{ $employee->name }}
                            </a>
                            <p class="text-xs text-gray-500 truncate">
                                {{ $employee->role ?: 'No role set' }}
                                &middot; Salary {{ number_format($employee->amount, 0) }}/mo
                                @if ($short)
                                    <span class="text-amber-600 font-semibold">&middot; short {{ number_format($row['due'] - $row['paid'], 0) }}</span>
                                @endif
                            </p>
                        </div>

                        <form method="POST" action="{{ route('salaries.pay', $employee) }}" class="flex items-center gap-2 shrink-0">
                            @csrf
                            <input type="hidden" name="month" value="{{ $month->format('Y-m-d') }}">
                            <input type="number" step="0.01" min="0" name="amount_paid"
                                   value="{{ $isPaid ? number_format($row['paid'], 2, '.', '') : number_format($row['due'], 2, '.', '') }}"
                                   placeholder="{{ number_format($row['due'], 2, '.', '') }}"
                                   class="w-24 sm:w-28 rounded-md border-gray-300 shadow-sm text-sm text-right focus:border-brand-400 focus:ring-brand-400 min-h-[44px]">
                            <button type="submit"
                                    class="min-h-[44px] px-3 rounded-md text-xs font-semibold uppercase tracking-wider {{ $isPaid ? 'bg-green-100 text-green-800 hover:bg-green-200' : 'bg-brand-400 text-white hover:bg-brand-500' }}">
                                {{ $isPaid ? 'Update' : 'Pay' }}
                            </button>
                        </form>
                    </div>
                @endforeach
            </x-card>
        @endif

        @if ($left->isNotEmpty())
            <div>
                <h3 class="font-semibold text-gray-900 mb-2">No longer active ({{ $left->count() }})</h3>
                <x-card class="divide-y divide-gray-200">
                    @foreach ($left as $employee)
                        <div class="p-3 flex items-center gap-3">
                            <x-avatar :name="$employee->name" :src="$employee->user?->avatarUrl()" size="sm" class="opacity-60" />
                            <a href="{{ route('salaries.show', $employee) }}" class="text-sm text-gray-600 hover:text-brand-500 flex-1 truncate">{{ $employee->name }}</a>
                            <span class="text-xs text-gray-400 shrink-0">{{ number_format($employee->amount, 0) }}/mo</span>
                        </div>
                    @endforeach
                </x-card>
            </div>
        @endif
    </div>
</x-app-layout>
