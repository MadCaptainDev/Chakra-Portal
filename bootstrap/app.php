<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        /*
         * The MCP endpoint, registered by hand so it inherits no middleware
         * group at all. Everything it needs is named here and nothing else
         * applies -- in particular no session and no CSRF, which are meaningless
         * to a command-line client and would be a liability if a browser ever
         * reached this route holding a cookie.
         */
        then: function (): void {
            Route::middleware([
                'throttle:mcp',
                \App\Http\Middleware\AuthenticateMcpToken::class,
            ])->group(base_path('routes/mcp.php'));

            /*
             * Inbound callbacks from other people's servers -- currently Meta's
             * WhatsApp Cloud API. Registered here for the same reason as the
             * MCP endpoint: no session and no CSRF, neither of which a webhook
             * can produce and both of which would only be a liability on a
             * route the whole internet can reach.
             *
             * Each route names its own proof of authenticity. The throttle is
             * the one thing they share, and it is a flood guard rather than a
             * quota: it sits well above anything Meta actually sends, because a
             * webhook this endpoint drops is a message the studio never sees.
             */
            Route::middleware(['throttle:webhooks'])
                ->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'recurring.catchup' => \App\Http\Middleware\EnsureRecurringInvoicesGenerated::class,
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
            'client' => \App\Http\Middleware\EnsureUserIsClient::class,
            'module' => \App\Http\Middleware\EnsureModulePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
