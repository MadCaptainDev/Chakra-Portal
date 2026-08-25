{{--
    Permission-gated module links, shared by both branches of the sidebar.

    Grouped by the registry's own `group`, and each module names its own label,
    icon and optional badge -- so adding a module is an entry in
    App\Support\Permission and no Blade at all. Admins see the same list without
    a second one to maintain, because Gate::before passes them everything.
--}}
@php
    use App\Support\Permission;

    $isAdmin = auth()->user()?->isAdmin() ?? false;

    $groups = collect(Permission::grouped())
        ->map(fn ($modules) => collect($modules)
            ->filter(fn ($config, $module) => auth()->user()?->can($module.'.view'))
            // A module whose index route has not been written yet must not be
            // linked into a 404.
            ->filter(fn ($config, $module) => Route::has($module.'.index')))
        ->reject(fn ($modules) => $modules->isEmpty());
@endphp

@foreach ($groups as $group => $modules)
    @php
        // Open the accordion when any module (or nested) route in this group is current.
        $groupActive = $modules->keys()->contains(function (string $module) {
            if (request()->routeIs($module.'.*')) {
                return true;
            }
            if ($module === 'shoots' && request()->routeIs('equipment.*')) {
                return true;
            }
            if ($module === 'invoices' && request()->routeIs('recurring.*')) {
                return true;
            }
            if ($module === 'routines' && request()->routeIs('routines.calendar')) {
                return true;
            }
            if ($module === 'saas-products' && request()->routeIs('developer.*')) {
                return true;
            }

            return false;
        });
    @endphp
    <x-nav-section :label="$group" :collapsible="$isAdmin" :force-open="$groupActive">
        @foreach ($modules as $module => $config)
            {{-- Equipment keeps its own module/permission/routes untouched --
                 it just does not get a fourth top-level Production row of its
                 own. Rendered nested under Shoots below instead. --}}
            @continue($module === 'equipment')

            @php
                // Badges are counts, and a zero is not worth a pill.
                $badge = isset($config['badge']) ? (int) call_user_func($config['badge']) : 0;

                // Routines leads with "who still owes what", not the list of
                // definitions -- checking is the daily job, editing is rare.
                $href = $module === 'routines' && Route::has('routines.checking')
                    ? route('routines.checking')
                    : route($module.'.index');
            @endphp
            <x-sidebar-link :icon="$config['icon'] ?? 'document'"
                            :href="$href"
                            :active="request()->routeIs($module.'.*')">
                {{ $config['label'] }}
                @if ($badge > 0)
                    <span class="ml-auto shrink-0 inline-flex items-center justify-center min-w-[22px] h-[22px] px-1.5 rounded-full bg-brand-400 text-brand-900 text-[11px] font-bold">
                        {{ $badge > 99 ? '99+' : $badge }}
                    </span>
                @endif
            </x-sidebar-link>

            {{-- Recurring is a sub-screen of Invoices rather than a module of
                 its own -- it has no separate permission, and the sidebar is
                 the only way in. Rendered here so whoever can manage invoices
                 reaches it, admin or not. --}}
            @if ($module === 'invoices' && Route::has('recurring.index') && auth()->user()?->can('invoices.manage'))
                <x-sidebar-link icon="refresh" :href="route('recurring.index')" :active="request()->routeIs('recurring.*')">
                    Recurring
                </x-sidebar-link>
            @endif

            @if ($module === 'routines' && Route::has('routines.calendar'))
                <x-sidebar-link icon="calendar" :href="route('routines.calendar')" :active="request()->routeIs('routines.calendar')">
                    Calendar
                </x-sidebar-link>
            @endif

            {{-- Equipment, nested under Shoots: planning a shoot and ticking
                 the kit onto it are one flow for the person doing it, even
                 though quartermaster work (retiring/adding equipment) keeps
                 its own separate permission. --}}
            @if ($module === 'shoots' && Route::has('equipment.index') && auth()->user()?->can('equipment.view'))
                <x-sidebar-link icon="briefcase" :href="route('equipment.index')" :active="request()->routeIs('equipment.*')">
                    Equipment
                </x-sidebar-link>
            @endif

            {{-- The Swagger/API reference lives on its own page (never nested
                 inside a specific client or product), reached from here --
                 "sidebar sub-heading in App Studio, named Developer" is the
                 whole spec for where this goes. --}}
            @if ($module === 'saas-products' && Route::has('developer.index') && auth()->user()?->can('saas-products.manage'))
                <x-sidebar-link icon="desktop" :href="route('developer.index')" :active="request()->routeIs('developer.*')">
                    Developer
                </x-sidebar-link>
            @endif
        @endforeach
    </x-nav-section>
@endforeach
