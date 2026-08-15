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
