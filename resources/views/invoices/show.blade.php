<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Invoice {{ $invoice->invoice_number ?? '(pending)' }}">
            <x-slot name="actions">
                <a href="{{ route('invoices.pdf', $invoice) }}" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-brand-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-brand-500">
                    Download PDF
                </a>
                <a href="{{ route('invoices.edit', $invoice) }}" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50">
                    Edit
                </a>
                <form method="POST" action="{{ route('invoices.destroy', $invoice) }}" onsubmit="return confirm('Delete this invoice?');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-red-600 uppercase tracking-widest hover:bg-gray-50">
                        Delete
                    </button>
                </form>
            </x-slot>
        </x-page-header>
    </x-slot>

    <x-card class="overflow-hidden p-2 sm:p-4">
        <div
            x-data="{
                scale: 1,
                resize() { this.scale = Math.min(this.$el.clientWidth / 794, 1); }
            }"
            x-init="resize(); window.addEventListener('resize', resize)"
            class="w-full overflow-hidden mx-auto"
            :style="{ height: (1123 * scale) + 'px' }"
        >
            <iframe
                src="{{ route('invoices.preview', $invoice) }}"
                style="width: 794px; height: 1123px; border: 0;"
                :style="{ transform: 'scale(' + scale + ')', transformOrigin: 'top left' }"
            ></iframe>
        </div>
    </x-card>
</x-app-layout>
