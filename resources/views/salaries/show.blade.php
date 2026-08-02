<x-app-layout>
    <x-slot name="header">
        <x-page-header :title="$employee->name">
            <x-slot name="actions">
                <a href="{{ route('salaries.index') }}" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50">
                    Back to Payroll
                </a>
                <form method="POST" action="{{ route('salaries.destroy', $employee) }}" onsubmit="return confirm('Remove {{ $employee->name }} and their entire payment history?');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-red-600 uppercase tracking-widest hover:bg-gray-50">
                        Remove
                    </button>
                </form>
            </x-slot>
        </x-page-header>
    </x-slot>

    <div class="max-w-3xl space-y-6" x-data="{ editing: false }">
        {{-- Record card --}}
        <x-card class="p-4 sm:p-6">
            <div class="flex items-start gap-4">
                <x-avatar :name="$employee->name" size="lg" />

                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2 flex-wrap">
                        <h3 class="text-lg font-semibold text-gray-900">{{ $employee->name }}</h3>
                        <x-badge :status="$employee->is_active ? 'active' : 'inactive'" />
                    </div>
                    <p class="text-sm text-gray-500">{{ $employee->role ?: 'No role set' }}</p>

                    <dl class="grid grid-cols-2 sm:grid-cols-4 gap-4 mt-4 text-sm">
                        <div>
                            <dt class="text-gray-500">Salary</dt>
                            <dd class="text-gray-900 font-semibold">{{ number_format($employee->amount, 2) }}/mo</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Joined</dt>
                            <dd class="text-gray-900">{{ $employee->joined_on?->format('d M Y') ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Phone</dt>
                            <dd class="text-gray-900">{{ $employee->phone ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500">Paid to date</dt>
                            <dd class="text-gray-900 font-semibold">{{ number_format($totalPaid, 2) }}</dd>
                        </div>
                    </dl>
                </div>

                <button type="button" @click="editing = ! editing" class="shrink-0 text-sm font-semibold text-brand-500 hover:text-brand-600 min-h-[44px]">
                    <span x-show="! editing">Edit</span>
                    <span x-show="editing" x-cloak>Cancel</span>
                </button>
            </div>
        </x-card>

        <div x-show="editing" x-cloak>
            @include('salaries._form', ['employee' => $employee])
        </div>

        {{-- Payment history --}}
        <div>
            <h3 class="font-semibold text-gray-900 mb-3">Payment History</h3>

            @if ($history->isEmpty())
                <x-empty-state message="No payments recorded yet.">
                    <a href="{{ route('salaries.index') }}" class="text-brand-500 font-semibold text-sm hover:text-brand-600">Go to this month's payroll &rarr;</a>
                </x-empty-state>
            @else
                <x-card class="divide-y divide-gray-200">
                    @foreach ($history as $payment)
                        @php $short = (float) $payment->amount_paid + 0.001 < (float) $employee->amount; @endphp
                        <div class="p-3 sm:p-4 flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-medium text-gray-900">{{ $payment->period->format('F Y') }}</p>
                                <p class="text-xs text-gray-500">
                                    {{ $payment->paid_on ? 'Paid '.$payment->paid_on->format('d M Y') : 'No date recorded' }}
                                    @if ($short)
                                        <span class="text-amber-600 font-semibold">&middot; short by {{ number_format((float) $employee->amount - (float) $payment->amount_paid, 0) }}</span>
                                    @endif
                                </p>
                            </div>
                            <p class="font-semibold text-gray-900 shrink-0">{{ number_format($payment->amount_paid, 2) }}</p>
                        </div>
                    @endforeach
                </x-card>
            @endif
        </div>
    </div>
</x-app-layout>
