<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#132A38">

        <title>{{ $title ?? 'Staff sign in' }} · {{ config('app.name', 'Chakra Productions') }}</title>

        @include('partials.favicon')

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>

    {{--
        Two panels: the studio's side of the story on the left, the form on the
        right. They stack on a phone via auto-fit -- below roughly 800px there
        is only ever one column, and the pitch panel drops out entirely rather
        than pushing the form below the fold, because someone signing in on
        their phone wants the fields, not the copy.
    --}}
    {{-- data-dark-forms scopes the autofill override in app.css: Chrome would
         otherwise repaint these fields near-white the moment it offers a saved
         password. --}}
    <body class="h-full font-sans antialiased bg-brand-900 text-white" data-dark-forms>
        <div class="min-h-full grid lg:grid-cols-2">

            {{-- Pitch panel --}}
            <div class="relative hidden lg:flex flex-col justify-between overflow-hidden p-8 xl:p-16
                        bg-[linear-gradient(165deg,#1e4a5c_0%,#132a38_70%)]">
                <div aria-hidden="true"
                     class="animate-halo pointer-events-none absolute -right-[20%] bottom-[30%] left-[30%] h-[480px]
                            bg-[radial-gradient(closest-side,rgba(47,110,132,.75),transparent)]"></div>

                <a href="{{ route('home') }}" class="relative flex items-center gap-3 w-fit">
                    <x-application-logo class="h-9 w-auto" />
                    <span class="sr-only">Chakra Productions</span>
                </a>

                <div class="relative animate-rise-in">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-300">Staff portal</p>
                    {{-- 19ch, not the mock's 14ch: at this size 14ch broke the
                         second sentence across two ragged lines. --}}
                    <h1 class="mt-4 max-w-[19ch] text-4xl xl:text-5xl font-extrabold leading-[1.06] text-balance">
                        Log the hours. We&rsquo;ll do the maths.
                    </h1>
                    <p class="mt-5 max-w-[40ch] text-base text-brand-100/70 leading-relaxed">
                        Shoots, edits and posts &mdash; recorded against the right client, totalled by day and month.
                    </p>
                </div>

                <div class="relative flex flex-wrap gap-7 text-xs text-brand-100/60">
                    <span>Shooting</span>
                    <span>Editing</span>
                    <span>Posting</span>
                    <span>Other</span>
                </div>
            </div>

            {{-- Form panel --}}
            <div class="flex items-center justify-center p-6 sm:p-10 xl:p-16">
                <div class="w-full max-w-[400px] animate-rise-in-slow">
                    {{-- The mark only appears here on small screens, where the
                         pitch panel that normally carries it is hidden. --}}
                    <a href="{{ route('home') }}" class="lg:hidden inline-flex mb-10">
                        <x-application-logo class="h-9 w-auto" />
                        <span class="sr-only">Chakra Productions</span>
                    </a>

                    {{ $slot }}
                </div>
            </div>
        </div>
    </body>
</html>
