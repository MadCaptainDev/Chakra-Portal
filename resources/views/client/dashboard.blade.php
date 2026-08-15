@php
    use App\Models\Invoice;

    /*
     * The client's first screen. Five figures, each a link to the screen that
     * explains it. Money first, because that is what a client opens this for.
     *
     * Dark surface, matching the staff dashboard and the public site.
     */
    $money = fn ($value, $decimals = 0) => '₹'.number_format((float) $value, $decimals);
    $tile = 'text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-100/70';
@endphp

<x-app-layout title="Overview" dark>
    <div class="space-y-6">

        <div class="animate-rise-in flex flex-wrap items-center gap-4">
            @if ($client->logoUrl())
                <img src="{{ $client->logoUrl() }}" alt="" class="h-12 w-auto rounded-lg bg-white/10 p-1.5">
            @endif
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-300">Your account</p>
                <h1 class="mt-1.5 text-3xl sm:text-4xl font-extrabold tracking-tight">{{ $client->name }}</h1>
            </div>
        </div>

        {{-- Outstanding is the one number that asks for an action, so it is the
             only tile that carries the accent. Settled, it goes quiet. --}}
        <div class="grid grid-cols-2 lg:grid-cols-3 gap-3.5">
            <a href="{{ route('client.invoices') }}" @class([
                'block rounded-xl p-5 sm:p-6 ring-1 transition-colors col-span-2 lg:col-span-1',
                'bg-gradient-to-br from-amber-400/20 to-white/5 ring-amber-400/40 hover:from-amber-400/30' => $outstanding > 0,
                'bg-white/5 ring-white/10 hover:bg-white/[0.08]' => $outstanding <= 0,
            ])>
                <p class="text-3xl sm:text-4xl font-extrabold leading-none tabular-nums tracking-tight">{{ $money($outstanding) }}</p>
                <p @class(['mt-2.5', $tile, 'text-amber-100' => $outstanding > 0])>Outstanding</p>
                <p class="mt-1.5 text-xs {{ $outstanding > 0 ? 'text-amber-100/80' : 'text-brand-100/60' }}">
                    @if ($overdueCount > 0)
                        {{ $overdueCount }} overdue &middot; {{ $money($overdueAmount) }}
                    @elseif ($outstanding > 0)
                        Nothing overdue
                    @else
                        All settled — thank you
                    @endif
                </p>
            </a>

            <a href="{{ route('client.work') }}"
               class="block rounded-xl bg-white/5 ring-1 ring-white/10 p-5 sm:p-6 hover:bg-white/[0.08] transition-colors">
                <p class="text-3xl sm:text-4xl font-extrabold leading-none tabular-nums tracking-tight">{{ $publishedThisMonth }}</p>
                <p class="mt-2.5 {{ $tile }}">Published this month</p>
                <p class="mt-1.5 text-xs text-brand-100/60">{{ number_format($publishedTotal) }} in total</p>
            </a>

            <a href="{{ route('client.shoots') }}"
               class="block rounded-xl bg-white/5 ring-1 ring-white/10 p-5 sm:p-6 hover:bg-white/[0.08] transition-colors">
                @if ($nextShoot)
                    <p class="text-2xl sm:text-3xl font-extrabold leading-none tracking-tight">{{ $nextShoot->starts_at->format('d M') }}</p>
                    <p class="mt-2.5 {{ $tile }}">Next shoot</p>
                    <p class="mt-1.5 text-xs text-brand-100/60 truncate">{{ $nextShoot->title }}</p>
                @else
                    <p class="text-2xl sm:text-3xl font-extrabold leading-none tracking-tight text-brand-100/40">—</p>
                    <p class="mt-2.5 {{ $tile }}">Next shoot</p>
                    <p class="mt-1.5 text-xs text-brand-100/60">Nothing scheduled</p>
                @endif
            </a>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-3.5">
            <div class="rounded-xl bg-white/5 ring-1 ring-white/10 p-5 sm:p-6">
                <p class="{{ $tile }}">Invoices</p>
                <p class="mt-3 text-2xl font-extrabold tabular-nums">{{ $invoiceCount }}</p>
                <p class="mt-1.5 text-xs text-brand-100/60">Issued to you to date</p>
                <a href="{{ route('client.invoices') }}"
                   class="mt-4 inline-flex items-center gap-1 text-xs font-semibold uppercase tracking-widest text-brand-300 hover:text-brand-200">
                    See all
                    <x-icon name="chevron-right" class="w-4 h-4" />
                </a>
            </div>

            <div class="rounded-xl bg-white/5 ring-1 ring-white/10 p-5 sm:p-6">
                <p class="{{ $tile }}">Last payment received</p>
                @if ($lastPayment)
                    <p class="mt-3 text-2xl font-extrabold tabular-nums">{{ $money($lastPayment->amount, 2) }}</p>
                    <p class="mt-1.5 text-xs text-brand-100/60">
                        {{ $lastPayment->paid_on?->format('j M Y') }}
                        @if ($lastPayment->method) &middot; {{ $lastPayment->method }} @endif
                    </p>
                @else
                    <p class="mt-3 text-2xl font-extrabold tabular-nums text-brand-100/40">—</p>
                    <p class="mt-1.5 text-xs text-brand-100/60">Nothing recorded yet</p>
                @endif
            </div>
        </div>

        {{-- Said here rather than left for a client to discover at 9pm. --}}
        <p class="text-xs text-brand-100/45">
            Something look wrong? Anything on this screen is a question for the studio — we will sort it out.
        </p>
    </div>
</x-app-layout>
