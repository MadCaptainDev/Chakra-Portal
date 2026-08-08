@php
    // Renders bare -- the caller supplies the card chrome, so this can sit
    // inside an existing card when editing without nesting two of them.
    $emi = $emi ?? null;
    $uid = $emi?->id ?? 'new';
@endphp

<form method="POST" action="{{ $emi ? route('emi.update', $emi) : route('emi.store') }}">
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
            <x-text-input :id="'emi_amount_'.$uid" name="amount" type="number" step="0.01" min="0"
                          class="mt-1 block w-full" value="{{ old('amount', $emi?->amount) }}" required />
            <x-input-error :messages="$errors->get('amount')" class="mt-2" />
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
