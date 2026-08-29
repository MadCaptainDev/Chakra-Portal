<x-app-layout title="EMI">
    <x-slot name="header">
        <x-page-header title="Expenses" />
    </x-slot>

    <div class="space-y-6" x-data="{ adding: false, editingId: null, payEditId: null }">
        @include('expenses._tabs')

        <x-month-nav route="emi.index" :month="$asOf" suffix="EMI"
                     subtitle="Schedules, liability, and this month’s payments" />

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <x-card class="p-4">
                <p class="text-xs text-brand-100/60 uppercase tracking-wide">Total Outstanding</p>
                <p class="text-2xl sm:text-3xl font-bold text-white">{{ number_format($outstanding, 2) }}</p>
                <p class="text-xs text-brand-100/60 mt-1">across {{ $running->count() }} running EMI{{ $running->count() === 1 ? '' : 's' }}</p>
            </x-card>

            <x-card class="p-4">
                <p class="text-xs text-brand-100/60 uppercase tracking-wide">This Month Due</p>
                <p class="text-2xl sm:text-3xl font-bold text-brand-300">{{ number_format($monthlyLoad, 2) }}</p>
                <p class="text-xs text-brand-100/60 mt-1">Paid {{ number_format($monthlyPaid, 2) }}</p>
            </x-card>

            <x-card class="p-4">
                <p class="text-xs text-brand-100/60 uppercase tracking-wide mb-2">By Bank</p>
                <div class="space-y-1">
                    @forelse ($byBank as $bank)
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-brand-100/70 truncate">{{ $bank['bank'] }}</span>
                            <span class="font-semibold text-white shrink-0 ml-2">{{ number_format($bank['outstanding'], 0) }}</span>
                        </div>
                    @empty
                        <p class="text-sm text-brand-100/50">Nothing outstanding</p>
                    @endforelse
                </div>
            </x-card>
        </div>

        @if (! empty($timeline))
            <x-card class="p-4 sm:p-6">
                <h3 class="font-semibold text-white">Payoff Timeline</h3>
                <p class="text-xs text-brand-100/60 mb-4">Monthly EMI load from now until the last installment clears.</p>

                <div class="overflow-x-auto -mx-1 px-1">
                    <div class="flex items-end gap-1 min-w-max h-40">
                        @foreach ($timeline as $point)
                            <div class="flex flex-col items-center justify-end h-full group" style="width: 34px">
                                <span class="text-[9px] text-brand-100/60 mb-1 whitespace-nowrap">
                                    {{ $point['total'] > 0 ? number_format($point['total'] / 1000, 1) . 'k' : '' }}
                                </span>
                                <div class="w-full rounded-t bg-brand-400 group-hover:bg-brand-500 transition-colors"
                                     style="height: {{ max($point['percent'], 1) }}%"
                                     title="{{ $point['month']->format('F Y') }} — {{ number_format($point['total'], 2) }}"></div>
                                <span class="text-[9px] text-brand-100/60 mt-1 whitespace-nowrap">{{ $point['month']->format('M') }}</span>
                                @if ($point['month']->month === 1 || $loop->first)
                                    <span class="text-[9px] font-semibold text-brand-100/50">{{ $point['month']->format('y') }}</span>
                                @else
                                    <span class="text-[9px]">&nbsp;</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </x-card>
        @endif

        <div>
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-semibold text-white">Running ({{ $running->count() }})</h3>
                <button type="button" @click="adding = ! adding; editingId = null; payEditId = null"
                        class="text-sm font-semibold text-brand-500 hover:text-brand-300 min-h-[44px]">
                    <span x-show="! adding">+ Add EMI</span>
                    <span x-show="adding" x-cloak>Cancel</span>
                </button>
            </div>

            <div x-show="adding" x-cloak class="mb-4">
                <x-card class="p-4 sm:p-6">
                    @include('emi._form')
                </x-card>
            </div>

            @if ($running->isEmpty())
                <x-empty-state message="No running EMIs." />
            @else
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                    @foreach ($running as $emi)
                        @php
                            $completed = $emi->installmentsCompleted($asOf);
                            $percent = $emi->progressPercent($asOf);
                            $current = $emi->installmentNumberFor($asOf);
                            $outstanding = $emi->outstandingAmount($asOf);
                            $futureCount = $emi->remainingAfterCurrentMonth($asOf);
                            $futureAmount = $futureCount * (float) $emi->amount;
                            $lastTwo = max($emi->installments - $completed, 0) <= 2;
                            $row = $payRows->get($emi->id);
                            $isDue = $emi->isDueIn($asOf);
                            $paidThisMonth = $row ? (float) $row['paid'] : 0.0;
                            $dueThisMonth = $row ? (float) $row['due'] : (float) $emi->amount;
                            $unpaidThisMonth = $isDue ? max($dueThisMonth - $paidThisMonth, 0.0) : 0.0;
                            $isPaid = $paidThisMonth > 0;
                            $isPaidFull = $isPaid && $paidThisMonth + 0.001 >= $dueThisMonth;
                            $isShort = $isPaid && ! $isPaidFull;
                            $lastMonthLabel = $emi->lastMonth()?->format('M Y');
                        @endphp

                        <x-card class="p-4 space-y-3">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="font-semibold text-white truncate">{{ $emi->name }}</p>
                                    <p class="text-xs text-brand-100/60">
                                        {{ $emi->payee ?: 'No bank set' }}
                                        &middot; EMI {{ number_format($emi->amount, 0) }}/mo
                                        <span class="text-[10px] font-semibold uppercase tracking-wide text-amber-200 bg-amber-400/10 px-1.5 py-0.5 rounded" title="Base EMI rate — change only via Edit schedule → Change amount">Locked</span>
                                    </p>
                                </div>
                                <div class="text-right shrink-0">
                                    @if ($outstanding < 0.001)
                                        <p class="text-sm font-bold text-green-200">0</p>
                                        <p class="text-[11px] text-green-300 font-semibold">cleared</p>
                                    @elseif ($isPaidFull && $futureCount > 0)
                                        <p class="text-sm font-bold text-white">{{ number_format($futureAmount, 0) }}</p>
                                        <p class="text-[11px] text-brand-100/60">{{ $futureCount === 1 ? 'due '.$lastMonthLabel : 'left after this month' }}</p>
                                    @elseif ($isDue)
                                        <p class="text-sm font-bold text-white">{{ number_format($unpaidThisMonth, 0) }}</p>
                                        <p class="text-[11px] text-brand-100/60">due this month</p>
                                        @if ($futureCount > 0)
                                            <p class="text-[11px] text-brand-100/60">{{ number_format($futureAmount, 0) }} after · ends {{ $lastMonthLabel }}</p>
                                        @endif
                                    @else
                                        <p class="text-sm font-bold text-white">{{ number_format($outstanding, 0) }}</p>
                                        <p class="text-[11px] text-brand-100/60">left</p>
                                    @endif
                                    <button type="button"
                                            @click="editingId = editingId === {{ $emi->id }} ? null : {{ $emi->id }}; adding = false; payEditId = null"
                                            class="text-xs font-semibold text-brand-500 hover:text-brand-300 mt-1">
                                        Edit schedule
                                    </button>
                                </div>
                            </div>

                            <div class="h-2 rounded-full bg-white/10 overflow-hidden">
                                <div class="h-full rounded-full {{ $percent >= 100 || $lastTwo ? 'bg-green-500' : 'bg-brand-400' }}"
                                     style="width: {{ min($percent, 100) }}%"></div>
                            </div>

                            <div class="flex items-center justify-between text-xs">
                                <span class="text-brand-100/70">
                                    @if ($isPaidFull && $current)
                                        Installment <span class="font-semibold">{{ $current }}</span> of {{ $emi->installments }}
                                        <span class="text-green-200 font-semibold">· paid</span>
                                    @elseif ($current)
                                        Installment <span class="font-semibold">{{ $current }}</span> of {{ $emi->installments }}
                                    @else
                                        {{ $completed }} of {{ $emi->installments }} done
                                    @endif
                                </span>
                                <span class="{{ $lastTwo ? 'text-green-300 font-semibold' : 'text-brand-100/60' }}">
                                    @if ($isDue && $futureCount === 0)
                                        this month due only
                                    @else
                                        ends {{ $lastMonthLabel }}
                                    @endif
                                </span>
                            </div>

                            <div class="pt-2 border-t border-white/10 text-[11px] text-brand-100/60 space-y-0.5">
                                <div class="flex items-center justify-between gap-2">
                                    <span>Progress {{ number_format($emi->scheduledPaidAmount($asOf), 0) }} of {{ number_format($emi->totalCommitment(), 0) }}</span>
                                    <span title="Sum of payments logged in this app (may be less than schedule if older months were never entered)">App logged {{ number_format($emi->recordedPaid(), 0) }}</span>
                                </div>
                            </div>

                            @if ($isDue && $row)
                                <div class="pt-2 border-t border-white/10 space-y-2">
                                    @if ($isPaidFull)
                                        <div x-show="payEditId !== {{ $emi->id }}" class="flex items-center justify-between gap-2 rounded-md bg-green-400/10 px-3 py-2">
                                            <div class="min-w-0">
                                                <p class="text-sm font-semibold text-green-200">Paid this month</p>
                                                <p class="text-xs text-green-200">{{ number_format($paidThisMonth, 0) }} recorded · EMI rate stays {{ number_format($dueThisMonth, 0) }}/mo{{ $futureCount === 1 ? ' · last installment '.$lastMonthLabel : ($futureCount > 0 ? ' · '.$futureCount.' left after this' : '') }}</p>
                                            </div>
                                            <button type="button" @click="payEditId = {{ $emi->id }}"
                                                    class="shrink-0 text-xs font-semibold text-green-200 hover:text-green-200 min-h-[44px]">
                                                Adjust
                                            </button>
                                        </div>
                                    @elseif ($isShort)
                                        <div x-show="payEditId !== {{ $emi->id }}" class="flex items-center justify-between gap-2 rounded-md bg-amber-400/10 px-3 py-2">
                                            <div class="min-w-0">
                                                <p class="text-sm font-semibold text-amber-200">Partial this month</p>
                                                <p class="text-xs text-amber-200">{{ number_format($paidThisMonth, 0) }} of {{ number_format($dueThisMonth, 0) }} · {{ number_format($dueThisMonth - $paidThisMonth, 0) }} short</p>
                                            </div>
                                            <button type="button" @click="payEditId = {{ $emi->id }}"
                                                    class="shrink-0 text-xs font-semibold text-amber-200 hover:text-amber-200 min-h-[44px]">
                                                Adjust
                                            </button>
                                        </div>
                                    @endif

                                    {{--
                                        @if/@endif inline in a component tag's attribute list (as used
                                        on the plain <form> elements elsewhere in this file) is not
                                        supported by Blade's component-tag compiler the way it is on a
                                        plain HTML tag -- the whole tag is duplicated per branch instead,
                                        so x-cloak (a bare presence attribute, not a bindable one) is only
                                        ever present when there is something to toggle.
                                    --}}
                                    @if ($isPaid)
                                        <x-pay-row :action="route('emi.pay', $emi)" :month="$asOf->format('Y-m-d')"
                                                   :due="$dueThisMonth" :paid="$paidThisMonth"
                                                   stacked label="This month’s payment" hint="not the locked EMI rate"
                                                   pay-label="Record pay" update-label="Save"
                                                   x-show="payEditId === {{ $emi->id }}" x-cloak>
                                            <button type="button" @click="payEditId = null" class="text-xs font-semibold text-brand-100/60 hover:text-brand-100/80">Cancel</button>
                                        </x-pay-row>
                                    @else
                                        <x-pay-row :action="route('emi.pay', $emi)" :month="$asOf->format('Y-m-d')"
                                                   :due="$dueThisMonth" :paid="$paidThisMonth"
                                                   stacked label="This month’s payment" hint="not the locked EMI rate"
                                                   pay-label="Record pay" update-label="Save">
                                            <p class="text-[11px] text-brand-100/60">Record what you paid this month. Due {{ number_format($dueThisMonth, 0) }} (locked EMI rate unchanged).</p>
                                        </x-pay-row>
                                    @endif
                                </div>
                            @elseif (! $isDue)
                                <p class="pt-2 border-t border-white/10 text-xs text-brand-100/60">Not due in {{ $asOf->format('F Y') }}.</p>
                            @endif

                            <div x-show="editingId === {{ $emi->id }}" x-cloak>
                                @include('emi._form', ['emi' => $emi])
                            </div>

                            <div class="mt-2 flex items-center justify-end gap-3">
                                <form method="POST" action="{{ route('emi.destroy', $emi) }}"
                                      onsubmit="return confirm('Delete {{ $emi->name }}? Any recorded payments against it go too.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="min-h-[44px] px-2 text-xs font-semibold text-red-300 hover:text-red-200">
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </x-card>
                    @endforeach
                </div>
            @endif
        </div>

        @if ($finished->isNotEmpty())
            <div>
                <h3 class="font-semibold text-white mb-3">Cleared ({{ $finished->count() }})</h3>
                <x-card class="divide-y divide-white/10">
                    @foreach ($finished as $emi)
                        @php
                            $clearedThisMonth = $emi->isDueIn($asOf) && $emi->isPaidInFullFor($asOf);
                        @endphp
                        <div class="p-3 flex items-center justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-brand-100/80 truncate">{{ $emi->name }}</p>
                                <p class="text-xs text-brand-100/60">
                                    {{ $emi->payee }}
                                    &middot; EMI {{ number_format($emi->amount, 0) }}/mo
                                    &middot; {{ $clearedThisMonth ? 'paid & cleared '.$asOf->format('M Y') : 'finished '.$emi->lastMonth()?->format('M Y') }}
                                </p>
                            </div>
                            <span class="shrink-0 text-xs font-semibold text-green-200 bg-green-400/10 px-2 py-1 rounded-full">Cleared</span>
                        </div>
                    @endforeach
                </x-card>
            </div>
        @endif
    </div>
</x-app-layout>
