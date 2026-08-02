@php
    $prev = $month->copy()->subMonthNoOverflow()->format('Y-m');
    $next = $month->copy()->addMonthNoOverflow()->format('Y-m');

    $typeStyles = [
        'emi' => ['label' => 'EMI / Finance', 'dot' => 'bg-purple-500'],
        'salary' => ['label' => 'Salaries', 'dot' => 'bg-green-500'],
        'bill' => ['label' => 'Bills', 'dot' => 'bg-blue-500'],
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Expenses">
            <x-slot name="actions">
                <a href="{{ route('emi.index') }}" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50">
                    EMI
                </a>
                <a href="{{ route('salaries.index') }}" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50">
                    Salaries
                </a>
                @if ($outstanding > 0)
                    <form method="POST" action="{{ route('expenses.pay-all') }}" onsubmit="return confirm('Mark every unpaid item this month as paid at its standard amount?');">
                        @csrf
                        <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
                        <button type="submit" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-brand-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-brand-500">
                            Mark All Paid
                        </button>
                    </form>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-4">
        {{-- Month navigation --}}
        <div class="flex items-center justify-between gap-2">
            <a href="{{ route('expenses.index', ['month' => $prev]) }}"
               class="inline-flex items-center min-h-[44px] px-3 rounded-md bg-white border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50">&larr; Prev</a>

            <div class="text-center">
                <p class="font-semibold text-gray-900">{{ $month->format('F Y') }}</p>
                @if (! $month->isSameMonth(now()))
                    <a href="{{ route('expenses.index') }}" class="text-xs text-brand-500 hover:text-brand-600">Back to this month</a>
                @endif
            </div>

            <a href="{{ route('expenses.index', ['month' => $next]) }}"
               class="inline-flex items-center min-h-[44px] px-3 rounded-md bg-white border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50">Next &rarr;</a>
        </div>

        {{-- Totals --}}
        <div class="grid grid-cols-3 gap-3">
            <x-card class="p-3 sm:p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Due</p>
                <p class="text-lg sm:text-2xl font-bold text-gray-900">{{ number_format($totalDue, 2) }}</p>
            </x-card>
            <x-card class="p-3 sm:p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Paid</p>
                <p class="text-lg sm:text-2xl font-bold text-green-600">{{ number_format($totalPaid, 2) }}</p>
            </x-card>
            <x-card class="p-3 sm:p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Outstanding</p>
                <p class="text-lg sm:text-2xl font-bold {{ $outstanding > 0 ? 'text-red-600' : 'text-gray-400' }}">{{ number_format($outstanding, 2) }}</p>
            </x-card>
        </div>

        @if ($rows->isEmpty())
            <x-empty-state message="Nothing is payable in {{ $month->format('F Y') }}.">
                <a href="{{ route('emi.index') }}" class="text-brand-500 font-semibold text-sm hover:text-brand-600">Add an expense &rarr;</a>
            </x-empty-state>
        @else
            @foreach ($groups as $type => $group)
                @php $style = $typeStyles[$type] ?? ['label' => ucfirst($type), 'dot' => 'bg-gray-400']; @endphp

                <div>
                    <div class="flex items-center gap-2 mb-2">
                        <span class="w-2.5 h-2.5 rounded-full {{ $style['dot'] }}"></span>
                        <h3 class="font-semibold text-gray-900">{{ $style['label'] }}</h3>
                        <span class="text-sm text-gray-500">
                            {{ number_format($group->sum('paid'), 2) }} / {{ number_format($group->sum('due'), 2) }}
                        </span>
                    </div>

                    <x-card class="divide-y divide-gray-200">
                        @foreach ($group as $row)
                            @php
                                $expense = $row['expense'];
                                $isPaid = $row['paid'] > 0;
                                $short = $isPaid && $row['paid'] + 0.001 < $row['due'];
                            @endphp

                            <div class="p-3 sm:p-4 flex flex-col sm:flex-row sm:items-center gap-3">
                                <div class="min-w-0 flex-1">
                                    <p class="font-medium text-gray-900 truncate">
                                        {{ $expense->name }}
                                        @if ($row['installment'])
                                            <span class="text-xs font-normal text-gray-500">
                                                &middot; {{ $row['installment'] }} of {{ $expense->installments }}
                                            </span>
                                        @endif
                                    </p>
                                    <p class="text-xs text-gray-500">
                                        @if ($expense->payee){{ $expense->payee }} &middot; @endif
                                        Due {{ number_format($row['due'], 2) }}
                                        @if ($short)
                                            <span class="text-amber-600 font-semibold">&middot; short by {{ number_format($row['due'] - $row['paid'], 2) }}</span>
                                        @endif
                                    </p>
                                </div>

                                <form method="POST" action="{{ route('expenses.pay', $expense) }}" class="flex items-center gap-2 shrink-0">
                                    @csrf
                                    <input type="hidden" name="month" value="{{ $month->format('Y-m-d') }}">
                                    <input type="number" step="0.01" min="0" name="amount_paid"
                                           value="{{ $isPaid ? number_format($row['paid'], 2, '.', '') : '' }}"
                                           placeholder="{{ number_format($row['due'], 2, '.', '') }}"
                                           class="w-28 rounded-md border-gray-300 shadow-sm text-sm text-right focus:border-brand-400 focus:ring-brand-400 min-h-[44px]">
                                    <button type="submit"
                                            class="min-h-[44px] px-3 rounded-md text-xs font-semibold uppercase tracking-wider {{ $isPaid ? 'bg-green-100 text-green-800 hover:bg-green-200' : 'bg-brand-400 text-white hover:bg-brand-500' }}">
                                        {{ $isPaid ? 'Update' : 'Pay' }}
                                    </button>
                                </form>
                            </div>
                        @endforeach
                    </x-card>
                </div>
            @endforeach
        @endif
    </div>
</x-app-layout>
