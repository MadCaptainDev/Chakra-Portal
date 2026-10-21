{{--
    Money Overview (MoneyOverviewController): the month's money in and out,
    what is overdue, what is still to come in and what is still to pay.
--}}
@php
    $r = fn ($v) => \App\Support\ClientHub::rupees((float) $v);
    $tile = 'flex flex-col gap-1.5 rounded-2xl border border-white/[0.06] bg-[#15303f] p-5';
    $panel = 'rounded-2xl border border-white/[0.06] bg-[#15303f] p-5';
    $row = 'flex items-center justify-between gap-3 border-t border-white/[0.05] py-2.5 text-[13.5px]';
    $isThisMonth = $month->isSameMonth(now());
    $net = $cameIn - $paidOut;
@endphp

<x-app-layout title="Money" dark>
    <div class="mx-auto max-w-6xl space-y-7 pb-16">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-sm text-brand-100/55">{{ $month->format('F Y') }}</p>
                <h1 class="mt-1 text-3xl font-extrabold tracking-tight sm:text-4xl">Money</h1>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <nav class="inline-flex h-11 items-center gap-1 rounded-xl border border-white/[0.08] bg-[#15303f] px-1" aria-label="Month">
                    <a href="{{ route('money.index', ['month' => $month->copy()->subMonth()->format('Y-m')]) }}" class="flex h-9 w-9 items-center justify-center rounded-lg text-lg text-brand-100/70 hover:bg-white/10 hover:text-white" aria-label="Previous month">‹</a>
                    <a href="{{ route('money.index') }}" class="flex h-9 items-center rounded-lg px-3 text-sm font-semibold {{ $isThisMonth ? 'text-white' : 'text-brand-300 hover:bg-white/10' }}">This month</a>
                    <a href="{{ route('money.index', ['month' => $month->copy()->addMonth()->format('Y-m')]) }}" class="flex h-9 w-9 items-center justify-center rounded-lg text-lg text-brand-100/70 hover:bg-white/10 hover:text-white" aria-label="Next month">›</a>
                </nav>
                @if (Route::has('expenses.create'))
                    <a href="{{ route('expenses.create') }}" class="inline-flex h-11 items-center rounded-xl border border-white/15 px-4 text-sm font-semibold text-white hover:bg-white/10">+ Expense</a>
                @endif
                <a href="{{ route('invoices.create') }}" class="inline-flex h-11 items-center rounded-xl bg-brand-400 px-4 text-sm font-bold text-brand-900 hover:bg-brand-300">+ Invoice</a>
            </div>
        </header>

        {{-- Four numbers --}}
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="{{ $tile }}">
                <span class="text-[13px] text-brand-100/65">Came in</span>
                <span class="text-[26px] font-bold text-emerald-300">{{ $r($cameIn) }}</span>
                <span class="text-xs text-brand-100/50">payments in {{ $month->format('F') }}</span>
            </div>
            <div class="{{ $tile }}">
                <span class="text-[13px] text-brand-100/65">Went out</span>
                <span class="text-[26px] font-bold text-orange-300">{{ $r($paidOut) }}</span>
                <span class="text-xs text-brand-100/50">salaries, EMIs, bills and spends paid</span>
            </div>
            <div class="{{ $tile }}">
                <span class="text-[13px] text-brand-100/65">Net</span>
                <span class="text-[26px] font-bold {{ $net >= 0 ? 'text-white' : 'text-red-300' }}">{{ $net < 0 ? '−' : '' }}{{ $r(abs($net)) }}</span>
                <span class="text-xs text-brand-100/50">came in minus went out</span>
            </div>
            <div class="{{ $tile }} {{ $overdue->isNotEmpty() ? '!border-red-400/30 !bg-red-500/[0.08]' : '' }}">
                <span class="text-[13px] {{ $overdue->isNotEmpty() ? 'text-red-200' : 'text-brand-100/65' }}">Overdue</span>
                <span class="text-[26px] font-bold text-white">{{ $r($overdueTotal) }}</span>
                <span class="text-xs {{ $overdue->isNotEmpty() ? 'text-red-200' : 'text-brand-100/50' }}">{{ $overdue->count() }} {{ Str::plural('invoice', $overdue->count()) }}{{ $pendingApproval ? ' · '.$pendingApproval.' awaiting approval' : '' }}</span>
            </div>
        </div>

        {{-- Overdue invoices --}}
        <section class="overflow-hidden rounded-2xl border border-white/[0.06] bg-[#15303f]" aria-labelledby="m-overdue">
            <div class="flex items-baseline justify-between px-5 py-4">
                <h2 id="m-overdue" class="font-semibold text-white">Overdue invoices</h2>
                <a href="{{ route('invoices.index') }}" class="text-[13px] text-brand-300 hover:text-white">All invoices</a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[620px] text-[13.5px]">
                    <thead class="text-left text-[11px] font-semibold uppercase tracking-wider text-brand-100/45">
                        <tr class="border-t border-white/[0.05]"><th class="px-5 py-2.5">Invoice</th><th class="px-3 py-2.5">Client</th><th class="px-3 py-2.5 text-right">Left to pay</th><th class="px-3 py-2.5">Late by</th><th class="px-5 py-2.5"></th></tr>
                    </thead>
                    <tbody>
                        @forelse ($overdue as $inv)
                            @php $days = (int) $inv->due_date->diffInDays(today()); @endphp
                            <tr class="border-t border-white/[0.05]">
                                <td class="px-5 py-3 text-brand-100/75">{{ $inv->invoice_number ?? 'Pending' }}</td>
                                <td class="px-3 py-3 text-white">{{ $inv->client?->name }}</td>
                                <td class="px-3 py-3 text-right font-semibold text-white">{{ $r($inv->balanceDue()) }}</td>
                                <td class="px-3 py-3 {{ $days > 30 ? 'text-red-300' : 'text-amber-200' }}">{{ $days }} {{ Str::plural('day', $days) }}</td>
                                <td class="px-5 py-3 text-right"><a href="{{ route('invoices.show', $inv) }}" class="inline-flex h-9 items-center rounded-lg bg-white/10 px-3 text-[13px] font-semibold text-white hover:bg-white/15">Open</a></td>
                            </tr>
                        @empty
                            <tr class="border-t border-white/[0.05]"><td colspan="5" class="px-5 py-6 text-center text-emerald-200/80">Nothing overdue.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <div class="grid gap-5 lg:grid-cols-2">
            <section class="{{ $panel }}" aria-labelledby="m-coming">
                <h2 id="m-coming" class="mb-2 font-semibold text-white">Still to come in <span class="font-normal text-brand-100/55">· {{ $r($comingTotal) }}</span></h2>
                @forelse ($coming as $inv)
                    <a href="{{ route('invoices.show', $inv) }}" class="{{ $row }} hover:bg-white/[0.02]">
                        <span class="min-w-0 truncate text-white">{{ $inv->client?->name }} · {{ $inv->invoice_number ?? 'Pending' }}</span>
                        <span class="shrink-0 text-emerald-300">{{ $r($inv->balanceDue()) }}{{ $inv->due_date ? ' · due '.$inv->due_date->format('j M') : '' }}</span>
                    </a>
                @empty
                    <p class="border-t border-white/[0.05] py-5 text-sm text-brand-100/50">No unpaid invoices that are not yet due.</p>
                @endforelse
            </section>

            <section class="{{ $panel }}" aria-labelledby="m-pay">
                <h2 id="m-pay" class="mb-2 font-semibold text-white">Still to pay in {{ $month->format('F') }} <span class="font-normal text-brand-100/55">· {{ $r($toPay->sum('left')) }}</span></h2>
                @forelse ($toPay->take(8) as $x)
                    <div class="{{ $row }}">
                        <span class="min-w-0 truncate text-white">{{ $x['expense']->name }} <span class="text-brand-100/50">· {{ \App\Models\Expense::TYPES[$x['expense']->type] ?? $x['expense']->type }}</span></span>
                        <span class="shrink-0 text-orange-300">{{ $r($x['left']) }}</span>
                    </div>
                @empty
                    <p class="border-t border-white/[0.05] py-5 text-sm text-emerald-200/80">Everything for {{ $month->format('F') }} is paid.</p>
                @endforelse
                @if ($toPay->count() > 8 && Route::has('expenses.index'))
                    <a href="{{ route('expenses.index') }}" class="block pt-2 text-[13px] text-brand-300 hover:text-white">+ {{ $toPay->count() - 8 }} more</a>
                @endif
            </section>

            <section class="{{ $panel }} lg:col-span-2" aria-labelledby="m-in">
                <h2 id="m-in" class="mb-2 font-semibold text-white">Latest payments in</h2>
                @forelse ($payments as $p)
                    <div class="{{ $row }}">
                        <span class="min-w-0 truncate text-white">{{ $p->invoice?->client?->name ?? 'Payment' }}{{ $p->invoice?->invoice_number ? ' · '.$p->invoice->invoice_number : '' }}</span>
                        <span class="shrink-0 text-emerald-300">{{ $r($p->amount) }} · {{ $p->paid_on?->format('j M') }}</span>
                    </div>
                @empty
                    <p class="border-t border-white/[0.05] py-5 text-sm text-brand-100/50">No payments recorded in {{ $month->format('F') }}.</p>
                @endforelse
            </section>
        </div>
    </div>
</x-app-layout>
