@php
    // Renders bare -- the caller supplies the card chrome.
    $bill = $bill ?? null;
    $uid = $bill?->id ?? 'new';
@endphp

<form method="POST" action="{{ $bill ? route('bills.update', $bill) : route('bills.store') }}">
    @csrf
    @if ($bill)
        @method('PUT')
    @endif

    <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
        <div class="sm:col-span-2">
            <x-input-label :for="'bill_name_'.$uid" value="Bill" />
            <x-text-input :id="'bill_name_'.$uid" name="name" type="text" class="mt-1 block w-full"
                          value="{{ old('name', $bill->name ?? '') }}" placeholder="e.g. Electricity" required />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <div>
            <x-input-label :for="'bill_amount_'.$uid" value="Monthly Amount" />
            <x-text-input :id="'bill_amount_'.$uid" name="amount" type="number" step="0.01" min="0"
                          class="mt-1 block w-full" value="{{ old('amount', $bill?->amount) }}" required />
            <x-input-error :messages="$errors->get('amount')" class="mt-2" />
        </div>

        <div class="flex items-end">
            {{-- Required: the controller reads boolean('is_active', true), so
                 without this field editing a paused bill would silently
                 reactivate it. --}}
            <label class="inline-flex items-center min-h-[44px] gap-2">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1"
                       @checked(old('is_active', $bill->is_active ?? true))
                       class="rounded border-gray-300 text-brand-500 focus:ring-brand-400">
                <span class="text-sm text-gray-700">Active</span>
            </label>
        </div>
    </div>

    <div class="mt-4">
        <x-primary-button>{{ $bill ? 'Save Changes' : 'Add Bill' }}</x-primary-button>
    </div>
</form>
