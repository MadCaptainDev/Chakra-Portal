<x-app-layout title="Quotation">
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <h2 class="font-semibold text-xl text-white leading-tight">
                    Quotation {{ $quotation->quotation_number ?? '(pending)' }}
                </h2>
                <x-badge :status="$quotation->displayStatus()" />
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('quotations.pdf', $quotation) }}" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-brand-400 border border-transparent rounded-md font-semibold text-xs text-brand-900 uppercase tracking-widest hover:bg-brand-500">
                    Download PDF
                </a>

                @if ($quotation->canAccept())
                    <form method="POST" action="{{ route('quotations.accept', $quotation) }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-emerald-400/20 border border-emerald-400/30 rounded-md font-semibold text-xs text-emerald-200 uppercase tracking-widest hover:bg-emerald-400/30">
                            Mark Accepted
                        </button>
                    </form>
                    <form method="POST" action="{{ route('quotations.reject', $quotation) }}" onsubmit="return confirm('Mark this quotation as rejected?');">
                        @csrf
                        <button type="submit" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-white/5 border border-white/15 rounded-md font-semibold text-xs text-red-300 uppercase tracking-widest hover:bg-white/[0.09]">
                            Mark Rejected
                        </button>
                    </form>
                @endif

                @if ($quotation->canConvert())
                    <form method="POST" action="{{ route('quotations.convert', $quotation) }}" onsubmit="return confirm('Turn this quotation into an invoice?');">
                        @csrf
                        <button type="submit" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-brand-400 border border-transparent rounded-md font-semibold text-xs text-brand-900 uppercase tracking-widest hover:bg-brand-500">
                            Convert to Invoice
                        </button>
                    </form>
                @endif

                @unless ($quotation->isConverted())
                    <a href="{{ route('quotations.edit', $quotation) }}" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-white/5 border border-white/15 rounded-md font-semibold text-xs text-brand-100/80 uppercase tracking-widest hover:bg-white/[0.09]">
                        Edit
                    </a>
                    <form method="POST" action="{{ route('quotations.destroy', $quotation) }}" onsubmit="return confirm('Delete this quotation?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-white/5 border border-white/15 rounded-md font-semibold text-xs text-red-300 uppercase tracking-widest hover:bg-white/[0.09]">
                            Delete
                        </button>
                    </form>
                @endunless
            </div>
        </div>
    </x-slot>

    <div class="space-y-6">
        @if ($quotation->isConverted())
            <div class="bg-brand-400/10 border border-brand-400/30 rounded-lg p-4 text-sm text-brand-100">
                Converted to invoice
                <a href="{{ route('invoices.show', $quotation->converted_invoice_id) }}" class="font-semibold text-brand-300 hover:text-brand-200 underline">
                    {{ $quotation->convertedInvoice?->invoice_number ?? '#'.$quotation->converted_invoice_id }}
                </a>.
            </div>
        @elseif ($quotation->displayStatus() === 'expired')
            <div class="bg-red-400/10 border border-red-400/30 rounded-lg p-4 text-sm text-red-200">
                This quotation's validity ({{ $quotation->valid_until->format('d M Y') }}) has passed and it is still undecided.
            </div>
        @elseif ($quotation->status === \App\Models\Quotation::STATUS_ACCEPTED)
            <div class="bg-emerald-400/10 border border-emerald-400/30 rounded-lg p-4 text-sm text-emerald-200">
                Accepted{{ $quotation->accepted_at ? ' on '.$quotation->accepted_at->format('d M Y, g:i A') : '' }}. Convert it to an invoice when ready to bill.
            </div>
        @elseif ($quotation->status === \App\Models\Quotation::STATUS_REJECTED)
            <div class="bg-red-400/10 border border-red-400/30 rounded-lg p-4 text-sm text-red-200">
                Rejected{{ $quotation->rejected_at ? ' on '.$quotation->rejected_at->format('d M Y, g:i A') : '' }}.
            </div>
        @endif

        <x-card class="overflow-hidden p-2 sm:p-4">
            <div
                x-data="{
                    scale: 1,
                    resize() { this.scale = Math.min(this.$el.clientWidth / 794, 1); }
                }"
                x-init="resize(); window.addEventListener('resize', () => resize())"
                class="w-full"
            >
                <div
                    class="overflow-hidden mx-auto rounded-md ring-1 ring-white/10 shadow-sm bg-white"
                    :style="{ width: (794 * scale) + 'px', height: (1123 * scale) + 'px' }"
                >
                    <iframe
                        src="{{ route('quotations.preview', $quotation) }}"
                        title="Quotation preview"
                        style="width: 794px; height: 1123px; border: 0; display: block;"
                        :style="{ transform: 'scale(' + scale + ')', transformOrigin: 'top left' }"
                    ></iframe>
                </div>
            </div>
        </x-card>
    </div>
</x-app-layout>
