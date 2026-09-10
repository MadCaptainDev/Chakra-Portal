@php
    /*
    | The studio at a glance: what needs doing, the team, and money.
    |
    | This used to be one fixed layout, top to bottom. It is now a list of
    | widgets (resources/views/dashboard/widgets/*.blade.php) rendered in
    | whatever order and visibility App\Support\DashboardLayout resolves
    | for the signed-in person -- see DashboardController::index() for
    | where $dashboardWidgets comes from, and _customize.blade.php for the
    | panel that changes it. Every widget still reads from the same
    | variables this file computes and passes down via Blade's normal
    | @include scope-sharing, so splitting them out changed nothing about
    | what any one of them shows.
    |
    | Cards are `bg-white/5` over a `white/10` hairline on the brand-900
    | ground, the same surface the public site and the staff dashboard use.
    */
    $money = fn ($value, $decimals = 2) => '₹'.number_format((float) $value, $decimals);

    // Action rows are tone-coded by cost of ignoring, not by which module
    // raised them. Amber for "today", red for "this is already costing you".
    $tones = [
        'red' => ['ring' => 'ring-red-400/40', 'bg' => 'bg-red-400/10', 'bar' => 'bg-red-400'],
        'amber' => ['ring' => 'ring-amber-400/40', 'bg' => 'bg-amber-400/10', 'bar' => 'bg-amber-400'],
        'brand' => ['ring' => 'ring-brand-400/40', 'bg' => 'bg-brand-400/10', 'bar' => 'bg-brand-400'],
        'green' => ['ring' => 'ring-emerald-400/40', 'bg' => 'bg-emerald-400/10', 'bar' => 'bg-emerald-400'],
    ];
@endphp

<x-app-layout title="Dashboard" dark>
    <div class="space-y-10">

        {{-- ——— Header: the month, and nothing else competing with it.
             Everything that used to sit here as descriptive text is one of
             the widgets below now. ——— --}}
        <div class="animate-rise-in flex flex-wrap items-end justify-between gap-5">
            <h1 class="text-3xl sm:text-4xl font-extrabold tracking-tight">{{ $month->format('F Y') }}</h1>

            <div class="flex items-center gap-3">
                <button type="button" @click="$dispatch('open-modal', 'customize-dashboard')"
                        class="inline-flex items-center justify-center gap-2 min-h-[44px] px-4 rounded-md
                               border border-white/15 text-xs font-semibold uppercase tracking-widest
                               text-brand-100/80 hover:bg-white/[0.09] transition-colors">
                    <x-icon name="cog" class="w-4 h-4" />
                    Customize
                </button>

                <a href="{{ route('invoices.create') }}"
                   class="inline-flex items-center justify-center gap-2 min-h-[44px] px-5 rounded-md
                          bg-brand-400 text-brand-900 text-xs font-semibold uppercase tracking-widest
                          hover:bg-brand-500 transition-colors">
                    <x-icon name="plus" class="w-4 h-4" />
                    New invoice
                </a>
            </div>
        </div>

        {{-- ——— The widgets themselves, in this person's own order. A
             widget that is its own conditional (Missed duties, only when
             there are any) still gets a turn in the loop and simply
             renders nothing -- visibility and "is there anything to show"
             are two different questions. ——— --}}
        @foreach ($dashboardWidgets as $widget)
            @if ($widget['visible'])
                @include('dashboard.widgets.'.$widget['key'])
            @endif
        @endforeach
    </div>

    @include('dashboard._customize')
</x-app-layout>
