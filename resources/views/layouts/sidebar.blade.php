{{-- Sidebar navigation content, shared by the desktop fixed sidebar and the mobile slide-over drawer. --}}
@php
    $user = auth()->user();
    $isAdmin = $user?->isAdmin();
@endphp

<div class="flex flex-col h-full">
    <div class="flex items-center h-16 px-4 shrink-0 border-b border-white/5">
        <a href="{{ route($user?->homeRoute() ?? 'home') }}" class="flex items-center">
            <x-application-logo class="h-9 w-auto" />
        </a>
    </div>

    <nav class="flex-1 px-3 py-3 overflow-y-auto">
    @unless ($isAdmin)
        {{-- Employees get timesheet, calendar, and their own profile. The admin
             middleware enforces this; hiding the links is only cosmetic. --}}
        <x-nav-section label="My work">
            <x-sidebar-link icon="home" :href="route('my.dashboard')" :active="request()->routeIs('my.dashboard')">
                Dashboard
            </x-sidebar-link>
            <x-sidebar-link icon="clock" :href="route('my.timesheet')" :active="request()->routeIs('my.timesheet*')">
                My Timesheet
            </x-sidebar-link>
            <x-sidebar-link icon="calendar" :href="route('my.calendar')" :active="request()->routeIs('my.calendar')">
                Calendar
            </x-sidebar-link>
        </x-nav-section>

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
        </x-nav-section>

        <x-nav-section label="Money in">
            <x-sidebar-link icon="document" :href="route('invoices.index')" :active="request()->routeIs('invoices.*')">
                Invoices
            </x-sidebar-link>
            @if (Route::has('recurring.index'))
                <x-sidebar-link icon="refresh" :href="route('recurring.index')" :active="request()->routeIs('recurring.*')">
                    Recurring
                </x-sidebar-link>
            @endif
            <x-sidebar-link icon="users" :href="route('clients.index')" :active="request()->routeIs('clients.*')">
                Clients
            </x-sidebar-link>
            @if (Route::has('enquiries.index'))
                @php $unreadEnquiries = \App\Models\Enquiry::unreadCount(); @endphp
                <x-sidebar-link icon="mail" :href="route('enquiries.index')" :active="request()->routeIs('enquiries.*')">
                    Enquiries
                    @if ($unreadEnquiries > 0)
                        <span class="ml-auto shrink-0 inline-flex items-center justify-center min-w-[22px] h-[22px] px-1.5 rounded-full bg-brand-400 text-brand-900 text-[11px] font-bold">
                            {{ $unreadEnquiries > 99 ? '99+' : $unreadEnquiries }}
                        </span>
                    @endif
                </x-sidebar-link>
            @endif
        </x-nav-section>

        <x-nav-section label="Money out">
            <x-sidebar-link icon="card" :href="route('expenses.index')"
                            :active="request()->routeIs('expenses.*', 'salaries.*', 'bills.*', 'emi.*', 'other.*')">
                Expenses
            </x-sidebar-link>
        </x-nav-section>

        <x-nav-section label="Team">
            <x-sidebar-link icon="clock" :href="route('timesheets.index')" :active="request()->routeIs('timesheets.*')">
                Timesheets
            </x-sidebar-link>
            <x-sidebar-link icon="megaphone" :href="route('announcements.index')" :active="request()->routeIs('announcements.*')">
                Announcements
            </x-sidebar-link>
            @if (Route::has('users.index'))
                <x-sidebar-link icon="user" :href="route('users.index')" :active="request()->routeIs('users.*')">
                    Users
                </x-sidebar-link>
            @endif
        </x-nav-section>

        <x-nav-section label="Website">
            <x-sidebar-link icon="sparkles" :href="route('portfolio.index')"
                            :active="request()->routeIs('portfolio.*', 'portfolio-categories.*')">
                Portfolio
            </x-sidebar-link>
            <x-sidebar-link icon="users" :href="route('team.index')" :active="request()->routeIs('team.*')">
                Team
            </x-sidebar-link>
        </x-nav-section>

        <x-nav-section label="Setup">
            <x-sidebar-link icon="cog" :href="route('settings.edit')" :active="request()->routeIs('settings.*')">
                Settings
            </x-sidebar-link>
            @if (Route::has('invoice-template.edit'))
                <x-sidebar-link icon="template" :href="route('invoice-template.edit')" :active="request()->routeIs('invoice-template.*')">
                    PDF Template
                </x-sidebar-link>
            @endif
        </x-nav-section>
    @endunless
    </nav>

    {{-- Signed-in identity. The avatar makes it obvious at a glance which
         account a shared office machine is left logged into. --}}
    <div class="shrink-0 border-t border-white/10 p-3">
        <a href="{{ route('profile.edit') }}"
           class="flex items-center gap-3 p-2 min-h-[44px] rounded-lg hover:bg-white/5 transition group">
            <x-avatar :name="$user->name" :src="$user->avatarUrl()" size="sm" />
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-white truncate leading-tight">{{ $user->name }}</p>
                <p class="text-[11px] text-brand-100/50 truncate">{{ $user->email }}</p>
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
