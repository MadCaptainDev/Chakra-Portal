{{--
    Permission-gated module links, shared by both branches of the sidebar.

    Registry-driven: adding a module to App\Support\Permission puts it here for
    everyone who has been granted it, with no Blade to edit. The section itself
    disappears when a user can reach none of them, rather than leaving an empty
    heading behind.
--}}
@php
    use App\Support\Permission;

    $visible = collect(Permission::MODULES)
        ->filter(fn ($config, $module) => auth()->user()?->can($module.'.view'))
        // A module whose route has not been written yet must not be linked.
        ->filter(fn ($config, $module) => Route::has($module.'.index'));
@endphp

@if ($visible->isNotEmpty())
    <x-nav-section label="Production">
        @foreach ($visible as $module => $config)
            <x-sidebar-link icon="document"
                            :href="route($module.'.index')"
                            :active="request()->routeIs($module.'.*')">
                {{ $config['label'] }}
            </x-sidebar-link>
        @endforeach
    </x-nav-section>
@endif
