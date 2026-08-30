@props([
    'clientInputId' => 'client_id',
    'monthInputId' => 'invoice_date',
])

<aside class="rounded-lg border border-white/10 bg-brand-900/30 p-3 space-y-3 h-fit lg:sticky lg:top-4">
    <div>
        <p class="text-xs font-semibold uppercase tracking-wide text-brand-100/60">Qty variables</p>
        <p class="text-[11px] text-brand-100/50 mt-1">Click a row’s Qty first, then insert. Counts use Published items in the invoice month.</p>
    </div>

    <div class="space-y-2">
        @foreach (\App\Support\InvoiceQuantityVariable::catalog() as $variable)
            <button type="button"
                    @click="insertVariable(@js($variable['token']))"
                    class="w-full text-left rounded-md border border-white/10 bg-white/5 px-3 py-2 hover:bg-white/[0.09] transition-colors">
                <p class="text-xs font-semibold text-brand-100/80">{{ $variable['label'] }}</p>
                <p class="text-[11px] font-mono text-brand-300 mt-0.5">{{ $variable['token'] }}</p>
                <p class="text-[11px] text-brand-100/50 mt-1" x-show="previewCounts" x-cloak>
                    Now: <span class="font-semibold text-white" x-text="previewCounts?.['{{ $variable['key'] }}'] ?? '—'"></span>
                </p>
            </button>
        @endforeach
    </div>

    <p class="text-[11px] text-brand-100/50" x-show="! previewCounts && hasClientAndMonth()" x-cloak>
        Loading counts for the selected client and month…
    </p>
</aside>
