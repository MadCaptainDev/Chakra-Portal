<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Permission;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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

        /*
         * The MCP endpoint's rate limit.
         *
         * Counted per token rather than per IP: an AI client is chatty by
         * nature and several people behind one office connection must not
         * throttle each other. Falls back to the IP for an unauthenticated
         * request, which is what a brute-force attempt on the token looks like
         * -- and is the case the limit actually matters for.
         */
        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute(120)
            ->by($request->bearerToken() ?: $request->ip()));

        /*
         * Inbound webhooks.
         *
         * Set high on purpose. Meta batches events and retries anything it does
         * not get a prompt 200 for, so a limit tight enough to be a quota would
         * turn a busy hour into lost messages and, if enough 429s pile up, into
         * Meta disabling the subscription. This is a flood guard against
         * somebody who found the URL, nothing finer -- the signature check is
         * what actually keeps them out.
         *
         * Counted per IP, which is the only thing an unauthenticated caller
         * offers. Meta posts from a wide range, so no legitimate sender is
         * anywhere near this.
         */
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(600)
            ->by($request->ip()));

        /*
         * The backup + license API. Counted per token like 'mcp' above, for
         * the same reason -- but set lower: this is a handful of scheduled
         * backup uploads and license checks a day per product, not a chatty
         * AI client, so a much smaller ceiling still leaves comfortable room
         * while catching a misconfigured script stuck in a retry loop.
         */
        RateLimiter::for('saas-api', fn (Request $request) => Limit::perMinute(20)
            ->by($request->bearerToken() ?: $request->ip()));
    }
}
