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

    {{-- Defaults to whichever tab a failed submit's errors belong to, so a
         validation error never lands silently on a tab that isn't showing. --}}
    <div class="space-y-6" x-data="{ tab: '{{ $errors->has('phone') ? 'whatsapp' : 'preview' }}' }">
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

        <div class="overflow-x-auto -mx-1 px-1 pb-1">
            <x-tab-nav model="tab" :tabs="[
                'preview' => ['label' => 'Preview'],
                'whatsapp' => ['label' => 'WhatsApp', 'count' => $quotation->whatsappLogs->count() ?: null],
            ]" />
        </div>

        <div x-show="tab === 'preview'" x-cloak class="space-y-6">
            <x-card class="overflow-hidden p-2 sm:p-4">
                <x-document-preview :src="route('quotations.preview', $quotation)" title="Quotation preview" />
            </x-card>
        </div>

        <div x-show="tab === 'whatsapp'" x-cloak class="space-y-6">
            <x-card class="p-4 sm:p-6">
                <h3 class="font-semibold text-white mb-4">
                    {{ $quotation->whatsapp_sent_at ? 'Send again' : 'Send via WhatsApp' }}
                </h3>
                {{-- Any number, any time -- not just the client's own number
                     on file. Typed fresh on every send rather than
                     remembered, since there is no one "the" recipient to
                     default to and get wrong. --}}
                <form method="POST" action="{{ route('quotations.send-whatsapp', $quotation) }}"
                      class="flex flex-col sm:flex-row sm:items-start gap-3">
                    @csrf
                    <div class="flex-1">
                        <x-input-label for="phone" value="WhatsApp number" />
                        <x-text-input id="phone" name="phone" type="text" class="mt-1 w-full"
                            value="{{ old('phone', $quotation->client->phone) }}"
                            placeholder="e.g. 9876543210" required autofocus />
                        <x-input-error :messages="$errors->get('phone')" class="mt-2" />
                    </div>
                    <x-primary-button class="mt-1 sm:mt-6">Send</x-primary-button>
                </form>
            </x-card>

            <x-card class="p-4 sm:p-6">
                <h3 class="font-semibold text-white mb-4">Send history</h3>
                <x-whatsapp-log-table :logs="$quotation->whatsappLogs" />
            </x-card>
        </div>
    </div>
</x-app-layout>
