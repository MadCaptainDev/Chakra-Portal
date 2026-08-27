@php
    $existingItems = old('items', isset($schedule)
        ? $schedule->items->map(fn ($item) => [
            'description' => $item->description,
            'quantity' => (float) $item->quantity,
            'unit_price' => (float) $item->unit_price,
        ])->all()
        : [['description' => '', 'quantity' => 1, 'unit_price' => 0]]
    );
@endphp

@csrf

<div
    x-data="recurringForm({
        items: {{ Illuminate\Support\Js::from($existingItems) }},
        discountAmount: {{ Illuminate\Support\Js::from((float) old('discount_amount', $schedule->discount_amount ?? 0)) }},
        frequency: {{ Illuminate\Support\Js::from(old('frequency', $schedule->frequency ?? 'monthly')) }},
    })"
>
    <div class="mb-6">
        <x-input-label for="client_id" value="Client" />
        <x-select id="client_id" name="client_id" class="mt-1" required>
            <option value="">Select a client...</option>
            @foreach ($clients as $client)
                <option value="{{ $client->id }}" @selected(old('client_id', $schedule->client_id ?? null) == $client->id)>
                    {{ $client->name }}
                </option>
            @endforeach
        </x-select>
        <x-input-error :messages="$errors->get('client_id')" class="mt-2" />
    </div>

    <div class="mb-6">
        <x-input-label for="label" value="Label (optional, for your own reference)" />
        <x-text-input id="label" name="label" type="text" class="mt-1" value="{{ old('label', $schedule->label ?? '') }}" placeholder="e.g. Monthly Retainer" />
        <x-input-error :messages="$errors->get('label')" class="mt-2" />
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div>
            <x-input-label for="frequency" value="Frequency" />
            <x-select id="frequency" name="frequency" class="mt-1" x-model="frequency" required>
                @foreach (\App\Models\RecurringInvoice::FREQUENCIES as $value => $label)
                    <option value="{{ $value }}" @selected(old('frequency', $schedule->frequency ?? 'monthly') === $value)>{{ $label }}</option>
                @endforeach
            </x-select>
            <x-input-error :messages="$errors->get('frequency')" class="mt-2" />
        </div>

        <div x-show="frequency !== 'weekly'">
            <x-input-label for="day_of_month" value="Day of Month" />
            <x-text-input id="day_of_month" name="day_of_month" type="number" min="1" max="31" class="mt-1"
                value="{{ old('day_of_month', $schedule->day_of_month ?? '') }}" />
            <x-input-error :messages="$errors->get('day_of_month')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="due_days" value="Due Days After Issue (optional)" />
            <x-text-input id="due_days" name="due_days" type="number" min="0" class="mt-1" value="{{ old('due_days', $schedule->due_days ?? '') }}" />
            <x-input-error :messages="$errors->get('due_days')" class="mt-2" />
        </div>
    </div>

    <div class="mb-6">
        <x-input-label for="next_run_on" value="First Occurrence" />
        <x-text-input id="next_run_on" name="next_run_on" type="date" class="mt-1"
            value="{{ old('next_run_on', isset($schedule) ? $schedule->next_run_on->format('Y-m-d') : now()->format('Y-m-d')) }}" required />
        <x-input-error :messages="$errors->get('next_run_on')" class="mt-2" />
        <p class="text-xs text-brand-100/60 mt-1">The first invoice will be generated (as pending approval) on or after this date.</p>
    </div>

    <div class="mb-6">
        <x-input-label for="intro_text" value='Intro Text (shown as "Dear Client, ...")' />
        <x-textarea id="intro_text" name="intro_text" rows="3" class="mt-1">{{ old('intro_text', $schedule->intro_text ?? 'Professional services rendered as per our agreement. Kindly settle the invoice by the due date indicated.') }}</x-textarea>
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
                            class="block w-full rounded-md border-white/15 shadow-sm text-sm focus:border-brand-400 focus:ring-brand-400 min-h-[44px]">
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
                    <th class="px-4 py-2 text-right text-xs font-medium text-brand-100/60 uppercase w-24">Qty</th>
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
                                class="block w-full rounded-md border-white/15 shadow-sm text-sm text-right focus:border-brand-400 focus:ring-brand-400">
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
                value="{{ old('discount_label', $schedule->discount_label ?? '') }}" placeholder="e.g. First Month Discount" />
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
            <div class="flex justify-between font-bold text-base border-t pt-1"><span>Total per invoice</span><span x-text="total().toFixed(2)"></span></div>
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-4">
        <x-primary-button>Save Schedule</x-primary-button>
        <a href="{{ route('recurring.index') }}" class="text-sm text-brand-100/70 hover:text-white">Cancel</a>
    </div>
</div>

<script>
    function recurringForm({ items, discountAmount, frequency }) {
        return {
            items: items.length ? items : [{ description: '', quantity: 1, unit_price: 0 }],
            discountAmount: discountAmount,
            frequency: frequency,
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
