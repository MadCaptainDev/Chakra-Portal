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
                <p class="text-xs text-gray-500 uppercase tracking-wide">Total Outstanding</p>
                <p class="text-2xl sm:text-3xl font-bold text-gray-900">{{ number_format($outstanding, 2) }}</p>
                <p class="text-xs text-gray-500 mt-1">across {{ $running->count() }} running EMI{{ $running->count() === 1 ? '' : 's' }}</p>
            </x-card>

            <x-card class="p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wide">This Month Due</p>
                <p class="text-2xl sm:text-3xl font-bold text-brand-600">{{ number_format($monthlyLoad, 2) }}</p>
                <p class="text-xs text-gray-500 mt-1">Paid {{ number_format($monthlyPaid, 2) }}</p>
            </x-card>

            <x-card class="p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wide mb-2">By Bank</p>
                <div class="space-y-1">
                    @forelse ($byBank as $bank)
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-gray-600 truncate">{{ $bank['bank'] }}</span>
                            <span class="font-semibold text-gray-900 shrink-0 ml-2">{{ number_format($bank['outstanding'], 0) }}</span>
                        </div>
                    @empty
                        <p class="text-sm text-gray-400">Nothing outstanding</p>
                    @endforelse
                </div>
            </x-card>
        </div>

        @if (! empty($timeline))
            <x-card class="p-4 sm:p-6">
                <h3 class="font-semibold text-gray-900">Payoff Timeline</h3>
                <p class="text-xs text-gray-500 mb-4">Monthly EMI load from now until the last installment clears.</p>

                <div class="overflow-x-auto -mx-1 px-1">
                    <div class="flex items-end gap-1 min-w-max h-40">
                        @foreach ($timeline as $point)
                            <div class="flex flex-col items-center justify-end h-full group" style="width: 34px">
                                <span class="text-[9px] text-gray-500 mb-1 whitespace-nowrap">
                                    {{ $point['total'] > 0 ? number_format($point['total'] / 1000, 1) . 'k' : '' }}
                                </span>
                                <div class="w-full rounded-t bg-brand-400 group-hover:bg-brand-500 transition-colors"
                                     style="height: {{ max($point['percent'], 1) }}%"
                                     title="{{ $point['month']->format('F Y') }} — {{ number_format($point['total'], 2) }}"></div>
                                <span class="text-[9px] text-gray-500 mt-1 whitespace-nowrap">{{ $point['month']->format('M') }}</span>
                                @if ($point['month']->month === 1 || $loop->first)
                                    <span class="text-[9px] font-semibold text-gray-400">{{ $point['month']->format('y') }}</span>
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
                <h3 class="font-semibold text-gray-900">Running ({{ $running->count() }})</h3>
                <button type="button" @click="adding = ! adding; editingId = null; payEditId = null"
                        class="text-sm font-semibold text-brand-500 hover:text-brand-600 min-h-[44px]">
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
                            $lastTwo = max($emi->installments - $completed, 0) <= 2;
                            $row = $payRows->get($emi->id);
                            $isDue = $emi->isDueIn($asOf);
                            $paidThisMonth = $row ? (float) $row['paid'] : 0.0;
                            $dueThisMonth = $row ? (float) $row['due'] : (float) $emi->amount;
                            $isPaid = $paidThisMonth > 0;
                            $isPaidFull = $isPaid && $paidThisMonth + 0.001 >= $dueThisMonth;
                            $isShort = $isPaid && ! $isPaidFull;
                        @endphp

                        <x-card class="p-4 space-y-3">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="font-semibold text-gray-900 truncate">{{ $emi->name }}</p>
                                    <p class="text-xs text-gray-500">
                                        {{ $emi->payee ?: 'No bank set' }}
                                        &middot; EMI {{ number_format($emi->amount, 0) }}/mo
                                        <span class="text-[10px] font-semibold uppercase tracking-wide text-amber-700 bg-amber-50 px-1.5 py-0.5 rounded" title="Base EMI rate — change only via Edit schedule → Change amount">Locked</span>
                                    </p>
                                </div>
                                <div class="text-right shrink-0">
                                    @if ($outstanding < 0.001)
                                        <p class="text-sm font-bold text-green-700">0</p>
                                        <p class="text-[11px] text-green-600 font-semibold">cleared</p>
                                    @else
                                        <p class="text-sm font-bold text-gray-900">{{ number_format($outstanding, 0) }}</p>
                                        <p class="text-[11px] text-gray-500">left</p>
                                    @endif
                                    <button type="button"
                                            @click="editingId = editingId === {{ $emi->id }} ? null : {{ $emi->id }}; adding = false; payEditId = null"
                                            class="text-xs font-semibold text-brand-500 hover:text-brand-600 mt-1">
                                        Edit schedule
                                    </button>
                                </div>
                            </div>

                            <div class="h-2 rounded-full bg-gray-100 overflow-hidden">
                                <div class="h-full rounded-full {{ $percent >= 100 || $lastTwo ? 'bg-green-500' : 'bg-brand-400' }}"
                                     style="width: {{ min($percent, 100) }}%"></div>
                            </div>

                            <div class="flex items-center justify-between text-xs">
                                <span class="text-gray-600">
                                    @if ($isPaidFull && $current)
                                        Installment <span class="font-semibold">{{ $current }}</span> of {{ $emi->installments }}
                                        <span class="text-green-700 font-semibold">· paid</span>
                                    @elseif ($current)
                                        Installment <span class="font-semibold">{{ $current }}</span> of {{ $emi->installments }}
                                    @else
                                        {{ $completed }} of {{ $emi->installments }} done
                                    @endif
                                </span>
                                <span class="{{ $lastTwo ? 'text-green-600 font-semibold' : 'text-gray-500' }}">
                                    ends {{ $emi->lastMonth()?->format('M Y') }}
                                </span>
                            </div>

                            <div class="pt-2 border-t border-gray-100 text-[11px] text-gray-500 space-y-0.5">
                                <div class="flex items-center justify-between gap-2">
                                    <span>Progress {{ number_format($emi->scheduledPaidAmount($asOf), 0) }} of {{ number_format($emi->totalCommitment(), 0) }}</span>
                                    <span title="Sum of payments logged in this app (may be less than schedule if older months were never entered)">App logged {{ number_format($emi->recordedPaid(), 0) }}</span>
                                </div>
                            </div>

                            @if ($isDue && $row)
                                <div class="pt-2 border-t border-gray-100 space-y-2">
                                    @if ($isPaidFull)
                                        <div x-show="payEditId !== {{ $emi->id }}" class="flex items-center justify-between gap-2 rounded-md bg-green-50 px-3 py-2">
                                            <div class="min-w-0">
                                                <p class="text-sm font-semibold text-green-800">Paid this month</p>
                                                <p class="text-xs text-green-700">{{ number_format($paidThisMonth, 0) }} recorded · EMI rate stays {{ number_format($dueThisMonth, 0) }}/mo</p>
                                            </div>
                                            <button type="button" @click="payEditId = {{ $emi->id }}"
                                                    class="shrink-0 text-xs font-semibold text-green-800 hover:text-green-900 min-h-[44px]">
                                                Adjust
                                            </button>
                                        </div>
                                    @elseif ($isShort)
                                        <div x-show="payEditId !== {{ $emi->id }}" class="flex items-center justify-between gap-2 rounded-md bg-amber-50 px-3 py-2">
                                            <div class="min-w-0">
                                                <p class="text-sm font-semibold text-amber-800">Partial this month</p>
                                                <p class="text-xs text-amber-700">{{ number_format($paidThisMonth, 0) }} of {{ number_format($dueThisMonth, 0) }} · {{ number_format($dueThisMonth - $paidThisMonth, 0) }} short</p>
                                            </div>
                                            <button type="button" @click="payEditId = {{ $emi->id }}"
                                                    class="shrink-0 text-xs font-semibold text-amber-800 hover:text-amber-900 min-h-[44px]">
                                                Adjust
                                            </button>
                                        </div>
                                    @endif

                                    <form method="POST" action="{{ route('emi.pay', $emi) }}"
                                          class="space-y-2"
                                          @if ($isPaid) x-show="payEditId === {{ $emi->id }}" x-cloak @endif>
                                        @csrf
                                        <input type="hidden" name="month" value="{{ $asOf->format('Y-m-d') }}">
                                        <div class="flex items-end gap-2">
                                            <div class="min-w-0 flex-1">
                                                <label class="block text-xs font-semibold text-gray-700 mb-1">
                                                    This month’s payment
                                                    <span class="font-normal text-gray-500">(not the locked EMI rate)</span>
                                                </label>
                                                <input type="number" step="0.01" min="0" name="amount_paid"
                                                       value="{{ $isPaid ? number_format($paidThisMonth, 2, '.', '') : number_format($dueThisMonth, 2, '.', '') }}"
                                                       placeholder="{{ number_format($dueThisMonth, 2, '.', '') }}"
                                                       class="w-full rounded-md border-gray-300 shadow-sm text-sm text-right focus:border-brand-400 focus:ring-brand-400 min-h-[44px]">
                                            </div>
                                            <button type="submit"
                                                    class="min-h-[44px] px-3 rounded-md text-xs font-semibold uppercase tracking-wider shrink-0 {{ $isPaid ? 'bg-green-100 text-green-800 hover:bg-green-200' : 'bg-brand-400 text-brand-900 hover:bg-brand-500' }}">
                                                {{ $isPaid ? 'Save' : 'Record pay' }}
                                            </button>
                                        </div>
                                        @if ($isPaid)
                                            <button type="button" @click="payEditId = null" class="text-xs font-semibold text-gray-500 hover:text-gray-700">Cancel</button>
                                        @else
                                            <p class="text-[11px] text-gray-500">Record what you paid this month. Due {{ number_format($dueThisMonth, 0) }} (locked EMI rate unchanged).</p>
                                        @endif
                                    </form>
                                </div>
                            @elseif (! $isDue)
                                <p class="pt-2 border-t border-gray-100 text-xs text-gray-500">Not due in {{ $asOf->format('F Y') }}.</p>
                            @endif

                            <div x-show="editingId === {{ $emi->id }}" x-cloak>
                                @include('emi._form', ['emi' => $emi])
                            </div>

                            <div class="mt-2 flex items-center justify-end gap-3">
                                <form method="POST" action="{{ route('emi.destroy', $emi) }}"
                                      onsubmit="return confirm('Delete {{ $emi->name }}? Any recorded payments against it go too.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="min-h-[44px] px-2 text-xs font-semibold text-red-600 hover:text-red-800">
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
                <h3 class="font-semibold text-gray-900 mb-3">Cleared ({{ $finished->count() }})</h3>
                <x-card class="divide-y divide-gray-200">
                    @foreach ($finished as $emi)
                        @php
                            $clearedThisMonth = $emi->isDueIn($asOf) && $emi->isPaidInFullFor($asOf);
                        @endphp
                        <div class="p-3 flex items-center justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-700 truncate">{{ $emi->name }}</p>
                                <p class="text-xs text-gray-500">
                                    {{ $emi->payee }}
                                    &middot; EMI {{ number_format($emi->amount, 0) }}/mo
                                    &middot; {{ $clearedThisMonth ? 'paid & cleared '.$asOf->format('M Y') : 'finished '.$emi->lastMonth()?->format('M Y') }}
                                </p>
                            </div>
                            <span class="shrink-0 text-xs font-semibold text-green-700 bg-green-50 px-2 py-1 rounded-full">Cleared</span>
                        </div>
                    @endforeach
                </x-card>
            </div>
        @endif
    </div>
</x-app-layout>
