<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Permission;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    /**
     * Wire the module permissions into Laravel's own authorization, so the
     * rest of the app can use @can and $this->authorize() rather than
     * learning a bespoke vocabulary.
     */
    public function boot(): void
    {
        /*
         * Admins pass everything. Returning null rather than false on the
         * miss is the important part -- false would short-circuit the gate
         * and refuse everyone who is not an admin, defeating the whole
         * system. null means "no opinion, carry on".
         */
        Gate::before(fn (User $user) => $user->isAdmin() ? true : null);

        foreach (Permission::MODULES as $module => $config) {
            foreach ($config['abilities'] as $ability) {
                Gate::define(
                    $module.'.'.$ability,
                    fn (User $user) => $user->hasPermission($module, $ability)
                );
            }
        }
    }
}
