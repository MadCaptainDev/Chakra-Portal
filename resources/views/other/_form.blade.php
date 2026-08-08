@php $item = $item ?? null; @endphp

<x-card class="p-4 sm:p-6">
    <form method="POST" action="{{ $item ? route('other.update', $item) : route('other.store') }}">
        @csrf
        @if ($item)
            @method('PUT')
        @endif

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-4">
            <div class="sm:col-span-2 lg:col-span-1">
                <x-input-label for="other_name_{{ $item?->id ?? 'new' }}" value="What was spent" />
                <x-text-input id="other_name_{{ $item?->id ?? 'new' }}" name="name" type="text" class="mt-1 block w-full"
                              value="{{ old('name', $item->name ?? '') }}"
                              placeholder="e.g. Camera battery, Client lunch" required />
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="other_category_{{ $item?->id ?? 'new' }}" value="Category" />
                <select id="other_category_{{ $item?->id ?? 'new' }}" name="category" required
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-400 focus:ring-brand-400 min-h-[44px]">
                    <option value="" disabled @selected(! old('category', $item->category ?? null))>Select category</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category }}" @selected(old('category', $item->category ?? '') === $category)>
                            {{ $category }}
                        </option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('category')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="other_spent_on_{{ $item?->id ?? 'new' }}" value="Date" />
                <x-text-input id="other_spent_on_{{ $item?->id ?? 'new' }}" name="spent_on" type="date" class="mt-1 block w-full"
                              value="{{ old('spent_on', $item?->spent_on?->format('Y-m-d') ?? now()->format('Y-m-d')) }}" required />
                <x-input-error :messages="$errors->get('spent_on')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="other_amount_{{ $item?->id ?? 'new' }}" value="Amount" />
                <x-text-input id="other_amount_{{ $item?->id ?? 'new' }}" name="amount" type="number" step="0.01" min="0.01"
                              class="mt-1 block w-full" value="{{ old('amount', $item->amount ?? '') }}" required />
                <x-input-error :messages="$errors->get('amount')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="other_payee_{{ $item?->id ?? 'new' }}" value="Paid to (optional)" />
                <x-text-input id="other_payee_{{ $item?->id ?? 'new' }}" name="payee" type="text" class="mt-1 block w-full"
                              value="{{ old('payee', $item->payee ?? '') }}" placeholder="Vendor / person" />
                <x-input-error :messages="$errors->get('payee')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="other_notes_{{ $item?->id ?? 'new' }}" value="Notes (optional)" />
                <x-text-input id="other_notes_{{ $item?->id ?? 'new' }}" name="notes" type="text" class="mt-1 block w-full"
                              value="{{ old('notes', $item->notes ?? '') }}" />
                <x-input-error :messages="$errors->get('notes')" class="mt-2" />
            </div>
        </div>

        <div class="flex items-center gap-3">
            <x-primary-button>{{ $item ? 'Save Changes' : 'Add Expense' }}</x-primary-button>
            @if ($item)
                <button type="button" form="other-delete-{{ $item->id }}"
                        onclick="return confirm('Delete this expense?')"
                        class="inline-flex items-center justify-center min-h-[44px] px-3 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-red-600 uppercase tracking-widest hover:bg-gray-50">
                    Delete
                </button>
            @endif
        </div>
    </form>

    @if ($item)
        <form id="other-delete-{{ $item->id }}" method="POST" action="{{ route('other.destroy', $item) }}" class="hidden">
            @csrf
            @method('DELETE')
        </form>
    @endif
</x-card>
