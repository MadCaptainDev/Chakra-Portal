<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
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
    {{--
        No <main>, no sidebar, no max-width -- the page IS the tool. Sized
        with h-screen plus an inline 100dvh override rather than Tailwind's
        h-dvh utility: this project pins tailwindcss ^3.1, and h-dvh only
        exists from 3.4 -- the inline style is simply ignored by any browser
        that predates dvh support, leaving h-screen as the fallback.
    --}}
    <body class="h-screen overflow-hidden font-sans antialiased text-white" style="height: 100dvh">
        <div class="theme-dark bg-brand-900 h-full flex flex-col overflow-hidden">
            {{ $slot }}
        </div>

        {{--
            layouts/app.blade.php renders session('status')/session('error')
            inside <main>, which this layout has none of -- without this, a
            redirect back here (WhatsappFlowController::update()'s "saved",
            or a validation failure) would land silently. Fixed + auto-
            dismissed rather than inline: the whole point of this layout is
            that nothing pushes the canvas around.
        --}}
        @if (session('status') || session('error'))
            <div x-data="{ show: true }" x-show="show" x-cloak
                 x-init="setTimeout(() => show = false, 5000)"
                 x-transition
                 class="fixed bottom-4 right-4 z-50 max-w-sm flex items-start gap-3 p-4 rounded-xl shadow-lg ring-1
                        {{ session('status') ? 'bg-brand-400/15 ring-brand-400/40 text-brand-200' : 'bg-red-400/15 ring-red-400/40 text-red-200' }}"
                 role="{{ session('status') ? 'status' : 'alert' }}">
                <x-icon :name="session('status') ? 'check-circle' : 'alert'"
                        class="w-5 h-5 shrink-0 mt-0.5 {{ session('status') ? 'text-brand-300' : 'text-red-300' }}" />
                <p class="text-sm font-medium">{{ session('status') ?? session('error') }}</p>
                <button type="button" @click="show = false" class="ml-auto shrink-0 text-current/60 hover:text-current" aria-label="Dismiss">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        @endif

        @stack('scripts')
    </body>
</html>
