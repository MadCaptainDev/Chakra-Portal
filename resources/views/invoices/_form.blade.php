@php
    $existingItems = old('items', isset($invoice)
        ? $invoice->items->map(fn ($item) => [
            'description' => $item->description,
            'quantity' => (float) $item->quantity,
            'unit_price' => (float) $item->unit_price,
        ])->all()
        : [['description' => '', 'quantity' => 1, 'unit_price' => 0]]
    );
@endphp

@csrf

<div
    x-data="invoiceForm({
        items: {{ Illuminate\Support\Js::from($existingItems) }},
        discountAmount: {{ Illuminate\Support\Js::from((float) old('discount_amount', $invoice->discount_amount ?? 0)) }},
    })"
>
    <div class="grid grid-cols-2 gap-4 mb-6">
        <div>
            <x-input-label for="client_id" value="Client" />
            <select id="client_id" name="client_id" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-teal-500 focus:ring-teal-500">
                <option value="">Select a client...</option>
                @foreach ($clients as $client)
                    <option value="{{ $client->id }}" @selected(old('client_id', $invoice->client_id ?? null) == $client->id)>
                        {{ $client->name }}
                    </option>
                @endforeach
            </select>
            <x-input-error :messages="$errors->get('client_id')" class="mt-2" />
            <p class="text-xs text-gray-500 mt-1">
                Need a new client? <a href="{{ route('clients.create') }}" class="text-teal-600 underline" target="_blank">Add one here</a>, then refresh this page.
            </p>
        </div>

        <div>
            <x-input-label for="invoice_date" value="Invoice Date" />
            <x-text-input id="invoice_date" name="invoice_date" type="date" class="mt-1 block w-full"
                value="{{ old('invoice_date', isset($invoice) ? $invoice->invoice_date->format('Y-m-d') : now()->format('Y-m-d')) }}" required />
            <x-input-error :messages="$errors->get('invoice_date')" class="mt-2" />
        </div>
    </div>

    <div class="mb-6">
        <x-input-label for="intro_text" value="Intro Text (shown as \"Dear Client, ...\")" />
        <textarea id="intro_text" name="intro_text" rows="3" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-teal-500 focus:ring-teal-500">{{ old('intro_text', $invoice->intro_text ?? 'Professional services rendered as per our agreement. Kindly settle the invoice by the due date indicated.') }}</textarea>
        <x-input-error :messages="$errors->get('intro_text')" class="mt-2" />
    </div>

    <h3 class="font-semibold text-gray-800 mb-2">Line Items</h3>
    <div class="border rounded-md overflow-hidden mb-2">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase w-24">Qty</th>
                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase w-32">Unit Price</th>
                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase w-32">Amount</th>
                    <th class="w-10"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <template x-for="(item, index) in items" :key="index">
                    <tr>
                        <td class="px-4 py-2">
                            <input type="text" :name="`items[${index}][description]`" x-model="item.description" required
                                class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-teal-500 focus:ring-teal-500">
                        </td>
                        <td class="px-4 py-2">
                            <input type="number" step="0.01" min="0.01" :name="`items[${index}][quantity]`" x-model.number="item.quantity" required
                                class="block w-full rounded-md border-gray-300 shadow-sm text-sm text-right focus:border-teal-500 focus:ring-teal-500">
                        </td>
                        <td class="px-4 py-2">
                            <input type="number" step="0.01" min="0" :name="`items[${index}][unit_price]`" x-model.number="item.unit_price" required
                                class="block w-full rounded-md border-gray-300 shadow-sm text-sm text-right focus:border-teal-500 focus:ring-teal-500">
                        </td>
                        <td class="px-4 py-2 text-right text-sm font-medium" x-text="lineTotal(item).toFixed(2)"></td>
                        <td class="px-4 py-2 text-right">
                            <button type="button" @click="removeItem(index)" class="text-red-600 hover:text-red-800" x-show="items.length > 1">&times;</button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
    <button type="button" @click="addItem()" class="text-sm text-teal-600 hover:text-teal-800 mb-6">+ Add line item</button>

    <div class="grid grid-cols-2 gap-4 mb-6">
        <div>
            <x-input-label for="discount_label" value="Discount Label (optional)" />
            <x-text-input id="discount_label" name="discount_label" type="text" class="mt-1 block w-full"
                value="{{ old('discount_label', $invoice->discount_label ?? '') }}" placeholder="e.g. First Month Discount" />
            <x-input-error :messages="$errors->get('discount_label')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="discount_amount" value="Discount Amount (optional)" />
            <input id="discount_amount" name="discount_amount" type="number" step="0.01" min="0" x-model.number="discountAmount"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-teal-500 focus:ring-teal-500">
            <x-input-error :messages="$errors->get('discount_amount')" class="mt-2" />
        </div>
    </div>

    <div class="flex justify-end mb-6">
        <div class="w-64 space-y-1 text-sm">
            <div class="flex justify-between"><span>Subtotal</span><span x-text="subtotal().toFixed(2)"></span></div>
            <div class="flex justify-between"><span>Discount</span><span x-text="(discountAmount || 0).toFixed(2)"></span></div>
            <div class="flex justify-between font-bold text-base border-t pt-1"><span>Total</span><span x-text="total().toFixed(2)"></span></div>
        </div>
    </div>

    <div class="flex items-center gap-4">
        <x-primary-button>Save Invoice</x-primary-button>
        <a href="{{ route('invoices.index') }}" class="text-sm text-gray-600 hover:text-gray-900">Cancel</a>
    </div>
</div>

<script>
    function invoiceForm({ items, discountAmount }) {
        return {
            items: items.length ? items : [{ description: '', quantity: 1, unit_price: 0 }],
            discountAmount: discountAmount,
            lineTotal(item) {
                return (Number(item.quantity) || 0) * (Number(item.unit_price) || 0);
            },
            subtotal() {
                return this.items.reduce((sum, item) => sum + this.lineTotal(item), 0);
            },
            total() {
                return this.subtotal() - (Number(this.discountAmount) || 0);
            },
            addItem() {
                this.items.push({ description: '', quantity: 1, unit_price: 0 });
            },
            removeItem(index) {
                this.items.splice(index, 1);
            },
        };
    }
</script>
