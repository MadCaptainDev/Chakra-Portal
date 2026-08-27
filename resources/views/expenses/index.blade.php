@php
    $monthKey = $month->format('Y-m');

    $typeStyles = [
        'emi' => [
            'dot' => 'bg-brand-500',
            'bar' => 'bg-brand-400',
            'manage' => route('emi.index', ['month' => $monthKey]),
            'label' => 'EMI',
        ],
        'salary' => [
            'dot' => 'bg-green-500',
            'bar' => 'bg-green-500',
            'manage' => route('salaries.index', ['month' => $monthKey]),
            'label' => 'Salaries',
        ],
        'bill' => [
            'dot' => 'bg-sky-500',
            'bar' => 'bg-sky-500',
            'manage' => route('bills.index', ['month' => $monthKey]),
            'label' => 'Bills',
        ],
        'other' => [
            'dot' => 'bg-amber-500',
            'bar' => 'bg-amber-500',
            'manage' => route('other.index', ['month' => $monthKey]),
            'label' => 'One-time',
        ],
    ];
@endphp

<x-app-layout title="Expenses">
    <x-slot name="header">
        <x-page-header title="Expenses">
            <x-slot name="actions">
                @if ($outstanding > 0)
                    <form method="POST" action="{{ route('expenses.pay-all') }}" onsubmit="return confirm('Mark every unpaid item this month as paid at its standard amount?');">
                        @csrf
                        <input type="hidden" name="month" value="{{ $monthKey }}">
                        <button type="submit" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-brand-400 border border-transparent rounded-md font-semibold text-xs text-brand-900 uppercase tracking-widest hover:bg-brand-500">
                            Mark All Paid
                        </button>
                    </form>
                @endif
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-6">
        @include('expenses._tabs')

        <x-month-nav route="expenses.index" :month="$month"
                     subtitle="Month outflow command center" />

        @if ($rows->isEmpty())
            <x-empty-state message="Nothing is payable in {{ $month->format('F Y') }}.">
                <a href="{{ route('other.index') }}" class="text-brand-500 font-semibold text-sm hover:text-brand-300">Add a one-time expense &rarr;</a>
            </x-empty-state>
        @else
            {{-- Hero month strip --}}
            <x-card class="p-4 sm:p-6">
                <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4 mb-4">
                    <div>
                        <p class="text-xs font-semibold text-brand-100/60 uppercase tracking-wide">{{ $month->format('F Y') }} outflow</p>
                        <p class="mt-1 text-sm text-brand-100/70">
                            @if ($outstanding > 0)
                                {{ $paidPercent }}% paid · {{ number_format($outstanding, 2) }} still open
                            @else
                                Fully cleared for this month
                            @endif
                        </p>
                    </div>
                    @if ($outstanding > 0)
                        <form method="POST" action="{{ route('expenses.pay-all') }}" class="shrink-0" onsubmit="return confirm('Mark every unpaid item this month as paid at its standard amount?');">
                            @csrf
                            <input type="hidden" name="month" value="{{ $monthKey }}">
                            <button type="submit" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-brand-400 border border-transparent rounded-md font-semibold text-xs text-brand-900 uppercase tracking-widest hover:bg-brand-500">
                                Mark All Paid
                            </button>
                        </form>
                    @endif
                </div>

                <div class="grid grid-cols-3 gap-3 mb-4">
                    <div>
                        <p class="text-xs text-brand-100/60 uppercase tracking-wide">Due</p>
                        <p class="text-lg sm:text-2xl font-bold text-white">{{ number_format($totalDue, 2) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-brand-100/60 uppercase tracking-wide">Paid</p>
                        <p class="text-lg sm:text-2xl font-bold text-green-300">{{ number_format($totalPaid, 2) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-brand-100/60 uppercase tracking-wide">Outstanding</p>
                        <p class="text-lg sm:text-2xl font-bold {{ $outstanding > 0 ? 'text-red-300' : 'text-brand-100/50' }}">{{ number_format($outstanding, 2) }}</p>
                    </div>
                </div>

                <div class="h-2.5 rounded-full bg-white/10 overflow-hidden">
                    <div class="h-full rounded-full {{ $paidPercent >= 100 ? 'bg-green-500' : 'bg-brand-400' }} transition-all"
                         style="width: {{ $paidPercent }}%"></div>
                </div>
                <p class="mt-2 text-xs text-brand-100/60 text-right">{{ $paidPercent }}% paid</p>
            </x-card>

            {{-- At-a-glance cards --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                <x-stat-card label="Payroll pending" value="{{ number_format($glance['payroll_pending'], 2) }}" :accent="$glance['payroll_pending'] > 0 ? 'amber' : 'gray'">
                    {{ $glance['payroll_unpaid_count'] }} unpaid {{ Str::plural('salary', $glance['payroll_unpaid_count']) }}
                </x-stat-card>
                <x-stat-card label="EMI load" value="{{ number_format($glance['emi_load'], 2) }}" accent="brand">
                    {{ $glance['emi_unpaid_count'] }} unpaid {{ Str::plural('EMI', $glance['emi_unpaid_count']) }}
                </x-stat-card>
                <x-stat-card label="Bills pending" value="{{ number_format($glance['bills_pending'], 2) }}" :accent="$glance['bills_pending'] > 0 ? 'amber' : 'gray'">
                    {{ $glance['bills_unpaid_count'] }} unpaid {{ Str::plural('bill', $glance['bills_unpaid_count']) }}
                </x-stat-card>
                <x-stat-card label="One-time spent" value="{{ number_format($glance['other_spent'], 2) }}" accent="amber">
                    {{ $glance['other_count'] }} {{ Str::plural('entry', $glance['other_count']) }}
                </x-stat-card>
            </div>

            {{-- Outflow mix board --}}
            <div>
                <div class="flex items-center justify-between mb-3">
                    <h3 class="font-semibold text-white">Outflow mix</h3>
                    <p class="text-xs text-brand-100/60">EMI · Salary · Bill · One-time</p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3">
                    @foreach ($byType as $type => $group)
                        @php $style = $typeStyles[$type] ?? ['dot' => 'bg-white/30', 'bar' => 'bg-white/30', 'manage' => null, 'label' => $group['label']]; @endphp
                        <x-card class="p-4 sm:p-5">
                            <div class="flex items-center justify-between mb-3">
                                <span class="inline-flex items-center gap-2 font-semibold text-white">
                                    <span class="w-2.5 h-2.5 rounded-full {{ $style['dot'] }}"></span>
                                    {{ $style['label'] }}
                                </span>
                                @if (! empty($style['manage']))
                                    <a href="{{ $style['manage'] }}" class="text-xs font-semibold text-brand-500 hover:text-brand-300">Open</a>
                                @endif
                            </div>
                            <div class="grid grid-cols-3 gap-2 text-center mb-3">
                                <div>
                                    <p class="text-[11px] text-brand-100/60 uppercase">Due</p>
                                    <p class="text-sm font-semibold text-white">{{ number_format($group['due'], 0) }}</p>
                                </div>
                                <div>
                                    <p class="text-[11px] text-brand-100/60 uppercase">Paid</p>
                                    <p class="text-sm font-semibold text-green-300">{{ number_format($group['paid'], 0) }}</p>
                                </div>
                                <div>
                                    <p class="text-[11px] text-brand-100/60 uppercase">Open</p>
                                    <p class="text-sm font-semibold {{ $group['outstanding'] > 0 ? 'text-red-300' : 'text-brand-100/50' }}">{{ number_format($group['outstanding'], 0) }}</p>
                                </div>
                            </div>
                            <div class="h-1.5 rounded-full bg-white/10 overflow-hidden">
                                <div class="h-full rounded-full {{ $style['bar'] }}" style="width: {{ $group['percent'] }}%"></div>
                            </div>
                            <p class="mt-1.5 text-[11px] text-brand-100/60">{{ $group['percent'] }}% paid · {{ $group['unpaid_count'] }} need action</p>
                        </x-card>
                    @endforeach
                </div>
            </div>

            {{-- Attention / action board --}}
            <div>
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-3">
                    <div>
                        <h3 class="font-semibold text-white">Needs attention</h3>
                        <p class="text-xs text-brand-100/60">Unpaid or short-paid items for {{ $month->format('F Y') }}</p>
                    </div>
                    @if ($attentionRows->isNotEmpty())
                        <span class="text-xs font-semibold text-amber-200 bg-amber-400/10 border border-amber-400/30 rounded-md px-2.5 py-1 w-fit">
                            {{ $attentionRows->count() }} {{ Str::plural('item', $attentionRows->count()) }}
                        </span>
                    @endif
                </div>

                @if ($attentionRows->isEmpty())
                    <x-card class="p-6 text-center">
                        <p class="text-sm font-medium text-green-200">Nothing outstanding — this month is clear.</p>
                        <p class="text-xs text-brand-100/60 mt-1">Manage line items on Salaries, Bills, or EMI tabs.</p>
                    </x-card>
                @else
                    <x-card class="divide-y divide-white/10">
                        @foreach ($attentionRows as $row)
                            @php
                                $expense = $row['expense'];
                                $style = $typeStyles[$expense->type] ?? ['dot' => 'bg-white/30', 'label' => $expense->typeLabel()];
                                $isPartial = $row['paid'] > 0;
                            @endphp
                            <div class="p-3 sm:p-4 flex flex-col lg:flex-row lg:items-center gap-3">
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="w-2 h-2 rounded-full {{ $style['dot'] }}"></span>
                                        <p class="font-medium text-white truncate">
                                            {{ $expense->name }}
                                            @if ($row['installment'])
                                                <span class="text-xs font-normal text-brand-100/60">
                                                    &middot; {{ $row['installment'] }} of {{ $expense->installments }}
                                                </span>
                                            @endif
                                        </p>
                                        <span class="text-[11px] font-semibold uppercase tracking-wide text-brand-100/60">{{ $style['label'] }}</span>
                                        @if ($isPartial)
                                            <span class="text-[11px] font-semibold text-amber-200">Short</span>
                                        @endif
                                    </div>
                                    <p class="text-xs text-brand-100/60 mt-1">
                                        Due {{ number_format($row['due'], 2) }}
                                        · Paid {{ number_format($row['paid'], 2) }}
                                        · Shortfall <span class="font-semibold text-red-300">{{ number_format($row['shortfall'], 2) }}</span>
                                    </p>
                                </div>

                                <x-pay-row :action="route('expenses.pay', $expense)" :month="$month->format('Y-m-d')"
                                           :due="$row['due']" />
                            </div>
                        @endforeach
                    </x-card>
                @endif
            </div>

            {{-- Short links into type tabs --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                @foreach ($typeStyles as $type => $style)
                    @php $group = $byType[$type] ?? null; @endphp
                    <a href="{{ $style['manage'] }}"
                       class="flex items-center justify-between gap-3 rounded-lg bg-white/5 shadow-sm border border-white/10 px-4 py-3 hover:border-brand-300 transition-colors">
                        <span class="inline-flex items-center gap-2 text-sm font-semibold text-white">
                            <span class="w-2 h-2 rounded-full {{ $style['dot'] }}"></span>
                            {{ $style['label'] }}
                        </span>
                        <span class="text-xs text-brand-100/60">
                            @if ($group && $group['outstanding'] > 0)
                                {{ number_format($group['outstanding'], 0) }} open &rarr;
                            @else
                                Manage &rarr;
                            @endif
                        </span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</x-app-layout>
