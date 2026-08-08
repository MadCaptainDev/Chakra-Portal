@php $bill = $bill ?? null; @endphp

<x-card class="p-4 sm:p-6">
    <form method="POST" action="{{ $bill ? route('bills.update', $bill) : route('bills.store') }}">
        @csrf
        @if ($bill)
            @method('PUT')
        @endif

        <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
            <div class="sm:col-span-2">
                <x-input-label for="bill_name_{{ $bill?->id ?? 'new' }}" value="Bill" />
                <x-text-input id="bill_name_{{ $bill?->id ?? 'new' }}" name="name" type="text" class="mt-1 block w-full"
                              value="{{ old('name', $bill->name ?? '') }}" placeholder="e.g. Electricity" required />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="bill_amount_{{ $bill?->id ?? 'new' }}" value="Monthly Amount" />
                <x-text-input id="bill_amount_{{ $bill?->id ?? 'new' }}" name="amount" type="number" step="0.01" min="0"
                              class="mt-1 block w-full" value="{{ old('amount', $bill->amount ?? '') }}" required />
                <x-input-error :messages="$errors->get('amount')" class="mt-2" />
            </div>
            <div class="flex flex-col justify-end gap-2">
                <label class="inline-flex items-center min-h-[44px] gap-2">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1"
                           @checked(old('is_active', $bill->is_active ?? true))
                           class="rounded border-gray-300 text-brand-500 focus:ring-brand-400">
                    <span class="text-sm text-gray-700">Active</span>
                </label>
                <x-primary-button>{{ $bill ? 'Save Bill' : 'Add Bill' }}</x-primary-button>
            </div>
        </div>
    </form>
</x-card>
