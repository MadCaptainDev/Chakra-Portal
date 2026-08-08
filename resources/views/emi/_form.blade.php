@php
    // Renders bare -- the caller supplies the card chrome, so this can sit
    // inside an existing card when editing without nesting two of them.
    $emi = $emi ?? null;
    $uid = $emi?->id ?? 'new';
@endphp

{{-- x-data sits on the form itself: it is a plain element, whereas an @if
     inside <x-card> breaks Blade component compilation. --}}
<form method="POST" action="{{ $emi ? route('emi.update', $emi) : route('emi.store') }}"
      @if ($emi) x-data="{ unlocking: {{ old('unlock_amount') ? 'true' : 'false' }} }" @endif>
    @csrf
    @if ($emi)
        @method('PUT')
    @endif

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
        <div class="sm:col-span-2">
            <x-input-label :for="'emi_name_'.$uid" value="Item" />
            <x-text-input :id="'emi_name_'.$uid" name="name" type="text" class="mt-1 block w-full"
                          value="{{ old('name', $emi->name ?? '') }}" placeholder="e.g. Gimbal" required />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <div>
            <x-input-label :for="'emi_payee_'.$uid" value="Bank" />
            <x-text-input :id="'emi_payee_'.$uid" name="payee" type="text" class="mt-1 block w-full"
                          value="{{ old('payee', $emi->payee ?? '') }}" placeholder="e.g. Axis" />
            <x-input-error :messages="$errors->get('payee')" class="mt-2" />
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
        <div>
            <x-input-label :for="'emi_amount_'.$uid" value="Monthly Amount" />
            {{-- The base EMI rate is locked after create; changing it takes an
                 explicit unlock plus a confirmation (see LocksExpenseAmount). --}}
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
                        <x-text-input :id="'emi_amount_'.$uid" name="amount" type="number" step="0.01" min="0"
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
                <x-text-input :id="'emi_amount_'.$uid" name="amount" type="number" step="0.01" min="0"
                              class="mt-1 block w-full" value="{{ old('amount') }}" required />
            @endif
            <x-input-error :messages="$errors->get('amount')" class="mt-2" />
            <x-input-error :messages="$errors->get('confirm_amount_change')" class="mt-2" />
        </div>

        <div>
            <x-input-label :for="'emi_start_'.$uid" value="First Month" />
            <x-text-input :id="'emi_start_'.$uid" name="start_month" type="month" class="mt-1 block w-full"
                          value="{{ old('start_month', $emi?->start_month?->format('Y-m')) }}" required />
            <x-input-error :messages="$errors->get('start_month')" class="mt-2" />
        </div>

        <div>
            <x-input-label :for="'emi_installments_'.$uid" value="Installments" />
            <x-text-input :id="'emi_installments_'.$uid" name="installments" type="number" min="1"
                          class="mt-1 block w-full" value="{{ old('installments', $emi?->installments) }}" required />
            <x-input-error :messages="$errors->get('installments')" class="mt-2" />
        </div>

        <div class="flex items-end">
            <x-primary-button>{{ $emi ? 'Save Changes' : 'Add EMI' }}</x-primary-button>
        </div>
    </div>
</form>
