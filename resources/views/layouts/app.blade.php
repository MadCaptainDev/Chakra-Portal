<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased">
        <div class="min-h-screen bg-gray-50" x-data="{ sidebarOpen: false }">

            <!-- Desktop sidebar -->
            <div class="hidden lg:flex lg:fixed lg:inset-y-0 lg:w-64 lg:flex-col bg-brand-900">
                @include('layouts.sidebar')
            </div>

            <!-- Mobile top bar -->
            <div class="lg:hidden sticky top-0 z-30 flex items-center justify-between h-16 px-4 bg-brand-900">
                <a href="{{ route('dashboard') }}" class="flex items-center">
                    <x-application-logo class="h-8 w-auto" />
                </a>
                <button @click="sidebarOpen = true" class="p-2 -mr-2 text-brand-100 hover:text-white" aria-label="Open menu">
                    <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </button>
            </div>

            <!-- Mobile drawer -->
            <div x-show="sidebarOpen" class="lg:hidden fixed inset-0 z-40" style="display: none;">
                <div x-show="sidebarOpen" x-transition.opacity @click="sidebarOpen = false" class="fixed inset-0 bg-gray-900/50"></div>
                <div x-show="sidebarOpen"
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="-translate-x-full"
                     x-transition:enter-end="translate-x-0"
                     x-transition:leave="transition ease-in duration-150"
                     x-transition:leave-start="translate-x-0"
                     x-transition:leave-end="-translate-x-full"
                     class="fixed inset-y-0 left-0 w-64 bg-brand-900 shadow-xl"
                     @click.away="sidebarOpen = false">
                    <div class="flex justify-end p-2">
                        <button @click="sidebarOpen = false" class="p-2 text-brand-100 hover:text-white" aria-label="Close menu">
                            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    @include('layouts.sidebar')
                </div>
            </div>

            <!-- Main column -->
            <div class="lg:pl-64 flex flex-col min-h-screen">
                @isset($header)
                    <header class="bg-white border-b border-gray-200">
                        <div class="px-4 py-5 sm:px-6 lg:px-8">
                            {{ $header }}
                        </div>
                    </header>
                @endisset

                <main class="flex-1 px-4 sm:px-6 lg:px-8 py-6">
                    @if (session('status'))
                        <div class="mb-4 p-4 rounded-md bg-green-100 text-green-800">{{ session('status') }}</div>
                    @endif
                    @if (session('error'))
                        <div class="mb-4 p-4 rounded-md bg-red-100 text-red-800">{{ session('error') }}</div>
                    @endif
                    {{ $slot }}
                </main>
            </div>
        </div>
    </body>
</html>
