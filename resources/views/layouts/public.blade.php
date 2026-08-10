@php
    /*
    | Chrome for the public site -- the landing page and the portfolio screen.
    | Both used to be standalone documents; the header and footer live here so
    | there is one of each.
    |
    | Section links are anchors on the landing page and absolute links to it
    | from anywhere else, so the same nav works on every public screen.
    */
    $onHome = request()->routeIs('home');

    // Links back to the landing page carry the page the visitor was reading, so
    // the enquiry form can record what actually persuaded them. On the landing
    // page itself these stay plain anchors -- no reload, and nothing to record.
    $source = $enquirySource ?? 'landing';
    $anchor = fn (string $hash) => $onHome
        ? $hash
        : route('home', $hash === '#contact' ? ['from' => $source] : []).$hash;

    // Work only appears once there is work to show -- see PublicLayout.
    $navLinks = array_filter([
        'Services' => $anchor('#services'),
        'Work' => ($hasPortfolio ?? false) ? route('portfolio') : null,
        'Team' => $anchor('#team'),
        'Contact' => $anchor('#contact'),
    ]);
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Chakra Productions — Video content studio' }}</title>
    <meta name="description" content="{{ $description ?? 'Chakra Productions is a video content studio: short-form, YouTube long-form, scripting, editing and publishing, taken from idea to posted.' }}">

    <meta property="og:title" content="{{ $title ?? 'Chakra Productions' }}">
    <meta property="og:description" content="{{ $description ?? 'A video content studio. Idea to posted.' }}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:image" content="{{ asset('images/chakra-logo.png') }}">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="antialiased bg-brand-900 text-white" x-data="{ menuOpen: false }">

    <header class="sticky top-0 z-40 bg-brand-900/90 backdrop-blur border-b border-white/10">
        <div class="max-w-7xl mx-auto px-5 sm:px-8">
            <div class="flex items-center justify-between h-16 sm:h-20">
                <a href="{{ $onHome ? '#top' : route('home') }}" class="flex items-center shrink-0">
                    <x-application-logo class="h-8 sm:h-10 w-auto" />
                    <span class="sr-only">Chakra Productions</span>
                </a>

                <nav class="hidden md:flex items-center gap-1">
                    @foreach ($navLinks as $label => $href)
                        <a href="{{ $href }}"
                           class="inline-flex items-center min-h-[44px] px-4 text-sm font-medium text-brand-100/70 hover:text-white transition-colors">
                            {{ $label }}
                        </a>
                    @endforeach
                    <a href="{{ $anchor('#contact') }}"
                       class="ml-2 inline-flex items-center min-h-[44px] px-5 rounded-md bg-brand-400 text-white text-xs font-semibold uppercase tracking-widest hover:bg-brand-500 transition-colors">
                        Start a project
                    </a>
                </nav>

                <button type="button" @click="menuOpen = ! menuOpen"
                        class="md:hidden inline-flex items-center justify-center min-h-[44px] min-w-[44px] -mr-2 text-brand-100 hover:text-white"
                        aria-label="Menu" :aria-expanded="menuOpen ? 'true' : 'false'">
                    <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                        <path x-show="! menuOpen" stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                        <path x-show="menuOpen" x-cloak stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <nav x-show="menuOpen" x-cloak @click="menuOpen = false" class="md:hidden pb-4 space-y-1">
                @foreach ($navLinks as $label => $href)
                    <a href="{{ $href }}" class="flex items-center min-h-[44px] px-2 rounded-md text-brand-100/80 hover:bg-white/5 hover:text-white">
                        {{ $label }}
                    </a>
                @endforeach
                <a href="{{ route('login') }}" class="flex items-center min-h-[44px] px-2 rounded-md text-sm text-brand-100/50 hover:bg-white/5 hover:text-white">
                    Staff sign in
                </a>
            </nav>
        </div>
    </header>

    <main id="top">
        {{ $slot }}
    </main>

    <footer class="border-t border-white/10">
        <div class="max-w-7xl mx-auto px-5 sm:px-8 py-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <p class="text-xs text-brand-100/40">
                &copy; {{ date('Y') }} Chakra Productions. All rights reserved.
            </p>
            <a href="{{ route('login') }}"
               class="inline-flex items-center min-h-[44px] text-xs font-semibold uppercase tracking-widest text-brand-100/40 hover:text-brand-200 transition-colors">
                Staff sign in
            </a>
        </div>
    </footer>

    @stack('scripts')
</body>
</html>
