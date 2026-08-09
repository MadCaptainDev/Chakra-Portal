@props(['points' => [], 'months' => 6])

@php
    // Replaces the Chart.js grouped bar chart. Two bars per month off a shared
    // baseline, drawn with divs -- which is all that chart ever was, minus
    // 200KB of library fetched from a CDN.
    $rows = collect($points);
    $max = max(1, (float) max($rows->max('income') ?? 0, $rows->max('expense') ?? 0));
    $hasData = $rows->sum('income') > 0 || $rows->sum('expense') > 0;

    $money = fn ($v) => number_format((float) $v, 0);
@endphp

@if (! $hasData)
    <p class="text-sm text-gray-400 py-10 text-center">No cashflow recorded in the last {{ $months }} months.</p>
@else
    <div {{ $attributes->merge(['class' => '']) }}>
        <div class="flex items-center gap-4 mb-4">
            <span class="inline-flex items-center gap-1.5 text-xs font-medium text-gray-600">
                <span class="w-2.5 h-2.5 rounded-sm bg-brand-500"></span> Collected
            </span>
            <span class="inline-flex items-center gap-1.5 text-xs font-medium text-gray-600">
                <span class="w-2.5 h-2.5 rounded-sm bg-amber-400"></span> Paid out
            </span>
        </div>

        {{-- Fixed-height track with absolutely-filled columns: a percentage
             height inside an auto-height flex parent resolves to zero. --}}
        <div class="relative h-36 sm:h-44">
            <div class="absolute inset-0 flex items-end justify-between gap-2 sm:gap-4">
                @foreach ($rows as $point)
                    @php
                        $income = (float) ($point['income'] ?? 0);
                        $expense = (float) ($point['expense'] ?? 0);
                        $iPct = $income > 0 ? max(2, (int) round(($income / $max) * 100)) : 0;
                        $ePct = $expense > 0 ? max(2, (int) round(($expense / $max) * 100)) : 0;
                        $net = $income - $expense;
                    @endphp
                    <div class="flex-1 min-w-0 h-full flex flex-col justify-end group"
                         title="{{ $point['label'] }} — collected {{ $money($income) }}, paid out {{ $money($expense) }}">
                        <div class="flex items-end justify-center gap-1 h-full">
                            <div class="w-1/2 max-w-[18px] rounded-t bg-brand-500 group-hover:bg-brand-600 transition-all duration-500"
                                 style="height: {{ $iPct }}%"></div>
                            <div class="w-1/2 max-w-[18px] rounded-t bg-amber-400 group-hover:bg-amber-500 transition-all duration-500"
                                 style="height: {{ $ePct }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="flex items-start justify-between gap-2 sm:gap-4 mt-2 pt-2 border-t border-gray-100">
            @foreach ($rows as $point)
                @php $net = (float) ($point['income'] ?? 0) - (float) ($point['expense'] ?? 0); @endphp
                <div class="flex-1 min-w-0 text-center">
                    <p class="text-[11px] font-semibold text-gray-600 truncate">{{ $point['label'] }}</p>
                    <p class="text-[10px] tabular-nums truncate {{ $net >= 0 ? 'text-green-600' : 'text-red-600' }}">
                        {{ $net >= 0 ? '+' : '−' }}{{ $money(abs($net)) }}
                    </p>
                </div>
            @endforeach
        </div>
    </div>
@endif
