<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="$employee->name">
            <x-slot name="actions">
                <a href="{{ route('salaries.index') }}" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-white/5 border border-white/15 rounded-md font-semibold text-xs text-brand-100/80 uppercase tracking-widest hover:bg-white/[0.09]">
                    Back to Payroll
                </a>
                <form method="POST" action="{{ route('salaries.destroy', $employee) }}" onsubmit="return confirm('Remove {{ $employee->name }} and their entire payment history?');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-white/5 border border-white/15 rounded-md font-semibold text-xs text-red-300 uppercase tracking-widest hover:bg-white/[0.09]">
                        Remove
                    </button>
                </form>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="max-w-3xl space-y-6" x-data="{
        editing: {{ $errors->any() && ! $errors->hasBag('hike') ? 'true' : 'false' }},
        hiking: {{ $errors->hasBag('hike') ? 'true' : 'false' }},
    }">
        @include('expenses._tabs')

        <x-card class="p-4 sm:p-6">
            <div class="flex items-start gap-4">
                <x-avatar :name="$employee->name" :src="$employee->user?->avatarUrl()" size="lg" />

                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2 flex-wrap">
                        <h3 class="text-lg font-semibold text-white">{{ $employee->name }}</h3>
                        <x-badge :status="$employee->is_active ? 'active' : 'inactive'" />
                    </div>
                    <p class="text-sm text-brand-100/60">{{ $employee->role ?: 'No role set' }}</p>
                    @if ($employee->user?->bio)
                        <p class="text-sm text-brand-100/70 mt-2 whitespace-pre-line">{{ $employee->user->bio }}</p>
                    @endif

                    <dl class="grid grid-cols-2 sm:grid-cols-4 gap-4 mt-4 text-sm">
                        <div>
                            <dt class="text-brand-100/60">Salary</dt>
                            <dd class="text-white font-semibold">
                                {{ number_format($employee->amount, 2) }}/mo
                                <span class="ml-1 text-[10px] font-semibold uppercase tracking-wide text-amber-200 bg-amber-400/10 px-1.5 py-0.5 rounded">Locked</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-brand-100/60">Joined</dt>
                            <dd class="text-white">{{ $employee->joined_on?->format('d M Y') ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-brand-100/60">Phone</dt>
                            <dd class="text-white">{{ $employee->user?->phone ?: $employee->phone ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-brand-100/60">Paid to date</dt>
                            <dd class="text-white font-semibold">{{ number_format($totalPaid, 2) }}</dd>
                        </div>
                    </dl>
                </div>

                <div class="shrink-0 flex flex-col items-end gap-2">
                    <button type="button" @click="editing = ! editing" class="text-sm font-semibold text-brand-500 hover:text-brand-300 min-h-[44px]">
                        <span x-show="! editing">Edit</span>
                        <span x-show="editing" x-cloak>Cancel</span>
                    </button>
                    @can('salaries.edit')
                        <button type="button" @click="hiking = ! hiking" class="text-sm font-semibold text-green-400 hover:text-green-300 min-h-[44px]">
                            <span x-show="! hiking">Give a hike</span>
                            <span x-show="hiking" x-cloak>Cancel hike</span>
                        </button>
                    @endcan
                </div>
            </div>
            @if ($employee->user)
                <div class="mt-3 pt-3 border-t border-white/10">
                    <a href="{{ route('users.edit', $employee->user) }}" class="text-sm font-semibold text-brand-500 hover:text-brand-300">
                        Edit full login profile &rarr;
                    </a>
                </div>
            @endif
        </x-card>

        <div x-show="editing" x-cloak>
            @include('salaries._form', ['employee' => $employee])
        </div>

        @can('salaries.edit')
            <div x-show="hiking" x-cloak>
                <x-card class="p-4 sm:p-6">
                    <h4 class="text-sm font-semibold text-white mb-3">Give {{ $employee->name }} a hike</h4>
                    <form method="POST" action="{{ route('salaries.hike', $employee) }}">
                        @csrf
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
                            <div>
                                <x-input-label for="hike_amount" value="New Monthly Salary" />
                                <x-text-input id="hike_amount" name="new_amount" type="number" step="0.01" min="0"
                                              class="mt-1 block w-full"
                                              value="{{ old('new_amount', $employee->amount) }}" required />
                                <x-input-error :messages="$errors->getBag('hike')->get('new_amount')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="hike_effective" value="Effective From" />
                                <x-text-input id="hike_effective" name="effective_on" type="date" class="mt-1 block w-full"
                                              value="{{ old('effective_on', now()->toDateString()) }}" />
                                <x-input-error :messages="$errors->getBag('hike')->get('effective_on')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="hike_reason" value="Reason (optional)" />
                                <x-text-input id="hike_reason" name="reason" type="text" class="mt-1 block w-full"
                                              value="{{ old('reason') }}" placeholder="e.g. Annual review" />
                                <x-input-error :messages="$errors->getBag('hike')->get('reason')" class="mt-2" />
                            </div>
                        </div>
                        <x-primary-button>Apply Hike</x-primary-button>
                    </form>
                </x-card>
            </div>
        @endcan

        @if ($hikes->isNotEmpty())
            <div>
                <h3 class="font-semibold text-white mb-3">Salary History</h3>
                <x-card class="divide-y divide-white/10">
                    @foreach ($hikes as $hike)
                        <div class="p-3 sm:p-4 flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-medium text-white">
                                    {{ number_format($hike->previous_amount, 2) }} &rarr; {{ number_format($hike->new_amount, 2) }}
                                </p>
                                <p class="text-xs text-brand-100/60">
                                    Effective {{ $hike->effective_on->format('d M Y') }}
                                    @if ($hike->reason)
                                        &middot; {{ $hike->reason }}
                                    @endif
                                    @if ($hike->createdBy)
                                        &middot; by {{ $hike->createdBy->name }}
                                    @endif
                                </p>
                            </div>
                            <p @class([
                                'font-semibold shrink-0',
                                'text-green-400' => $hike->delta() > 0,
                                'text-red-300' => $hike->delta() < 0,
                            ])>
                                {{ $hike->delta() > 0 ? '+' : '' }}{{ number_format($hike->delta(), 2) }}
                            </p>
                        </div>
                    @endforeach
                </x-card>
            </div>
        @endif

        <div>
            <h3 class="font-semibold text-white mb-3">Payment History</h3>

            @if ($history->isEmpty())
                <x-empty-state message="No payments recorded yet.">
                    <a href="{{ route('salaries.index') }}" class="text-brand-500 font-semibold text-sm hover:text-brand-300">Go to this month's payroll &rarr;</a>
                </x-empty-state>
            @else
                <x-card class="divide-y divide-white/10">
                    @foreach ($history as $payment)
                        @php $short = (float) $payment->amount_paid + 0.001 < (float) $employee->amount; @endphp
                        <div class="p-3 sm:p-4 flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-medium text-white">{{ $payment->period->format('F Y') }}</p>
                                <p class="text-xs text-brand-100/60">
                                    {{ $payment->paid_on ? 'Paid '.$payment->paid_on->format('d M Y') : 'No date recorded' }}
                                    @if ($short)
                                        <span class="text-amber-300 font-semibold">&middot; short by {{ number_format((float) $employee->amount - (float) $payment->amount_paid, 0) }}</span>
                                    @endif
                                </p>
                            </div>
                            <div class="flex items-center gap-3 shrink-0">
                                <p class="font-semibold text-white">{{ number_format($payment->amount_paid, 2) }}</p>
                                <a href="{{ route('salaries.payslip', [$employee, $payment->period->format('Y-m')]) }}"
                                   class="text-xs font-semibold text-brand-500 hover:text-brand-300 uppercase tracking-wide">
                                    Payslip
                                </a>
                            </div>
                        </div>
                    @endforeach
                </x-card>
            @endif
        </div>
    </div>
</x-app-layout>
