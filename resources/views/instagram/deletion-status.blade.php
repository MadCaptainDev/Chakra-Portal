@php
    /*
     * What became of a data deletion request.
     *
     * Meta hands this URL to the person who asked, so the reader has no
     * account here and no idea what Chakra Portal is. It says who we are, what
     * was held, and what happened -- and nothing else.
     */
@endphp

<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Data deletion request — Chakra Productions</title>
    <meta name="robots" content="noindex, nofollow">

    @include('partials.favicon')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased bg-brand-900 text-white">

<div class="mx-auto max-w-xl px-4 sm:px-6 py-16 sm:py-24">
    <x-application-logo class="h-8 w-auto mb-10" />

    <div class="rounded-2xl bg-white/5 ring-1 ring-white/10 p-6 sm:p-8">
        @if ($receipt?->completed_at)
            <div class="flex items-start gap-3">
                <x-icon name="check-circle" class="w-6 h-6 shrink-0 text-brand-300" />
                <div>
                    <h1 class="text-2xl font-bold tracking-tight">Your data has been deleted</h1>
                    <p class="mt-2 text-sm text-brand-100/70 leading-relaxed">
                        {{ $receipt->outcome }}
                    </p>
                    <p class="mt-3 text-sm text-brand-100/70 leading-relaxed">
                        Requested {{ $receipt->requested_at->format('j F Y') }}, completed
                        {{ $receipt->completed_at->format('j F Y') }}.
                    </p>
                </div>
            </div>

            <div class="mt-6 pt-5 border-t border-white/10 text-sm text-brand-100/60">
                <p>Confirmation code</p>
                <p class="mt-1 font-mono text-white">{{ $receipt->confirmation_code }}</p>
            </div>
        @elseif ($receipt)
            <h1 class="text-2xl font-bold tracking-tight">Your request is being processed</h1>
            <p class="mt-2 text-sm text-brand-100/70 leading-relaxed">
                Requested {{ $receipt->requested_at->diffForHumans() }}. Check back shortly.
            </p>
        @else
            <h1 class="text-2xl font-bold tracking-tight">We could not find that request</h1>
            <p class="mt-2 text-sm text-brand-100/70 leading-relaxed">
                The confirmation code did not match anything. It may have been mistyped, or the request may
                have been made against a different service.
            </p>
        @endif

        <p class="mt-6 pt-5 border-t border-white/10 text-sm text-brand-100/70 leading-relaxed">
            Chakra Productions is a video content studio. We hold Instagram data only for accounts that
            have connected to our client portal, and only to report on content we produced.
            <a href="{{ route('privacy') }}" class="text-brand-300 underline underline-offset-2 hover:text-white">
                Read our privacy policy</a>.
        </p>
    </div>
</div>

</body>
</html>
