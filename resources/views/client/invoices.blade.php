@php
    use App\Models\Invoice;

    $money = fn ($value) => '₹'.number_format((float) $value, 2);

    /*
     * displayStatus() already resolves overdue and part-paid, with overdue
     * winning, so this only has to colour what it is told rather than work it
     * out again and risk disagreeing with the studio's own screen.
     */
    $tone = [
        'overdue' => ['chip' => 'bg-red-400/20 text-red-200 ring-red-400/40', 'label' => 'Overdue'],
        'partial' => ['chip' => 'bg-amber-400/20 text-amber-100 ring-amber-400/40', 'label' => 'Part paid'],
        Invoice::STATUS_UNPAID => ['chip' => 'bg-white/10 text-brand-100 ring-white/20', 'label' => 'Due'],
        Invoice::STATUS_PAID => ['chip' => 'bg-emerald-400/20 text-emerald-200 ring-emerald-400/40', 'label' => 'Paid'],
    ];
@endphp

<x-app-layout title="Invoices" dark>
    <div class="space-y-6">

        <div class="animate-rise-in">
            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-300">{{ $client->name }}</p>
            <h1 class="mt-2 text-3xl sm:text-4xl font-extrabold tracking-tight">Invoices</h1>
        </div>

        <div class="grid grid-cols-2 gap-3.5">
            <div @class([
                'rounded-xl p-5 ring-1',
                'bg-gradient-to-br from-amber-400/20 to-white/5 ring-amber-400/40' => $outstanding > 0,
                'bg-white/5 ring-white/10' => $outstanding <= 0,
            ])>
                <p class="text-2xl sm:text-3xl font-extrabold leading-none tabular-nums">{{ $money($outstanding) }}</p>
                <p class="mt-2 text-[10px] font-semibold uppercase tracking-[0.16em] {{ $outstanding > 0 ? 'text-amber-100' : 'text-brand-100/70' }}">Outstanding</p>
            </div>
            <div class="rounded-xl bg-white/5 ring-1 ring-white/10 p-5">
                <p class="text-2xl sm:text-3xl font-extrabold leading-none tabular-nums">{{ $money($paidTotal) }}</p>
                <p class="mt-2 text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-100/70">Paid to date</p>
            </div>
        </div>

        @forelse ($invoices as $invoice)
            @php $chip = $tone[$invoice->displayStatus()] ?? $tone[Invoice::STATUS_UNPAID]; @endphp

            <div class="rounded-xl bg-white/5 ring-1 ring-white/10 p-4 sm:p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2.5">
                            <p class="font-bold text-lg">{{ $invoice->invoice_number }}</p>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full ring-1 text-[10px] font-bold uppercase tracking-wide {{ $chip['chip'] }}">
                                {{ $chip['label'] }}
                            </span>
                        </div>
                        <p class="mt-1 text-xs text-brand-100/60">
                            Issued {{ $invoice->invoice_date?->format('j M Y') }}
                            @if ($invoice->due_date) &middot; due {{ $invoice->due_date->format('j M Y') }} @endif
                        </p>
                    </div>

                    <div class="shrink-0 text-right">
                        <p class="text-xl font-extrabold tabular-nums">{{ $money($invoice->total) }}</p>
                        @if ($invoice->balanceDue() > 0 && $invoice->paidTotal() > 0)
                            <p class="text-xs text-amber-200">{{ $money($invoice->balanceDue()) }} still due</p>
                        @endif
                    </div>
                </div>

                @if ($invoice->payments->isNotEmpty())
                    <div class="mt-3 rounded-lg bg-black/15 divide-y divide-white/5">
                        @foreach ($invoice->payments as $payment)
                            <div class="flex items-center justify-between gap-3 px-3 py-2 text-xs">
                                <span class="text-brand-100/70">
                                    Received {{ $payment->paid_on?->format('j M Y') }}
                                    @if ($payment->method) &middot; {{ $payment->method }} @endif
                                </span>
                                <span class="tabular-nums font-semibold">{{ $money($payment->amount) }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="mt-3.5">
                    <a href="{{ route('client.invoices.pdf', $invoice->id) }}"
                       class="inline-flex items-center gap-2 min-h-[44px] px-4 rounded-md bg-brand-400 text-brand-900
                              text-xs font-semibold uppercase tracking-widest hover:bg-brand-500 transition-colors">
                        <x-icon name="document" class="w-4 h-4" />
                        Download PDF
                    </a>
                </div>
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-white/15 px-6 py-14 text-center">
                <p class="text-sm text-brand-100/70">No invoices yet.</p>
            </div>
        @endforelse
    </div>
</x-app-layout>
