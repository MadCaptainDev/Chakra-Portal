<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Chakra-Portal') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased bg-brand-900 text-white min-h-screen flex flex-col">

    <header class="px-6 sm:px-10 py-6 flex items-center justify-between">
        <x-application-logo class="h-9 w-auto" />
        <a href="{{ route('login') }}"
           class="inline-flex items-center min-h-[44px] px-5 rounded-md bg-brand-400 text-white text-xs font-semibold uppercase tracking-widest hover:bg-brand-500 transition-colors">
            Sign In
        </a>
    </header>

    <main class="flex-1 flex items-center px-6 sm:px-10 py-12">
        <div class="max-w-5xl mx-auto w-full">
            <p class="text-brand-300 text-xs font-semibold uppercase tracking-[0.2em] mb-4">
                Chakra Productions
            </p>

            <h1 class="text-4xl sm:text-6xl font-extrabold leading-tight mb-5">
                {{ config('app.name', 'Chakra-Portal') }}
            </h1>

            <p class="text-lg sm:text-xl text-brand-100/80 max-w-2xl mb-10 leading-relaxed">
                One place for invoicing, recurring billing, client records, and everything
                the studio pays out each month &mdash; EMIs, salaries and bills.
            </p>

            <a href="{{ route('login') }}"
               class="inline-flex items-center justify-center min-h-[52px] px-8 rounded-md bg-brand-400 text-white text-sm font-semibold uppercase tracking-widest hover:bg-brand-500 transition-colors">
                Sign In to the Portal
            </a>

            {{-- What's inside --}}
            <div class="grid grid-cols-2 lg:grid-cols-3 gap-3 mt-16">
                @php
                    $modules = [
                        ['Invoices', 'Create, approve and send PDF invoices'],
                        ['Recurring', 'Schedules that bill on their own'],
                        ['Clients', 'Contacts and full invoice history'],
                        ['EMI', 'Asset finance, outstanding and payoff dates'],
                        ['Salaries', 'Monthly payroll and staff records'],
                        ['Bills', 'Rent, power and everything recurring'],
                    ];
                @endphp

                @foreach ($modules as [$title, $description])
                    <div class="rounded-lg bg-white/5 border border-white/10 p-4">
                        <p class="font-semibold text-white mb-1">{{ $title }}</p>
                        <p class="text-sm text-brand-100/60 leading-snug">{{ $description }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </main>

    <footer class="px-6 sm:px-10 py-6 border-t border-white/10">
        <p class="text-xs text-brand-100/50">
            &copy; {{ date('Y') }} Chakra Productions. Staff access only.
        </p>
    </footer>

</body>
</html>
