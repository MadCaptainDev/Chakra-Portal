{{-- Sidebar navigation content, shared by the desktop fixed sidebar and the mobile slide-over drawer. --}}
@php $isAdmin = auth()->user()?->isAdmin(); @endphp

<div class="flex flex-col h-full">
    <div class="flex items-center h-16 px-4 shrink-0">
        <a href="{{ route(auth()->user()?->homeRoute() ?? 'home') }}" class="flex items-center">
            <x-application-logo class="h-9 w-auto" />
        </a>
    </div>

    <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto">
    @unless ($isAdmin)
        {{-- Employees get their own timesheet and nothing else. The admin
             middleware enforces this; hiding the links is only cosmetic. --}}
        <x-sidebar-link :href="route('my.dashboard')" :active="request()->routeIs('my.dashboard')">
            <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" /></svg>
            Dashboard
        </x-sidebar-link>
        <x-sidebar-link :href="route('my.timesheet')" :active="request()->routeIs('my.timesheet*')">
            <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            My Timesheet
        </x-sidebar-link>
        <x-sidebar-link :href="route('my.calendar')" :active="request()->routeIs('my.calendar')">
            <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
            Calendar
        </x-sidebar-link>
    @else
        <x-sidebar-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
            <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" /></svg>
            Dashboard
        </x-sidebar-link>
        <x-sidebar-link :href="route('invoices.index')" :active="request()->routeIs('invoices.*')">
            <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
            Invoices
        </x-sidebar-link>
        @if (Route::has('recurring.index'))
            <x-sidebar-link :href="route('recurring.index')" :active="request()->routeIs('recurring.*')">
                <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                Recurring
            </x-sidebar-link>
        @endif
        <x-sidebar-link :href="route('expenses.index')" :active="request()->routeIs('expenses.*')">
            <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" /></svg>
            Expenses
        </x-sidebar-link>
        <x-sidebar-link :href="route('emi.index')" :active="request()->routeIs('emi.*')">
            <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            EMI
        </x-sidebar-link>
        <x-sidebar-link :href="route('salaries.index')" :active="request()->routeIs('salaries.*')">
            <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" /></svg>
            Salaries
        </x-sidebar-link>
        <x-sidebar-link :href="route('bills.index')" :active="request()->routeIs('bills.*')">
            <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M9 14h6m-6-4h6m2 9H7a2 2 0 01-2-2V5a2 2 0 012-2h10a2 2 0 012 2v12a2 2 0 01-2 2z" /></svg>
            Bills
        </x-sidebar-link>
        <x-sidebar-link :href="route('clients.index')" :active="request()->routeIs('clients.*')">
            <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m5-2.13a4 4 0 100-8 4 4 0 000 8zm6 3.13a4 4 0 00-3-3.87m0 0a4 4 0 10-3-7.4" /></svg>
            Clients
        </x-sidebar-link>
        <x-sidebar-link :href="route('timesheets.index')" :active="request()->routeIs('timesheets.*')">
            <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            Timesheets
        </x-sidebar-link>
        <x-sidebar-link :href="route('announcements.index')" :active="request()->routeIs('announcements.*')">
            <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z" /></svg>
            Announcements
        </x-sidebar-link>
        @if (Route::has('users.index'))
            <x-sidebar-link :href="route('users.index')" :active="request()->routeIs('users.*')">
                <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
                Users
            </x-sidebar-link>
        @endif
        <x-sidebar-link :href="route('settings.edit')" :active="request()->routeIs('settings.*')">
            <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
            Settings
        </x-sidebar-link>
    @endunless
    </nav>

    <div class="shrink-0 border-t border-brand-800 p-4">
        <p class="text-sm font-medium text-white truncate">{{ Auth::user()->name }}</p>
        <p class="text-xs text-brand-100/70 truncate mb-3">{{ Auth::user()->email }}</p>
        <div class="flex items-center gap-3">
            <a href="{{ route('profile.edit') }}" class="text-xs font-semibold text-brand-100/80 hover:text-white">Profile</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="text-xs font-semibold text-brand-100/80 hover:text-white">Log Out</button>
            </form>
        </div>
    </div>
</div>
