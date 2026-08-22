{{-- Sidebar navigation content, shared by the desktop fixed sidebar and the mobile slide-over drawer. --}}
@php
    $user = auth()->user();
    $isAdmin = $user?->isAdmin();
    $isClient = $user?->isClient();
@endphp

<div class="flex flex-col h-full">
    <div class="flex items-center h-16 px-4 shrink-0 border-b border-white/5">
        <a href="{{ route($user?->homeRoute() ?? 'home') }}" class="flex items-center">
            <x-application-logo class="h-9 w-auto" />
        </a>
    </div>

    <nav class="flex-1 px-3 py-3 overflow-y-auto">
    @if ($isClient)
        {{-- Five links and no profile. A client has nothing to configure here,
             and an "Account" section with one dead item in it is worse than
             none. The middleware is what enforces this; the nav is cosmetic. --}}
        <x-nav-section label="{{ $user?->client?->name ?? 'Your account' }}">
            <x-sidebar-link icon="home" :href="route('client.dashboard')" :active="request()->routeIs('client.dashboard')">
                Overview
            </x-sidebar-link>
            <x-sidebar-link icon="template" :href="route('client.brief')" :active="request()->routeIs('client.brief*')">
                Brand Brief
            </x-sidebar-link>

            <x-sidebar-link icon="document" :href="route('client.invoices')" :active="request()->routeIs('client.invoices*')">
                Invoices
            </x-sidebar-link>
            <x-sidebar-link icon="sparkles" :href="route('client.work')" :active="request()->routeIs('client.work')">
                Work Delivered
            </x-sidebar-link>
            <x-sidebar-link icon="camera" :href="route('client.shoots')" :active="request()->routeIs('client.shoots')">
                Shoots
            </x-sidebar-link>
        </x-nav-section>
    @elseif (! $isAdmin)
        {{-- Employees get timesheet, calendar, and their own profile. The admin
             middleware enforces this; hiding the links is only cosmetic. --}}
        <x-nav-section label="My work">
            <x-sidebar-link icon="home" :href="route('my.dashboard')" :active="request()->routeIs('my.dashboard')">
                Dashboard
            </x-sidebar-link>
            <x-sidebar-link icon="check-circle" :href="route('my.todos')" :active="request()->routeIs('my.todos*')">
                My To-dos
            </x-sidebar-link>
            <x-sidebar-link icon="clock" :href="route('my.timesheet')" :active="request()->routeIs('my.timesheet*')">
                My Timesheet
            </x-sidebar-link>
            <x-sidebar-link icon="calendar" :href="route('my.calendar')" :active="request()->routeIs('my.calendar')">
                Calendar
            </x-sidebar-link>

            {{-- Only appears once somebody actually reports to this person.
                 Managing is not a role here, it is a fact about the org chart. --}}
            @if ($user?->managesAnyone())
                <x-sidebar-link icon="users" :href="route('my.team')" :active="request()->routeIs('my.team')">
                    Team Timesheet
                </x-sidebar-link>
                <x-sidebar-link icon="check-circle" :href="route('todos.index')" :active="request()->routeIs('todos.index')">
                    Team To-dos
                </x-sidebar-link>
            @endif
        </x-nav-section>

        {{-- Modules this person has been granted. Personal items above are
             never permissioned -- they are your own data, not a module. --}}
        @include('layouts._nav-modules')

        <x-nav-section label="Account">
            <x-sidebar-link icon="user" :href="route('profile.edit')" :active="request()->routeIs('profile.*')">
                My Profile
            </x-sidebar-link>
        </x-nav-section>
    @else
        <x-nav-section label="Overview">
            <x-sidebar-link icon="home" :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                Dashboard
            </x-sidebar-link>
            {{-- Admin-only, and inside the admin branch of this file already. --}}
            <x-sidebar-link icon="trending-up" :href="route('editors.index')" :active="request()->routeIs('editors.*')">
                Editor Output
            </x-sidebar-link>
            <x-sidebar-link icon="template" :href="route('content-dashboard.index')" :active="request()->routeIs('content-dashboard.*')">
                Content Dashboard
            </x-sidebar-link>
        </x-nav-section>

        <x-nav-section label="Team">
            <x-sidebar-link icon="check-circle" :href="route('todos.index')" :active="request()->routeIs('todos.index')">
                To-dos
            </x-sidebar-link>
            @if (Route::has('users.index'))
                <x-sidebar-link icon="user" :href="route('users.index')" :active="request()->routeIs('users.*')">
                    Users
                </x-sidebar-link>
            @endif
        </x-nav-section>

        {{-- Same partial as the employee branch: an admin passes every gate,
             so they see every module without a second list to maintain.

             Invoices, Enquiries, Expenses, Salaries, Timesheets, Announcements,
             Portfolio, Team and Master Data all used to be hardcoded above.
             They are permissioned modules now, and listing them in two places
             would show an admin two links to the same screen. What stays here
             is what the registry cannot describe: an admin-only screen, or a
             personal one that nobody needs granting for. --}}
        @include('layouts._nav-modules')

        <x-nav-section label="Setup">
            <x-sidebar-link icon="cog" :href="route('settings.edit')" :active="request()->routeIs('settings.*')">
                Settings
            </x-sidebar-link>
            <x-sidebar-link icon="chat" :href="route('whatsapp.edit')" :active="request()->routeIs('whatsapp.*')">
                WhatsApp
            </x-sidebar-link>
            <x-sidebar-link icon="sparkles" :href="route('instagram-settings.edit')" :active="request()->routeIs('instagram-settings.*')">
                Instagram
            </x-sidebar-link>
            <x-sidebar-link icon="document" :href="route('notion.edit')" :active="request()->routeIs('notion.*')">
                Notion
            </x-sidebar-link>
            <x-sidebar-link icon="bell" :href="route('push.edit')" :active="request()->routeIs('push.*')">
                Notifications
            </x-sidebar-link>
            <x-sidebar-link icon="eye" :href="route('competitor-settings.edit')" :active="request()->routeIs('competitor-settings.*')">
                Competitor Analysis
            </x-sidebar-link>
            <x-sidebar-link icon="users" :href="route('content-accounts.edit')" :active="request()->routeIs('content-accounts.*')">
                Content Accounts
            </x-sidebar-link>
            <x-sidebar-link icon="inbox" :href="route('brief-questions.index')" :active="request()->routeIs('brief-questions.*')">
                Brief Questions
            </x-sidebar-link>
            @if (Route::has('invoice-template.edit'))
                <x-sidebar-link icon="template" :href="route('invoice-template.edit')" :active="request()->routeIs('invoice-template.*')">
                    PDF Template
                </x-sidebar-link>
            @endif
        </x-nav-section>
    @endif
    </nav>

    {{-- Signed-in identity. The avatar makes it obvious at a glance which
         account a shared office machine is left logged into. --}}
    <div class="shrink-0 border-t border-white/10 p-3">
        <a href="{{ route('profile.edit') }}"
           class="flex items-center gap-3 p-2 min-h-[44px] rounded-lg hover:bg-white/5 transition group">
            <x-avatar :name="$user->name" :src="$user->avatarUrl()" size="sm" />
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-white truncate leading-tight">{{ $user->name }}</p>
                <p class="text-[11px] text-brand-100/70 truncate">{{ $user->email }}</p>
            </div>
        </a>

        <form method="POST" action="{{ route('logout') }}" class="mt-1">
            @csrf
            <button type="submit"
                    class="w-full flex items-center gap-3 px-2 py-2 min-h-[44px] rounded-lg text-xs font-semibold text-brand-100/60 hover:bg-white/5 hover:text-white transition">
                <span class="w-7 flex justify-center">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                    </svg>
                </span>
                Log Out
            </button>
        </form>
    </div>
</div>
