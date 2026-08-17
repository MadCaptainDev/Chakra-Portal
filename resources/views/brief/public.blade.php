@php
    /*
     * The brand brief as a client with no login fills it.
     *
     * The wizard is shared with the portal; this page is the chrome a stranger
     * with a link needs and a signed-in client does not -- the studio has to
     * introduce itself, there is no sidebar to escape to, and the submit is
     * final.
     *
     * Standalone document rather than x-public-layout: that layout's header
     * carries a nav back to the marketing site and a "Start a project" button,
     * and both are wrong on a page somebody is ten minutes into filling.
     */
@endphp

<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Brand brief — {{ $client->name }}</title>

    {{-- A private link. Keeping it out of search results matters more here than
         anywhere else on the site: the answers include budget and competitors. --}}
    <meta name="robots" content="noindex, nofollow">

    @include('partials.favicon')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased bg-brand-900 text-white">

<header class="border-b border-white/10">
    <div class="mx-auto max-w-5xl px-4 sm:px-6 h-16 flex items-center justify-between gap-4">
        <x-application-logo class="h-8 w-auto" />
        <p class="flex items-center gap-1.5 text-xs text-brand-100/50">
            <x-icon name="check-circle" class="w-3.5 h-3.5 text-brand-300" />
            Saved automatically
        </p>
    </div>
</header>

<main class="px-4 sm:px-6 py-8 sm:py-12">
    <div class="mx-auto max-w-3xl mb-6">
        <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-300">Client onboarding</p>
        <h1 class="mt-1.5 text-3xl sm:text-4xl font-extrabold tracking-tight">Brand brief</h1>
        <p class="mt-2 max-w-2xl text-sm text-brand-100/70 leading-relaxed">
            Help us understand your brand better so we can create content and marketing strategies that are right for
            {{ $client->name }}. It takes about ten minutes, and you can stop and come back any time.
        </p>
    </div>

    @if (session('status'))
        <div class="mx-auto max-w-3xl mb-4 rounded-xl bg-brand-400/15 ring-1 ring-brand-400/25 px-4 py-3 text-sm text-brand-100" role="status">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mx-auto max-w-3xl mb-4 rounded-xl bg-red-500/10 ring-1 ring-red-400/30 px-4 py-3 text-sm text-red-200">
            Some answers still need attention — they are marked below.
        </div>
    @endif

    @include('brief._wizard', [
        'brief' => $brief,
        'saveUrl' => route('brief.public.update', $token),
        'submitUrl' => route('brief.public.submit', $token),
        'exitUrl' => null,
        'showName' => true,
        'submitLabel' => 'Submit onboarding',
        'confirm' => 'Send your brand brief? This link closes once you send it, so you will not be able to change your answers here afterwards.',
    ])
</main>

<footer class="px-4 sm:px-6 pb-10">
    <p class="mx-auto max-w-3xl text-xs text-brand-100/40">
        Questions? Reply to the message this link came from, or
        <a href="{{ route('privacy') }}" class="underline underline-offset-2 hover:text-brand-200">read how we handle your data</a>.
    </p>
</footer>

@stack('scripts')
</body>
</html>
