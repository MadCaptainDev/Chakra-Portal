<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Invoice {{ $invoice->invoice_number ?? '(pending)' }}
                </h2>
                <x-badge :status="$invoice->isOverdue() ? 'overdue' : $invoice->status" />
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if ($invoice->isPendingApproval())
                    <form method="POST" action="{{ route('invoices.approve', $invoice) }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-brand-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-brand-500">
                            Approve
                        </button>
                    </form>
                    <a href="{{ route('invoices.edit', $invoice) }}" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50">
                        Edit
                    </a>
                    <form method="POST" action="{{ route('invoices.discard', $invoice) }}" onsubmit="return confirm('Discard this pending invoice? This cannot be undone.');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-red-600 uppercase tracking-widest hover:bg-gray-50">
                            Discard
                        </button>
                    </form>
                @else
                    <a href="{{ route('invoices.pdf', $invoice) }}" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-brand-400 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-brand-500">
                        Download PDF
                    </a>
                    <a href="{{ route('invoices.edit', $invoice) }}" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50">
                        Edit
                    </a>
                    <form method="POST" action="{{ route('invoices.duplicate', $invoice) }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50">
                            Duplicate
                        </button>
                    </form>
                    {{-- A plain GET: it only opens the schedule form pre-filled, so it
                         must not create anything or need a confirmation. --}}
                    <a href="{{ route('recurring.create', ['from_invoice' => $invoice->id]) }}"
                       class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50">
                        Make Recurring
                    </a>
                    <form method="POST" action="{{ route('invoices.destroy', $invoice) }}" onsubmit="return confirm('Delete this invoice?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="inline-flex items-center justify-center min-h-[44px] px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-red-600 uppercase tracking-widest hover:bg-gray-50">
                            Delete
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="space-y-6">
        @if ($invoice->isPendingApproval())
            <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 text-sm text-amber-800">
                This invoice was generated automatically by a recurring schedule and hasn't been sent anywhere yet.
                Review the details below, then Approve to assign it an invoice number, or Discard to skip this occurrence.
            </div>
        @endif

        <x-card class="overflow-hidden p-2 sm:p-4">
            {{-- Outer div is the measuring reference and is never resized by us --
                 scaling the same element we measure would feed its own width back
                 into the calculation and shrink the preview on every resize. --}}
            <div
                x-data="{
                    scale: 1,
                    resize() { this.scale = Math.min(this.$el.clientWidth / 794, 1); }
                }"
                x-init="resize(); window.addEventListener('resize', () => resize())"
                class="w-full"
            >
                {{-- Sized to the scaled page so mx-auto actually centres it; the
                     iframe itself stays 794x1123 (A4 at 96dpi) and is scaled down. --}}
                <div
                    class="overflow-hidden mx-auto rounded-md ring-1 ring-gray-200 shadow-sm bg-white"
                    :style="{ width: (794 * scale) + 'px', height: (1123 * scale) + 'px' }"
                >
                    <iframe
                        src="{{ route('invoices.preview', $invoice) }}"
                        title="Invoice preview"
                        style="width: 794px; height: 1123px; border: 0; display: block;"
                        :style="{ transform: 'scale(' + scale + ')', transformOrigin: 'top left' }"
                    ></iframe>
                </div>
            </div>
        </x-card>

        @unless ($invoice->isPendingApproval())
            <x-card class="p-4 sm:p-6">
                <h3 class="font-semibold text-gray-800 mb-4">Payments</h3>

                <div class="grid grid-cols-3 gap-4 mb-6 text-center sm:text-left">
                    <div>
                        <p class="text-xs text-gray-500 uppercase">Total</p>
                        <p class="font-semibold text-gray-900">{{ number_format($invoice->total, 2) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase">Paid</p>
                        <p class="font-semibold text-green-600">{{ number_format($invoice->paidTotal(), 2) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase">Balance</p>
                        <p class="font-semibold text-red-600">{{ number_format($invoice->balanceDue(), 2) }}</p>
                    </div>
                </div>

                @if ($invoice->payments->isNotEmpty())
                    <div class="divide-y divide-gray-200 border-t border-b mb-6">
                        @foreach ($invoice->payments as $payment)
                            <div class="py-3 flex items-center justify-between gap-2">
                                <div>
                                    <p class="text-sm font-medium text-gray-900">{{ number_format($payment->amount, 2) }}
                                        <span class="text-gray-500 font-normal">on {{ $payment->paid_on->format('d/m/Y') }}</span>
                                    </p>
                                    @if ($payment->method || $payment->note)
                                        <p class="text-xs text-gray-500">{{ collect([$payment->method, $payment->note])->filter()->implode(' — ') }}</p>
                                    @endif
                                </div>
                                <form method="POST" action="{{ route('payments.destroy', $payment) }}" onsubmit="return confirm('Remove this payment?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-red-600 text-sm font-semibold min-h-[44px]">Remove</button>
                                </form>
                            </div>
                        @endforeach
                    </div>
                @endif

                <form method="POST" action="{{ route('payments.store', $invoice) }}" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    @csrf
                    <div>
                        <x-input-label for="amount" value="Amount" />
                        <x-text-input id="amount" name="amount" type="number" step="0.01" min="0.01" class="mt-1" required
                            value="{{ old('amount') }}" placeholder="{{ number_format($invoice->balanceDue(), 2) }}" />
                        <x-input-error :messages="$errors->get('amount')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="paid_on" value="Date Paid" />
                        <x-text-input id="paid_on" name="paid_on" type="date" class="mt-1" required
                            value="{{ old('paid_on', now()->format('Y-m-d')) }}" />
                        <x-input-error :messages="$errors->get('paid_on')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="method" value="Method (optional)" />
                        <x-text-input id="method" name="method" type="text" class="mt-1" value="{{ old('method') }}" placeholder="e.g. Bank Transfer" />
                        <x-input-error :messages="$errors->get('method')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="note" value="Note (optional)" />
                        <x-text-input id="note" name="note" type="text" class="mt-1" value="{{ old('note') }}" />
                        <x-input-error :messages="$errors->get('note')" class="mt-2" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-primary-button>Record Payment</x-primary-button>
                    </div>
                </form>
            </x-card>
        @endunless
    </div>
</x-app-layout>
