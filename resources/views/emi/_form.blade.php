<x-card class="p-4 sm:p-6">
    <form method="POST" action="{{ route('emi.store') }}">
        @csrf

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
            <div class="sm:col-span-2">
                <x-input-label for="emi_name" value="Item" />
                <x-text-input id="emi_name" name="name" type="text" class="mt-1 block w-full"
                              value="{{ old('name') }}" placeholder="e.g. Gimbal" required />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="emi_payee" value="Bank" />
                <x-text-input id="emi_payee" name="payee" type="text" class="mt-1 block w-full"
                              value="{{ old('payee') }}" placeholder="e.g. Axis" />
                <x-input-error :messages="$errors->get('payee')" class="mt-2" />
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-4">
            <div>
                <x-input-label for="emi_amount" value="Monthly Amount" />
                <x-text-input id="emi_amount" name="amount" type="number" step="0.01" min="0"
                              class="mt-1 block w-full" value="{{ old('amount') }}" required />
                <x-input-error :messages="$errors->get('amount')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="emi_start" value="First Month" />
                <x-text-input id="emi_start" name="start_month" type="month" class="mt-1 block w-full"
                              value="{{ old('start_month') }}" required />
                <x-input-error :messages="$errors->get('start_month')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="emi_installments" value="Installments" />
                <x-text-input id="emi_installments" name="installments" type="number" min="1"
                              class="mt-1 block w-full" value="{{ old('installments') }}" required />
                <x-input-error :messages="$errors->get('installments')" class="mt-2" />
            </div>

            <div class="flex items-end">
                <x-primary-button>Add EMI</x-primary-button>
            </div>
        </div>
    </form>
</x-card>
