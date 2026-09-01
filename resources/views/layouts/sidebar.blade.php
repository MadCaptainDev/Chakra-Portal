{{-- Sidebar navigation content, shared by the desktop fixed sidebar and the mobile slide-over drawer. --}}
@php
    use App\Support\Permission;
    use Illuminate\Support\Str;

    $user = auth()->user();
    $isAdmin = $user?->isAdmin();
    $isClient = $user?->isClient();

    // Admin Permission groups start collapsed unless the current route lives in them.
    // Keys match Str::slug of Permission group labels (see x-nav-section).
    $adminOpenGroups = collect(Permission::grouped())
        ->mapWithKeys(function ($modules, string $group) {
            $active = collect($modules)->keys()->contains(function (string $module) {
                if (request()->routeIs($module.'.*')) {
                    return true;
                }
                if ($module === 'shoots' && request()->routeIs('equipment.*')) {
                    return true;
                }
                if ($module === 'invoices' && request()->routeIs('recurring.*')) {
                    return true;
                }
                if ($module === 'saas-products' && request()->routeIs('developer.*')) {
                    return true;
                }

                return false;
            });

            return [Str::slug($group) => $active];
        })
        ->all();
@endphp

<div class="flex flex-col h-full"
     x-data="{
        navQ: '',
        openGroups: @js($adminOpenGroups),
        linkMatches(el) {
            const q = this.navQ.trim().toLowerCase();
            if (!q) return true;
            return (el.innerText || '').toLowerCase().includes(q);
        },
        sectionHasMatch(id) {
            const q = this.navQ.trim().toLowerCase();
            if (!q) return true;
            const root = document.getElementById(id);
            if (!root) return true;
            return Array.from(root.querySelectorAll('[data-nav-link]')).some((a) =>
                (a.innerText || '').toLowerCase().includes(q)
            );
        },
        isGroupOpen(key) {
            if (this.navQ.trim().length > 0) return true;
            return !!this.openGroups[key];
        },
        toggleGroup(key) {
            this.openGroups[key] = !this.openGroups[key];
        },
        hasNavMatches() {
            const q = this.navQ.trim().toLowerCase();
            if (!q) return true;
            return Array.from(this.$root.querySelectorAll('[data-nav-link]')).some((a) =>
                (a.innerText || '').toLowerCase().includes(q)
            );
        }
     }">
    <div class="flex items-center h-16 px-4 shrink-0 border-b border-white/5">
        <a href="{{ route($user?->homeRoute() ?? 'home') }}" class="flex items-center">
            <x-application-logo class="h-9 w-auto" />
        </a>
    </div>

    <nav class="flex-1 px-3 py-3 overflow-y-auto">
    @if ($isClient)
        {{-- Six links and no profile. A client has nothing to configure here
             beyond their own social connection, and an "Account" section
             with one dead item in it is worse than none. The middleware is
             what enforces this; the nav is cosmetic. --}}
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
            <x-sidebar-link icon="globe" :href="route('client.social')" :active="request()->routeIs('client.social')">
                Social
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
            <x-sidebar-link icon="refresh" :href="route('my.routines')" :active="request()->routeIs('my.routines*')">
                My Routines
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
        <div class="pb-3">
            <label for="admin-nav-filter" class="sr-only">Filter menu</label>
            <div class="flex items-center gap-2 min-h-[40px] px-3 rounded-lg bg-white/5 border border-white/10 focus-within:border-brand-400 focus-within:ring-1 focus-within:ring-brand-400/60">
                <svg class="pointer-events-none shrink-0 w-4 h-4 text-brand-200/50" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z" />
                </svg>
                <input id="admin-nav-filter" type="search" x-model="navQ"
                       placeholder="Filter menu…"
                       autocomplete="off"
                       class="flex-1 min-w-0 min-h-[38px] border-0 bg-transparent p-0 text-sm text-white placeholder:text-brand-100/40 focus:ring-0 focus:outline-none" />
            </div>
            <p class="mt-2 px-1 text-[11px] text-brand-100/45" x-show="navQ.trim().length > 0 && !hasNavMatches()" x-cloak>
                No matches
            </p>
        </div>

        {{-- Working admins (linked to a Salaries row) log hours and to-dos the
             same way employees do. Keep admin access for everything else; these
             links are the personal /my/* path the employee branch already has.
             Placed first so the day-to-day work path is not buried under Overview. --}}
        @if ($user?->logsWork())
            <x-nav-section label="My work">
                <x-sidebar-link icon="home" :href="route('my.dashboard')" :active="request()->routeIs('my.dashboard')">
                    My Dashboard
                </x-sidebar-link>
                <x-sidebar-link icon="check-circle" :href="route('my.todos')" :active="request()->routeIs('my.todos*')">
                    My To-dos
                </x-sidebar-link>
                <x-sidebar-link icon="refresh" :href="route('my.routines')" :active="request()->routeIs('my.routines*')">
                    My Routines
                </x-sidebar-link>
                <x-sidebar-link icon="clock" :href="route('my.timesheet')" :active="request()->routeIs('my.timesheet*')">
                    My Timesheet
                </x-sidebar-link>
                <x-sidebar-link icon="calendar" :href="route('my.calendar')" :active="request()->routeIs('my.calendar')">
                    Calendar
                </x-sidebar-link>
                @if ($user?->managesAnyone())
                    <x-sidebar-link icon="users" :href="route('my.team')" :active="request()->routeIs('my.team')">
                        Team Timesheet
                    </x-sidebar-link>
                @endif
            </x-nav-section>
        @endif

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
                {{ $user?->logsWork() ? 'Team To-dos' : 'To-dos' }}
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
            {{-- Was nine separate links (Settings, WhatsApp, Instagram, Notion,
                 Notifications, Competitor Analysis, Content Accounts, Brief
                 Questions, PDF Template) -- now one destination. Each is still
                 its own page and its own route; x-settings-layout is what
                 turns them into tabs of one screen instead of nine unrelated
                 sidebar rows. Active state matches any of them, not just
                 settings.* -- this row should read as "current" on all nine. --}}
            <x-sidebar-link icon="cog" :href="route('settings.edit')"
                            :active="request()->routeIs(['settings.*', 'whatsapp.*', 'instagram-settings.*', 'notion.*', 'push.*', 'competitor-settings.*', 'content-accounts.*', 'brief-questions.*', 'invoice-template.*'])">
                Settings
            </x-sidebar-link>
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
