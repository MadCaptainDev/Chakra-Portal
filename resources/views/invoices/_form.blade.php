@php
    $existingItems = old('items', isset($invoice)
        ? $invoice->items->map(fn ($item) => [
            'description' => $item->description,
            'quantity' => (float) $item->quantity,
            'unit_price' => (float) $item->unit_price,
        ])->all()
        : [['description' => '', 'quantity' => 1, 'unit_price' => 0]]
    );

    // Keyed by id so the picker can show the selected client's details and
    // pre-fill the edit modal without another round trip.
    $clientDetails = $clients
        ->keyBy('id')
        ->map(fn ($client) => $client->only(['id', 'name', 'address', 'email', 'phone', 'notion_venture']));
@endphp

@csrf

<div
    x-data="invoiceForm({
        items: {{ Illuminate\Support\Js::from($existingItems) }},
        discountAmount: {{ Illuminate\Support\Js::from((float) old('discount_amount', $invoice->discount_amount ?? 0)) }},
    })"
>
    <div
        class="mb-6"
        x-data="clientPicker({
            clients: {{ Illuminate\Support\Js::from($clientDetails) }},
            selectedId: {{ Illuminate\Support\Js::from((string) old('client_id', $invoice->client_id ?? '')) }},
            storeUrl: @js(route('clients.quick-store')),
            updateUrl: @js(route('clients.quick-update', ['client' => '__ID__'])),
            csrf: @js(csrf_token()),
        })"
    >
        <x-input-label for="client_id" value="Client" />

        <div class="mt-1 flex flex-col sm:flex-row gap-2">
            {{-- Options stay server-rendered: new ones are appended by the modal,
                 so the picker still works if Alpine never boots. --}}
            <x-select id="client_id" name="client_id" class="sm:flex-1" required
                      x-ref="select" @change="selectedId = $event.target.value">
                <option value="">Select a client...</option>
                @foreach ($clients as $client)
                    <option value="{{ $client->id }}" @selected(old('client_id', $invoice->client_id ?? null) == $client->id)>
                        {{ $client->name }}
                    </option>
                @endforeach
            </x-select>

            <div class="flex gap-2">
                <button type="button" @click="openCreate()"
                        class="inline-flex items-center justify-center min-h-[44px] px-3 rounded-md bg-brand-400 text-xs font-semibold uppercase tracking-widest text-brand-900 hover:bg-brand-500 whitespace-nowrap">
                    + New
                </button>
                <button type="button" @click="openEdit()" x-show="selected()" x-cloak
                        class="inline-flex items-center justify-center min-h-[44px] px-3 rounded-md border border-white/15 bg-white/5 text-xs font-semibold uppercase tracking-widest text-brand-100/80 hover:bg-white/[0.09] whitespace-nowrap">
                    Edit
                </button>
            </div>
        </div>

        <x-input-error :messages="$errors->get('client_id')" class="mt-2" />

        <template x-if="selected()">
            <div class="mt-2 rounded-md border border-white/10 bg-brand-900/40 px-3 py-2 text-xs text-brand-100/70 space-y-0.5" x-cloak>
                <p x-show="selected().address" x-text="selected().address"></p>
                <p x-show="selected().email || selected().phone">
                    <span x-text="[selected().email, selected().phone].filter(Boolean).join(' · ')"></span>
                </p>
                <p x-show="! selected().address && ! selected().email && ! selected().phone" class="italic text-brand-100/50">
                    No address or contact on file &mdash; add them with Edit.
                </p>
            </div>
        </template>

        {{-- Plain inputs, no nested <form>: this sits inside the invoice form,
             and the modal posts over fetch anyway. --}}
        <x-modal name="client-quick-form" maxWidth="lg" focusable>
            <div class="p-6" @keydown.enter.prevent="submit()">
                <h2 class="text-lg font-semibold text-white"
                    x-text="mode === 'edit' ? 'Edit client' : 'New client'"></h2>
                <p class="mt-1 text-sm text-brand-100/60"
                   x-text="mode === 'edit'
                        ? 'Changes apply to the client everywhere, not just this invoice.'
                        : 'Saved straight away and selected on this invoice. Your draft is kept.'"></p>

                <div class="mt-4 space-y-4">
                    <div>
                        <x-input-label for="quick_client_name" value="Name" />
                        <x-text-input id="quick_client_name" type="text" class="mt-1 block w-full" x-model="form.name" />
                        <p class="mt-2 text-sm text-red-300" x-show="errors.name" x-text="errors.name?.[0]" x-cloak></p>
                    </div>

                    <div>
                        <x-input-label for="quick_client_address" value="Address (optional)" />
                        <x-text-input id="quick_client_address" type="text" class="mt-1 block w-full" x-model="form.address" />
                        <p class="mt-2 text-sm text-red-300" x-show="errors.address" x-text="errors.address?.[0]" x-cloak></p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="quick_client_email" value="Email (optional)" />
                            <x-text-input id="quick_client_email" type="email" class="mt-1 block w-full" x-model="form.email" />
                            <p class="mt-2 text-sm text-red-300" x-show="errors.email" x-text="errors.email?.[0]" x-cloak></p>
                        </div>
                        <div>
                            <x-input-label for="quick_client_phone" value="Phone (optional)" />
                            <x-text-input id="quick_client_phone" type="text" class="mt-1 block w-full" x-model="form.phone" />
                            <p class="mt-2 text-sm text-red-300" x-show="errors.phone" x-text="errors.phone?.[0]" x-cloak></p>
                        </div>
                    </div>

                    <div>
                        <x-input-label for="quick_client_venture" value="Notion venture (optional)" />
                        <x-text-input id="quick_client_venture" type="text" class="mt-1 block w-full" x-model="form.notion_venture" />
                        <p class="mt-2 text-sm text-red-300" x-show="errors.notion_venture" x-text="errors.notion_venture?.[0]" x-cloak></p>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" @click="$dispatch('close-modal', 'client-quick-form')"
                            class="inline-flex items-center justify-center min-h-[44px] px-4 rounded-md border border-white/15 bg-white/5 text-xs font-semibold uppercase tracking-widest text-brand-100/80 hover:bg-white/[0.09]">
                        Cancel
                    </button>
                    <button type="button" @click="submit()" :disabled="saving"
                            class="inline-flex items-center justify-center min-h-[44px] px-4 rounded-md bg-brand-400 text-xs font-semibold uppercase tracking-widest text-brand-900 hover:bg-brand-500 disabled:opacity-50">
                        <span x-text="saving ? 'Saving...' : (mode === 'edit' ? 'Save client' : 'Add client')"></span>
                    </button>
                </div>
            </div>
        </x-modal>
    </div>

    @if ($saasProducts->isNotEmpty())
        @php
            $studioDefault = old('saas_product_id', $invoice->saas_product_id ?? null) ? 'true' : 'false';
        @endphp
        <div class="mb-6" x-data="{ studio: {{ $studioDefault }} }">
            <x-input-label value="Which side of Chakra is this for?" />

            {{-- Left = Production (the default for almost every invoice),
                 right = App Studio -- a toggle rather than a dropdown, since
                 this is the one either/or choice the whole invoice hinges on. --}}
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
                            <option value="{{ $product->id }}" @selected(old('saas_product_id', $invoice->saas_product_id ?? null) == $product->id)>
                                {{ $product->name }} ({{ $product->client->name }})
                            </option>
                        @endforeach
                    </x-select>
                    <x-input-error :messages="$errors->get('saas_product_id')" class="mt-2" />
                </div>

                <div>
                    <x-input-label value="Invoice type" />
                    @php $studioType = old('saas_invoice_type', $invoice->saas_invoice_type ?? null); @endphp
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
                AMC: paying this in full extends the product's AMC by one billing period. Development: a one-off
                build/dev-work invoice -- App Studio income, but never touches AMC.
            </p>
        </div>
    @endif

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
        <div>
            <x-input-label for="invoice_date" value="Invoice Date" />
            <x-text-input id="invoice_date" name="invoice_date" type="date" class="mt-1"
                value="{{ old('invoice_date', isset($invoice) ? $invoice->invoice_date->format('Y-m-d') : now()->format('Y-m-d')) }}" required />
            <x-input-error :messages="$errors->get('invoice_date')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="due_date" value="Due Date (optional)" />
            <x-text-input id="due_date" name="due_date" type="date" class="mt-1"
                value="{{ old('due_date', isset($invoice) && $invoice->due_date ? $invoice->due_date->format('Y-m-d') : '') }}" />
            <x-input-error :messages="$errors->get('due_date')" class="mt-2" />
        </div>
    </div>

    <div class="mb-6">
        <x-input-label for="intro_text" value='Intro Text (shown as "Dear Client, ...")' />
        <x-textarea id="intro_text" name="intro_text" rows="3" class="mt-1">{{ old('intro_text', $invoice->intro_text ?? 'Professional services rendered as per our agreement. Kindly settle the invoice by the due date indicated.') }}</x-textarea>
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
                value="{{ old('discount_label', $invoice->discount_label ?? '') }}" placeholder="e.g. First Month Discount" />
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
        <x-primary-button>Save Invoice</x-primary-button>
        <a href="{{ route('invoices.index') }}" class="text-sm text-brand-100/70 hover:text-white">Cancel</a>
    </div>
</div>

<script>
    const BLANK_CLIENT = { name: '', address: '', email: '', phone: '', notion_venture: '' };

    function clientPicker({ clients, selectedId, storeUrl, updateUrl, csrf }) {
        return {
            clients: clients,
            selectedId: selectedId,
            mode: 'create',
            saving: false,
            errors: {},
            form: { ...BLANK_CLIENT },

            selected() {
                return this.clients[this.selectedId] || null;
            },

            openCreate() {
                this.mode = 'create';
                this.errors = {};
                this.form = { ...BLANK_CLIENT };
                this.$dispatch('open-modal', 'client-quick-form');
            },

            openEdit() {
                const client = this.selected();

                if (! client) {
                    return;
                }

                this.mode = 'edit';
                this.errors = {};
                this.form = {
                    name: client.name || '',
                    address: client.address || '',
                    email: client.email || '',
                    phone: client.phone || '',
                    notion_venture: client.notion_venture || '',
                };
                this.$dispatch('open-modal', 'client-quick-form');
            },

            async submit() {
                if (this.saving) {
                    return;
                }

                const editing = this.mode === 'edit';
                const url = editing ? updateUrl.replace('__ID__', this.selectedId) : storeUrl;

                this.saving = true;
                this.errors = {};

                try {
                    const response = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                        },
                        body: JSON.stringify(this.form),
                    });

                    if (response.status === 422) {
                        this.errors = (await response.json()).errors || {};
                        return;
                    }

                    if (! response.ok) {
                        throw new Error('Request failed with ' + response.status);
                    }

                    this.apply(await response.json(), editing);
                    this.$dispatch('close-modal', 'client-quick-form');
                } catch (error) {
                    this.errors = { name: ['Could not save the client. Check your connection and try again.'] };
                } finally {
                    this.saving = false;
                }
            },

            /** Fold the saved client into the select without reloading the page. */
            apply(client, editing) {
                this.clients[client.id] = client;

                const select = this.$refs.select;
                const existing = select.querySelector('option[value="' + client.id + '"]');

                if (editing && existing) {
                    existing.textContent = client.name;

                    return;
                }

                // Slot it into the alphabetical order the server rendered.
                const before = [...select.options].find(
                    (option) => option.value && option.text.trim().localeCompare(client.name, undefined, { sensitivity: 'base' }) > 0
                );

                select.add(new Option(client.name, client.id, true, true), before || null);
                this.selectedId = String(client.id);
            },
        };
    }

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
