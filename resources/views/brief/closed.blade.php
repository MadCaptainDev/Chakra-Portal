@php
    /*
     * What a client sees when they open a link they have already used.
     *
     * Its own page rather than the form in a disabled state. Somebody
     * returning to this link is asking "did it go through?", and a screen of
     * greyed-out fields answers that far worse than a sentence does. It also
     * removes any doubt about whether they are supposed to fill it again.
     */
@endphp

<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Brand Brief — {{ $client->name }}</title>
    <meta name="robots" content="noindex, nofollow">

    @include('partials.favicon')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased bg-brand-900 text-white">

<div class="mx-auto max-w-xl px-4 sm:px-6 py-16 sm:py-24">
    <x-application-logo class="h-8 w-auto mb-10" />

    <div class="rounded-2xl bg-white/5 ring-1 ring-white/10 p-6 sm:p-8">
        <div class="flex items-start gap-3">
            <x-icon name="check-circle" class="w-6 h-6 shrink-0 text-brand-300" />
            <div>
                <h1 class="text-2xl font-bold tracking-tight">Thank you — we have your brief</h1>
                <p class="mt-2 text-sm text-brand-100/70 leading-relaxed">
                    {{ $client->name }} sent this in on
                    {{ ($brief->public_submitted_at ?? $brief->submitted_at)?->format('j F Y') }}@if ($brief->public_submitted_name), filled in by {{ $brief->public_submitted_name }}@endif.
                    Every script we write for you starts from these answers.
                </p>
            </div>
        </div>

        <div class="mt-6 pt-5 border-t border-white/10">
            <p class="text-sm text-brand-100/70 leading-relaxed">
                This link is now closed, so nothing here can be changed by anyone who happens to have it. If your
                brand moves — a new tagline, a different audience — message us and we will open it back up. We would
                far rather update it than keep writing from an old answer.
            </p>
        </div>
    </div>

    <p class="mt-8 text-center text-xs text-brand-100/40">
        Chakra Productions ·
        <a href="{{ route('privacy') }}" class="underline underline-offset-2 hover:text-brand-200">Privacy</a>
    </p>
</div>

</body>
</html>
