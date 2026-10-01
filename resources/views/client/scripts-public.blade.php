{{--
    The script approval queue on a no-login link -- the list of client/
    scripts.blade.php, with the chrome a stranger with a link needs and a
    signed-in client does not. Same reasoning as brief/public.blade.php:
    standalone document rather than x-public-layout, since that layout's nav
    back to the marketing site is wrong on a page somebody has a job to do on.
--}}

<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Script Approvals — {{ $client->name }}</title>

    {{-- A private link, same as the brief's. --}}
    <meta name="robots" content="noindex, nofollow">

    @include('partials.favicon')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased bg-brand-900 text-white">

<header class="border-b border-white/10">
    <div class="mx-auto max-w-3xl px-4 sm:px-6 h-16 flex items-center justify-between gap-4">
        <x-application-logo class="h-8 w-auto" />
        <p class="text-xs text-brand-100/50">{{ $client->name }}</p>
    </div>
</header>

<main class="px-4 sm:px-6 py-8 sm:py-12">
    <div class="mx-auto max-w-3xl space-y-6">

        <div>
            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-300">{{ $client->name }}</p>
            <h1 class="mt-2 text-3xl sm:text-4xl font-extrabold tracking-tight">Script Approvals</h1>
            <p class="mt-2 text-sm text-brand-100/70">Read through what the studio has written and send it back approved or with notes.</p>
        </div>

        @if (session('status'))
            <div class="rounded-xl bg-brand-400/15 ring-1 ring-brand-400/25 px-4 py-3 text-sm text-brand-100" role="status">
                {{ session('status') }}
            </div>
        @endif

        @if ($scripts->isEmpty())
            <div class="rounded-xl border border-dashed border-white/15 px-6 py-12 text-center">
                <p class="text-sm text-brand-100/70">Nothing waiting on you right now.</p>
                <p class="mt-1 text-xs text-brand-100/50">A script will show up here as soon as the studio sends one for your approval.</p>
            </div>
        @else
            <div class="rounded-xl bg-white/5 ring-1 ring-white/10 overflow-hidden">
                @foreach ($scripts as $script)
                    <a href="{{ route('client.scripts.public.show', [$token, $script]) }}"
                       class="flex items-center justify-between gap-3 p-4 sm:p-5 hover:bg-white/5 transition-colors {{ $loop->first ? '' : 'border-t border-white/10' }}">
                        <div class="min-w-0">
                            <p class="font-semibold text-white truncate">{{ $script->title }}</p>
                            <p class="mt-1 text-xs text-brand-100/60">
                                {{ $script->scriptTypeTerm?->name ?? 'Script' }}
                                @if ($script->platformTerm) &middot; {{ $script->platformTerm->name }} @endif
                                @if ($script->sent_to_client_at) &middot; Sent {{ $script->sent_to_client_at->diffForHumans() }} @endif
                            </p>
                        </div>
                        <x-icon name="chevron-right" class="w-4 h-4 shrink-0 text-brand-100/40" />
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</main>

<footer class="px-4 sm:px-6 pb-10">
    <p class="mx-auto max-w-3xl text-xs text-brand-100/40">
        Questions? Reply to the message this link came from.
    </p>
</footer>

</body>
</html>
