<x-app-layout>
    <x-slot name="header">
        <x-page-header title="EMI & Finance">
            <x-slot name="actions">
                <a href="{{ route('expenses.index') }}" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50">
                    Month Overview
                </a>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="space-y-6" x-data="{ adding: false }">
        {{-- Headline liability --}}
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <x-card class="p-4 sm:col-span-1">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Total Outstanding</p>
                <p class="text-2xl sm:text-3xl font-bold text-gray-900">{{ number_format($outstanding, 2) }}</p>
                <p class="text-xs text-gray-500 mt-1">across {{ $running->count() }} running EMI{{ $running->count() === 1 ? '' : 's' }}</p>
            </x-card>

            <x-card class="p-4">
                <p class="text-xs text-gray-500 uppercase tracking-wide">This Month</p>
                <p class="text-2xl sm:text-3xl font-bold text-brand-600">{{ number_format($monthlyLoad, 2) }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ $asOf->format('F Y') }}</p>
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

        {{-- Payoff timeline --}}
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

        {{-- Running EMIs --}}
        <div>
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-semibold text-gray-900">Running ({{ $running->count() }})</h3>
                <button type="button" @click="adding = ! adding"
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
                            $elapsed = $emi->installmentsElapsed($asOf);
                            $percent = $emi->progressPercent($asOf);
                            $current = $emi->installmentNumberFor($asOf);
                            $lastTwo = $emi->remainingInstallments($asOf) <= 2;
                        @endphp

                        <x-card class="p-4" x-data="{ editing: false }">
                            <div class="flex items-start justify-between gap-2 mb-2">
                                <div class="min-w-0">
                                    <p class="font-semibold text-gray-900 truncate">{{ $emi->name }}</p>
                                    <p class="text-xs text-gray-500">
                                        {{ $emi->payee ?: 'No bank set' }} &middot; {{ number_format($emi->amount, 0) }}/mo
                                    </p>
                                </div>
                                <div class="text-right shrink-0">
                                    <p class="text-sm font-bold text-gray-900">{{ number_format($emi->outstandingAmount($asOf), 0) }}</p>
                                    <p class="text-[11px] text-gray-500">left</p>
                                </div>
                            </div>

                            <div class="h-2 rounded-full bg-gray-100 overflow-hidden">
                                <div class="h-full rounded-full {{ $lastTwo ? 'bg-green-500' : 'bg-brand-400' }}"
                                     style="width: {{ $percent }}%"></div>
                            </div>

                            <div class="mt-2 flex items-center justify-between text-xs">
                                <span class="text-gray-600">
                                    @if ($current)
                                        Installment <span class="font-semibold">{{ $current }}</span> of {{ $emi->installments }}
                                    @else
                                        {{ $elapsed }} of {{ $emi->installments }} done
                                    @endif
                                </span>
                                <span class="{{ $lastTwo ? 'text-green-600 font-semibold' : 'text-gray-500' }}">
                                    ends {{ $emi->lastMonth()?->format('M Y') }}
                                </span>
                            </div>

                            <div class="mt-2 pt-2 border-t border-gray-100 flex items-center justify-between text-[11px] text-gray-500">
                                <span>Scheduled paid {{ number_format($emi->scheduledPaidAmount($asOf), 0) }} of {{ number_format($emi->totalCommitment(), 0) }}</span>
                                <span>Recorded {{ number_format($emi->recordedPaid(), 0) }}</span>
                            </div>

                            <div class="mt-2 flex items-center justify-end gap-3">
                                <button type="button" @click="editing = ! editing"
                                        class="min-h-[44px] px-2 text-xs font-semibold text-brand-500 hover:text-brand-600">
                                    <span x-show="! editing">Edit</span>
                                    <span x-show="editing" x-cloak>Cancel</span>
                                </button>
                                <form method="POST" action="{{ route('emi.destroy', $emi) }}"
                                      onsubmit="return confirm('Delete {{ $emi->name }}? Any recorded payments against it go too.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="min-h-[44px] px-2 text-xs font-semibold text-red-600 hover:text-red-800">
                                        Delete
                                    </button>
                                </form>
                            </div>

                            <div x-show="editing" x-cloak class="mt-3 pt-3 border-t border-gray-200">
                                @include('emi._form', ['emi' => $emi])
                            </div>
                        </x-card>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Finished --}}
        @if ($finished->isNotEmpty())
            <div>
                <h3 class="font-semibold text-gray-900 mb-3">Cleared ({{ $finished->count() }})</h3>
                <x-card class="divide-y divide-gray-200">
                    @foreach ($finished as $emi)
                        <div class="p-3 flex items-center justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-700 truncate">{{ $emi->name }}</p>
                                <p class="text-xs text-gray-500">{{ $emi->payee }} &middot; finished {{ $emi->lastMonth()?->format('M Y') }}</p>
                            </div>
                            <span class="shrink-0 text-xs font-semibold text-green-700 bg-green-50 px-2 py-1 rounded-full">Cleared</span>
                        </div>
                    @endforeach
                </x-card>
            </div>
        @endif
    </div>
</x-app-layout>
