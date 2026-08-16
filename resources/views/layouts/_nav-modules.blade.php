{{--
    Permission-gated module links, shared by both branches of the sidebar.

    Grouped by the registry's own `group`, and each module names its own label,
    icon and optional badge -- so adding a module is an entry in
    App\Support\Permission and no Blade at all. Admins see the same list without
    a second one to maintain, because Gate::before passes them everything.
--}}
@php
    use App\Support\Permission;

    $groups = collect(Permission::grouped())
        ->map(fn ($modules) => collect($modules)
            ->filter(fn ($config, $module) => auth()->user()?->can($module.'.view'))
            // A module whose index route has not been written yet must not be
            // linked into a 404.
            ->filter(fn ($config, $module) => Route::has($module.'.index')))
        ->reject(fn ($modules) => $modules->isEmpty());
@endphp

@foreach ($groups as $group => $modules)
    <x-nav-section :label="$group">
        @foreach ($modules as $module => $config)
            @php
                // Badges are counts, and a zero is not worth a pill.
                $badge = isset($config['badge']) ? (int) call_user_func($config['badge']) : 0;
            @endphp
            <x-sidebar-link :icon="$config['icon'] ?? 'document'"
                            :href="route($module.'.index')"
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
        @endforeach
    </x-nav-section>
@endforeach
