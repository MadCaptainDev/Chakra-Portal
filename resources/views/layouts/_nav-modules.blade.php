{{--
    Permission-gated module links, shared by both branches of the sidebar.

    Grouped by the registry's own `group`, and each module names its own icon --
    with three modules a single hardcoded heading and one repeated document
    glyph would read as three identical rows. Adding a fourth module is still
    an entry in App\Support\Permission and no Blade at all.
--}}
@php
    use App\Support\Permission;

    $icons = [
        'scripts' => 'document',
        'shoots' => 'camera',
        'equipment' => 'briefcase',
        'clients' => 'users',
    ];

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
            <x-sidebar-link :icon="$icons[$module] ?? 'document'"
                            :href="route($module.'.index')"
                            :active="request()->routeIs($module.'.*')">
                {{ $config['label'] }}
            </x-sidebar-link>
        @endforeach
    </x-nav-section>
@endforeach
