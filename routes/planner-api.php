<?php

use App\Http\Controllers\Api\Planner\PlannerAppController;
use App\Http\Controllers\Api\Planner\PlannerAuthController;
use App\Http\Controllers\Api\Planner\PlannerItemController;
use App\Http\Controllers\Api\Planner\PlannerReleaseController;
use App\Http\Middleware\AuthenticatePlannerToken;
use Illuminate\Support\Facades\Route;

/*
 * The Chakra Planner API -- what the desktop app (Windows/Mac) talks to.
 * Documented in docs/chakra-planner/API.md; keep the two in step.
 *
 * Registered outside routes/web.php, like routes/mcp.php: no session, no
 * CSRF, no cookies. Signing in returns a bearer token and that is the only
 * way in. CORS is open for /api/* (framework default) because the app's
 * webview origin is tauri://localhost or http://tauri.localhost; with no
 * cookies involved there is nothing for another origin to borrow.
 */
Route::prefix('api/planner')->name('api.planner.')->group(function () {
    Route::post('login', [PlannerAuthController::class, 'login'])->name('login');

    // The app's own updates. Public: the updater runs before anyone signs in.
    Route::get('app/latest', [PlannerReleaseController::class, 'latest'])->name('app.latest');
    Route::get('app/update/{target}/{arch}/{current}', [PlannerReleaseController::class, 'update'])->name('app.update');
    Route::get('app/download/{platform}', [PlannerReleaseController::class, 'download'])->name('app.download');

    Route::middleware(AuthenticatePlannerToken::class)->group(function () {
        Route::get('me', [PlannerAuthController::class, 'me'])->name('me');
        Route::post('logout', [PlannerAuthController::class, 'logout'])->name('logout');

        Route::get('meta', [PlannerAppController::class, 'meta'])->name('meta');
        Route::post('refresh', [PlannerAppController::class, 'refresh'])->name('refresh');
        Route::post('realtime/token', [PlannerAppController::class, 'realtimeToken'])->name('realtime.token');

        Route::get('items', [PlannerItemController::class, 'index'])->name('items.index');
        Route::get('items/{item}', [PlannerItemController::class, 'show'])->whereNumber('item')->name('items.show');
        Route::patch('items/{item}', [PlannerItemController::class, 'update'])->whereNumber('item')->name('items.update');

        Route::post('app/releases', [PlannerReleaseController::class, 'store'])->name('app.releases.store');
    });
});
