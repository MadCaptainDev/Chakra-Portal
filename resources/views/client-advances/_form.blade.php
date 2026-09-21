@csrf

<div class="space-y-5">
    <div class="grid sm:grid-cols-2 gap-4">
        <div>
            <x-input-label for="client_id" value="Client" />
            <select id="client_id" name="client_id" required
                    class="mt-1.5 w-full rounded-lg border-0 bg-white/10 px-3 py-2.5 text-sm text-white">
                <option value="">Choose a client</option>
                @foreach ($clients as $client)
                    <option value="{{ $client->id }}" @selected(old('client_id', $advance->client_id) == $client->id)>
                        {{ $client->name }}
                    </option>
                @endforeach
            </select>
            <x-input-error :messages="$errors->get('client_id')" class="mt-1.5" />
        </div>

        <div>
            <x-input-label for="category_id" value="Category" />
            <select id="category_id" name="category_id"
                    class="mt-1.5 w-full rounded-lg border-0 bg-white/10 px-3 py-2.5 text-sm text-white">
                <option value="">Uncategorised</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected(old('category_id', $advance->category_id) == $category->id)>
                        {{ $category->name }}
                    </option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-brand-100/40">Add or rename these under Setup → Master data.</p>
            <x-input-error :messages="$errors->get('category_id')" class="mt-1.5" />
        </div>
    </div>

    <div>
        <x-input-label for="name" value="What was it for" />
        <input id="name" name="name" type="text" required maxlength="255"
               value="{{ old('name', $advance->name) }}"
               placeholder="September ad campaign"
               class="mt-1.5 w-full rounded-lg border-0 bg-white/10 px-3 py-2.5 text-sm text-white placeholder-white/30">
        <x-input-error :messages="$errors->get('name')" class="mt-1.5" />
    </div>

    <div class="grid sm:grid-cols-2 gap-4">
        <div>
            <x-input-label for="amount" value="What you paid" />
            <input id="amount" name="amount" type="number" step="0.01" min="0" required
                   value="{{ old('amount', $advance->amount) }}"
                   class="mt-1.5 w-full rounded-lg border-0 bg-white/10 px-3 py-2.5 text-sm text-white">
            <x-input-error :messages="$errors->get('amount')" class="mt-1.5" />
        </div>

        <div>
            <x-input-label for="billable_amount" value="What to charge the client" />
            <input id="billable_amount" name="billable_amount" type="number" step="0.01" min="0"
                   value="{{ old('billable_amount', $advance->billable_amount) }}"
                   placeholder="Leave blank to charge exactly what you paid"
                   class="mt-1.5 w-full rounded-lg border-0 bg-white/10 px-3 py-2.5 text-sm text-white placeholder-white/30">
            <x-input-error :messages="$errors->get('billable_amount')" class="mt-1.5" />
        </div>
    </div>

    <div class="grid sm:grid-cols-2 gap-4">
        <div>
            <x-input-label for="spent_on" value="Date paid" />
            <input id="spent_on" name="spent_on" type="date" required
                   value="{{ old('spent_on', $advance->spent_on?->toDateString() ?? $advance->spent_on) }}"
                   class="mt-1.5 w-full rounded-lg border-0 bg-white/10 px-3 py-2.5 text-sm text-white">
            <x-input-error :messages="$errors->get('spent_on')" class="mt-1.5" />
        </div>

        <div>
            <x-input-label for="payee" value="Who you paid" />
            <input id="payee" name="payee" type="text" maxlength="255"
                   value="{{ old('payee', $advance->payee) }}"
                   placeholder="Meta, OpenAI, the model's name"
                   class="mt-1.5 w-full rounded-lg border-0 bg-white/10 px-3 py-2.5 text-sm text-white placeholder-white/30">
            <x-input-error :messages="$errors->get('payee')" class="mt-1.5" />
        </div>
    </div>

    <div>
        <x-input-label for="receipt" value="Receipt" />
        <input id="receipt" name="receipt" type="file" accept="image/*,application/pdf"
               class="mt-1.5 w-full rounded-lg bg-white/10 px-3 py-2.5 text-sm text-white/70 file:mr-3 file:rounded-lg file:border-0 file:bg-white/15 file:px-3 file:py-1.5 file:text-sm file:text-white">
        <p class="mt-1 text-xs text-brand-100/40">
            Image or PDF, up to 8&nbsp;MB. Only people who can see this screen can open it.
            @if ($advance->receipt_path)
                <a href="{{ route('client-advances.receipt', $advance) }}" class="text-brand-300 hover:text-white">View the one on file</a> —
                uploading replaces it.
            @endif
        </p>
        <x-input-error :messages="$errors->get('receipt')" class="mt-1.5" />
    </div>

    <div>
        <x-input-label for="notes" value="Notes" />
        <textarea id="notes" name="notes" rows="3" maxlength="2000"
                  class="mt-1.5 w-full rounded-lg border-0 bg-white/10 px-3 py-2.5 text-sm text-white">{{ old('notes', $advance->notes) }}</textarea>
        <x-input-error :messages="$errors->get('notes')" class="mt-1.5" />
    </div>
</div>
