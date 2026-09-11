@php
    $existingItems = old('items', isset($quotation)
        ? $quotation->items->map(fn ($item) => [
            'description' => $item->description,
            'quantity' => (float) $item->quantity,
            'unit_price' => (float) $item->unit_price,
        ])->all()
        : [['description' => '', 'quantity' => 1, 'unit_price' => 0]]
    );
@endphp

@csrf

<div
    x-data="quotationForm({
        items: {{ Illuminate\Support\Js::from($existingItems) }},
        discountAmount: {{ Illuminate\Support\Js::from((float) old('discount_amount', $quotation->discount_amount ?? 0)) }},
    })"
>
    <div class="mb-6">
        <x-input-label for="client_id" value="Client" />
        <x-select id="client_id" name="client_id" class="mt-1 w-full" required>
            <option value="">Select a client...</option>
            @foreach ($clients as $client)
                <option value="{{ $client->id }}" @selected(old('client_id', $quotation->client_id ?? null) == $client->id)>
                    {{ $client->name }}
                </option>
            @endforeach
        </x-select>
        <x-input-error :messages="$errors->get('client_id')" class="mt-2" />
    </div>

    @if ($saasProducts->isNotEmpty())
        @php
            $studioDefault = old('saas_product_id', $quotation->saas_product_id ?? null) ? 'true' : 'false';
        @endphp
        <div class="mb-6" x-data="{ studio: {{ $studioDefault }} }">
            <x-input-label value="Which side of Chakra is this for?" />

            {{-- Left = Production (the default for almost every quotation),
                 right = App Studio -- same either/or toggle as the invoice
                 form. --}}
            <div class="mt-1 inline-flex items-center rounded-lg bg-white/10 p-1">
                <button type="button" @click="studio = false"
                        :class="! studio ? 'bg-white/5 text-white shadow-sm' : 'text-brand-100/60 hover:text-brand-100/80'"
                        class="px-4 min-h-[40px] rounded-md text-sm font-semibold transition-colors">
                    Production
                </button>
                <button type="button" @click="studio = true"
                        :class="studio ? 'bg-white/5 text-white shadow-sm' : 'text-brand-100/60 hover:text-brand-100/80'"
                        class="px-4 min-h-[40px] rounded-md text-sm font-semibold transition-colors">
                    App Studio
                </button>
            </div>

            <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-4" x-show="studio" x-cloak>
                <div>
                    <x-input-label for="saas_product_id" value="App Studio product" />
                    <x-select id="saas_product_id" name="saas_product_id" class="mt-1 w-full" x-bind:disabled="! studio">
                        <option value="">Select a product...</option>
                        @foreach ($saasProducts as $product)
                            <option value="{{ $product->id }}" @selected(old('saas_product_id', $quotation->saas_product_id ?? null) == $product->id)>
                                {{ $product->name }} ({{ $product->client->name }})
                            </option>
                        @endforeach
                    </x-select>
                    <x-input-error :messages="$errors->get('saas_product_id')" class="mt-2" />
                </div>

                <div>
                    <x-input-label value="Quotation type" />
                    @php $studioType = old('saas_invoice_type', $quotation->saas_invoice_type ?? null); @endphp
                    <div class="mt-1 flex items-center gap-4 min-h-[44px]">
                        @foreach (\App\Models\Invoice::STUDIO_TYPES as $value => $label)
                            <label class="inline-flex items-center gap-2 text-sm text-brand-100/80">
                                <input type="radio" name="saas_invoice_type" value="{{ $value }}" x-bind:disabled="! studio"
                                       @checked($studioType === $value)
                                       class="bg-white/10 border-white/25 text-brand-400 focus:ring-brand-400">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    <x-input-error :messages="$errors->get('saas_invoice_type')" class="mt-2" />
                </div>
            </div>

            <p class="mt-2 text-xs text-brand-100/60" x-show="studio" x-cloak>
                AMC: quoting a renewal/extension of the product's AMC. Development: a one-off build/dev-work quote --
                App Studio income either way, but this is only a label until it is converted to an invoice.
            </p>
        </div>
    @endif

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
        <div>
            <x-input-label for="quotation_date" value="Quotation Date" />
            <x-text-input id="quotation_date" name="quotation_date" type="date" class="mt-1"
                value="{{ old('quotation_date', isset($quotation) ? $quotation->quotation_date->format('Y-m-d') : now()->format('Y-m-d')) }}" required />
            <x-input-error :messages="$errors->get('quotation_date')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="valid_until" value="Valid Until (optional)" />
            <x-text-input id="valid_until" name="valid_until" type="date" class="mt-1"
                value="{{ old('valid_until', isset($quotation) && $quotation->valid_until ? $quotation->valid_until->format('Y-m-d') : '') }}" />
            <x-input-error :messages="$errors->get('valid_until')" class="mt-2" />
        </div>
    </div>

    <div class="mb-6">
        <x-input-label for="intro_text" value='Intro Text (shown as "Dear Client, ...")' />
        <x-textarea id="intro_text" name="intro_text" rows="3" class="mt-1">{{ old('intro_text', $quotation->intro_text ?? 'Thank you for the opportunity to quote for your requirement. Please find the proposed scope and pricing below.') }}</x-textarea>
        <x-input-error :messages="$errors->get('intro_text')" class="mt-2" />
    </div>

    <h3 class="font-semibold text-white mb-2">Line Items</h3>

    {{-- Mobile: stacked cards --}}
    <div class="md:hidden space-y-3 mb-2">
        <template x-for="(item, index) in items" :key="index">
            <div class="border border-white/10 rounded-md p-3 space-y-2">
                <div class="flex items-start gap-2">
                    <input type="text" :name="`items[${index}][description]`" x-model="item.description" required placeholder="Description"
                        class="block w-full rounded-md border-white/15 shadow-sm text-sm focus:border-brand-400 focus:ring-brand-400 min-h-[44px]">
                    <button type="button" @click="removeItem(index)" x-show="items.length > 1"
                        class="shrink-0 min-h-[44px] min-w-[44px] flex items-center justify-center text-red-300 text-xl leading-none">&times;</button>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="text-xs text-brand-100/60">Qty</label>
                        <input type="number" step="0.01" min="0.01" :name="`items[${index}][quantity]`" x-model.number="item.quantity" required
                            class="block w-full rounded-md border-white/15 shadow-sm text-sm font-mono focus:border-brand-400 focus:ring-brand-400 min-h-[44px]">
                    </div>
                    <div>
                        <label class="text-xs text-brand-100/60">Unit Price</label>
                        <input type="number" step="0.01" min="0" :name="`items[${index}][unit_price]`" x-model.number="item.unit_price" required
                            class="block w-full rounded-md border-white/15 shadow-sm text-sm focus:border-brand-400 focus:ring-brand-400 min-h-[44px]">
                    </div>
                </div>
                <p class="text-right text-sm font-medium text-brand-100/80">Amount: <span x-text="lineTotal(item).toFixed(2)"></span></p>
            </div>
        </template>
    </div>

    {{-- Desktop: table --}}
    <div class="hidden md:block border rounded-md overflow-x-auto mb-2">
        <table class="min-w-full divide-y divide-white/10">
            <thead class="bg-brand-900/40">
                <tr>
                    <th class="px-4 py-2 text-left text-xs font-medium text-brand-100/60 uppercase">Description</th>
                    <th class="px-4 py-2 text-right text-xs font-medium text-brand-100/60 uppercase w-36">Qty</th>
                    <th class="px-4 py-2 text-right text-xs font-medium text-brand-100/60 uppercase w-32">Unit Price</th>
                    <th class="px-4 py-2 text-right text-xs font-medium text-brand-100/60 uppercase w-32">Amount</th>
                    <th class="w-10"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/10">
                <template x-for="(item, index) in items" :key="index">
                    <tr>
                        <td class="px-4 py-2">
                            <input type="text" :name="`items[${index}][description]`" x-model="item.description" required
                                class="block w-full rounded-md border-white/15 shadow-sm text-sm focus:border-brand-400 focus:ring-brand-400">
                        </td>
                        <td class="px-4 py-2">
                            <input type="number" step="0.01" min="0.01" :name="`items[${index}][quantity]`" x-model.number="item.quantity" required
                                class="block w-full rounded-md border-white/15 shadow-sm text-sm text-right font-mono focus:border-brand-400 focus:ring-brand-400">
                        </td>
                        <td class="px-4 py-2">
                            <input type="number" step="0.01" min="0" :name="`items[${index}][unit_price]`" x-model.number="item.unit_price" required
                                class="block w-full rounded-md border-white/15 shadow-sm text-sm text-right focus:border-brand-400 focus:ring-brand-400">
                        </td>
                        <td class="px-4 py-2 text-right text-sm font-medium" x-text="lineTotal(item).toFixed(2)"></td>
                        <td class="px-4 py-2 text-right">
                            <button type="button" @click="removeItem(index)" class="text-red-300 hover:text-red-200" x-show="items.length > 1">&times;</button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
    <button type="button" @click="addItem()" class="text-sm text-brand-500 hover:text-brand-300 font-semibold mb-6 min-h-[44px] inline-flex items-center">+ Add line item</button>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
        <div>
            <x-input-label for="discount_label" value="Discount Label (optional)" />
            <x-text-input id="discount_label" name="discount_label" type="text" class="mt-1"
                value="{{ old('discount_label', $quotation->discount_label ?? '') }}" placeholder="e.g. Bundle Discount" />
            <x-input-error :messages="$errors->get('discount_label')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="discount_amount" value="Discount Amount (optional)" />
            <input id="discount_amount" name="discount_amount" type="number" step="0.01" min="0" x-model.number="discountAmount"
                class="mt-1 block w-full rounded-md border-white/15 shadow-sm focus:border-brand-400 focus:ring-brand-400 min-h-[44px]">
            <x-input-error :messages="$errors->get('discount_amount')" class="mt-2" />
        </div>
    </div>

    <div class="flex justify-end mb-6">
        <div class="w-full sm:w-64 space-y-1 text-sm">
            <div class="flex justify-between"><span>Subtotal</span><span x-text="subtotal().toFixed(2)"></span></div>
            <div class="flex justify-between"><span>Discount</span><span x-text="(discountAmount || 0).toFixed(2)"></span></div>
            <div class="flex justify-between font-bold text-base border-t pt-1"><span>Total</span><span x-text="total().toFixed(2)"></span></div>
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-4">
        <x-primary-button>Save Quotation</x-primary-button>
        <a href="{{ route('quotations.index') }}" class="text-sm text-brand-100/70 hover:text-white">Cancel</a>
    </div>
</div>

<script>
    function quotationForm({ items, discountAmount }) {
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
