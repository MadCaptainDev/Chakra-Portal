@php $emi = $emi ?? null; @endphp

{{-- x-data must live on a plain element; @if inside <x-card> breaks Blade component compilation. --}}
<div @if ($emi) x-data="{ unlocking: {{ old('unlock_amount') ? 'true' : 'false' }} }" @endif>
    <x-card class="p-4 sm:p-6">
        <form method="POST" action="{{ $emi ? route('emi.update', $emi) : route('emi.store') }}">
            @csrf
            @if ($emi)
                @method('PUT')
            @endif

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
                <div class="sm:col-span-2">
                    <x-input-label for="emi_name_{{ $emi?->id ?? 'new' }}" value="Item" />
                    <x-text-input id="emi_name_{{ $emi?->id ?? 'new' }}" name="name" type="text" class="mt-1 block w-full"
                                  value="{{ old('name', $emi->name ?? '') }}" placeholder="e.g. Gimbal" required />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="emi_payee_{{ $emi?->id ?? 'new' }}" value="Bank" />
                    <x-text-input id="emi_payee_{{ $emi?->id ?? 'new' }}" name="payee" type="text" class="mt-1 block w-full"
                                  value="{{ old('payee', $emi->payee ?? '') }}" placeholder="e.g. Axis" />
                    <x-input-error :messages="$errors->get('payee')" class="mt-2" />
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-4">
                <div>
                    <x-input-label for="emi_amount_{{ $emi?->id ?? 'new' }}" value="Monthly Amount" />
                    @if ($emi)
                        <template x-if="! unlocking">
                            <div class="mt-1">
                                <div class="flex flex-col gap-1 min-h-[44px] justify-center">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <p class="text-lg font-semibold text-gray-900">{{ number_format((float) $emi->amount, 2) }}</p>
                                        <span class="text-[11px] font-semibold uppercase tracking-wide text-amber-700 bg-amber-50 px-2 py-0.5 rounded">Locked</span>
                                    </div>
                                    <button type="button" @click="unlocking = true" class="text-xs font-semibold text-brand-500 hover:text-brand-600 text-left">Change amount</button>
                                </div>
                            </div>
                        </template>
                        <template x-if="unlocking">
                            <div class="mt-1 space-y-2">
                                <input type="hidden" name="unlock_amount" value="1">
                                <x-text-input id="emi_amount_{{ $emi->id }}" name="amount" type="number" step="0.01" min="0"
                                              class="block w-full" value="{{ old('amount', $emi->amount) }}" required />
                                <label class="inline-flex items-start gap-2 text-sm text-gray-700">
                                    <input type="checkbox" name="confirm_amount_change" value="1" required
                                           class="mt-1 rounded border-gray-300 text-brand-500 focus:ring-brand-400"
                                           @checked(old('confirm_amount_change'))>
                                    <span>I confirm changing this locked EMI amount.</span>
                                </label>
                                <button type="button" @click="unlocking = false" class="text-xs font-semibold text-gray-500 hover:text-gray-700">Keep locked</button>
                            </div>
                        </template>
                    @else
                        <x-text-input id="emi_amount_new" name="amount" type="number" step="0.01" min="0"
                                      class="mt-1 block w-full" value="{{ old('amount') }}" required />
                    @endif
                    <x-input-error :messages="$errors->get('amount')" class="mt-2" />
                    <x-input-error :messages="$errors->get('confirm_amount_change')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="emi_start_{{ $emi?->id ?? 'new' }}" value="First Month" />
                    <x-text-input id="emi_start_{{ $emi?->id ?? 'new' }}" name="start_month" type="month" class="mt-1 block w-full"
                                  value="{{ old('start_month', $emi?->start_month?->format('Y-m') ?? '') }}" required />
                    <x-input-error :messages="$errors->get('start_month')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="emi_installments_{{ $emi?->id ?? 'new' }}" value="Installments" />
                    <x-text-input id="emi_installments_{{ $emi?->id ?? 'new' }}" name="installments" type="number" min="1"
                                  class="mt-1 block w-full" value="{{ old('installments', $emi->installments ?? '') }}" required />
                    <x-input-error :messages="$errors->get('installments')" class="mt-2" />
                </div>

                <div class="flex items-end gap-2">
                    <x-primary-button>{{ $emi ? 'Save EMI' : 'Add EMI' }}</x-primary-button>
                    @if ($emi)
                        <button type="button" form="emi-delete-{{ $emi->id }}"
                                onclick="return confirm('Delete {{ $emi->name }}?')"
                                class="inline-flex items-center justify-center min-h-[44px] px-3 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-red-600 uppercase tracking-widest hover:bg-gray-50">
                            Delete
                        </button>
                    @endif
                </div>
            </div>
        </form>

        @if ($emi)
            <form id="emi-delete-{{ $emi->id }}" method="POST" action="{{ route('emi.destroy', $emi) }}" class="hidden">
                @csrf
                @method('DELETE')
            </form>
        @endif
    </x-card>
</div>
