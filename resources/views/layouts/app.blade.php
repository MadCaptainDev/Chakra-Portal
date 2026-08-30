<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#132A38">

        <title>{{ $title ? $title.' · '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}</title>

        @include('partials.favicon')

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @stack('styles')
    </head>
    <body class="font-sans antialiased text-white">
        <a href="#main"
           class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:top-2 focus:left-2 focus:px-4 focus:py-2 focus:rounded-lg focus:bg-brand-900 focus:text-white focus:text-sm focus:font-semibold">
            Skip to content
        </a>

        <div class="min-h-screen {{ $dark ? 'theme-dark bg-brand-900 text-white' : 'bg-brand-50 text-gray-900' }}"
             x-data="{ sidebarOpen: false }" @keydown.escape.window="sidebarOpen = false">

            <!-- Desktop sidebar -->
            <div class="hidden lg:flex lg:fixed lg:inset-y-0 lg:w-64 lg:flex-col bg-brand-900">
                @include('layouts.sidebar')
            </div>

            <!-- Mobile top bar -->
            <div class="lg:hidden sticky top-0 z-30 flex items-center gap-3 h-16 px-4 bg-brand-900 shadow-sm">
                {{-- Role-aware: an employee tapping the logo must not land on
                     the admin dashboard and get a 403. --}}
                <a href="{{ route(auth()->user()?->homeRoute() ?? 'home') }}" class="flex items-center shrink-0">
                    <x-application-logo class="h-8 w-auto" />
                </a>

                @if ($title)
                    <p class="min-w-0 flex-1 text-sm font-semibold text-white/90 truncate">{{ $title }}</p>
                @else
                    <span class="flex-1"></span>
                @endif

                <button @click="sidebarOpen = true"
                        class="shrink-0 inline-flex items-center justify-center w-11 h-11 -mr-2 rounded-lg text-brand-100 hover:bg-white/10 hover:text-white transition"
                        aria-label="Open menu">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </button>
            </div>

            <!-- Mobile drawer -->
            <div x-show="sidebarOpen" class="lg:hidden fixed inset-0 z-40" style="display: none;" role="dialog" aria-modal="true">
                <div x-show="sidebarOpen" x-transition.opacity @click="sidebarOpen = false"
                     class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm"></div>
                <div x-show="sidebarOpen"
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="-translate-x-full"
                     x-transition:enter-end="translate-x-0"
                     x-transition:leave="transition ease-in duration-150"
                     x-transition:leave-start="translate-x-0"
                     x-transition:leave-end="-translate-x-full"
                     class="fixed inset-y-0 left-0 w-[17rem] max-w-[85vw] bg-brand-900 shadow-2xl"
                     @click.away="sidebarOpen = false">
                    {{-- Overlaid, not stacked above the nav: a close button in
                         normal flow pushed the logo down and left the drawer
                         looking misaligned against the desktop rail. --}}
                    <button @click="sidebarOpen = false"
                            class="absolute top-3 right-3 z-10 inline-flex items-center justify-center w-10 h-10 rounded-lg text-brand-100/70 hover:bg-white/10 hover:text-white transition"
                            aria-label="Close menu">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                    @include('layouts.sidebar')
                </div>
            </div>

            <!-- Main column -->
            <div class="lg:pl-64 flex flex-col min-h-screen">
                @isset($header)
                    <header class="bg-brand-900/85 backdrop-blur border-b border-white/10 lg:sticky lg:top-0 lg:z-20">
                        <div class="mx-auto max-w-7xl px-4 py-5 sm:px-6 lg:px-8">
                            {{ $header }}
                        </div>
                    </header>
                @endisset

                <main id="main" class="flex-1 w-full mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6">
                    {{-- Both flashes used to carry a light-plane twin behind a
                         $dark ternary. There is no light signed-in screen left
                         to show it to. --}}
                    @if (session('status'))
                        <div class="mb-4 flex items-start gap-3 p-4 rounded-xl bg-brand-400/15 ring-1 ring-brand-400/40 text-brand-200"
                             role="status">
                            <x-icon name="check-circle" class="w-5 h-5 shrink-0 mt-0.5 text-brand-300" />
                            <div class="min-w-0">
                                <p class="text-sm font-medium">{{ session('status') }}</p>
                                @if (session('whatsapp_crm_url'))
                                    <a href="{{ session('whatsapp_crm_url') }}"
                                       class="mt-1 inline-block text-xs font-semibold uppercase tracking-widest text-brand-100 hover:text-white">
                                        Open in WhatsApp CRM →
                                    </a>
                                @endif
                            </div>
                        </div>
                    @endif
                    @if (session('error'))
                        <div class="mb-4 flex items-start gap-3 p-4 rounded-xl bg-red-400/15 ring-1 ring-red-400/40 text-red-200"
                             role="alert">
                            <x-icon name="alert" class="w-5 h-5 shrink-0 mt-0.5 text-red-300" />
                            <p class="text-sm font-medium">{{ session('error') }}</p>
                        </div>
                    @endif
                    {{ $slot }}
                </main>
            </div>
        </div>

        @stack('scripts')
    </body>
</html>
