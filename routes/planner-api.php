<?php

use App\Http\Controllers\Api\Planner\PlannerAppController;
use App\Http\Controllers\Api\Planner\PlannerAuthController;
use App\Http\Controllers\Api\Planner\PlannerItemController;
use App\Http\Controllers\Api\Planner\PlannerOffloadController;
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

        // The top-bar editing widget: the same My Editing actions as the
        // portal (EditingController decides who may do what to which video).
        Route::get('editing', [\App\Http\Controllers\My\EditingController::class, 'board'])->name('editing.board');
        Route::post('editing', [\App\Http\Controllers\My\EditingController::class, 'store'])->name('editing.store');
        Route::post('editing/{todo}/act', [\App\Http\Controllers\My\EditingController::class, 'act'])->middleware(\Illuminate\Routing\Middleware\SubstituteBindings::class)->whereNumber('todo')->name('editing.act');

        // Send a cut for review from the app: the portal's own chunked upload
        // (VideoReviewController decides who may upload to which card). Named
        // editing.* so a widget-only account can send its own videos too.
        Route::post('editing/{todo}/review', [\App\Http\Controllers\VideoReviewController::class, 'start'])->middleware(\Illuminate\Routing\Middleware\SubstituteBindings::class)->whereNumber('todo')->name('editing.review.start');
        Route::post('editing/review/{upload}/chunk', [\App\Http\Controllers\VideoReviewController::class, 'chunk'])->name('editing.review.chunk');
        Route::post('editing/review/{upload}/finish', [\App\Http\Controllers\VideoReviewController::class, 'finish'])->name('editing.review.finish');

        // The editing floor for admins and managers (the controller checks; see PlannerEditorUpdatesController).
        Route::get('editor-updates', [\App\Http\Controllers\Api\Planner\PlannerEditorUpdatesController::class, 'show'])->name('editor-updates');

        // Card offload (PlannerOffloadController): shoots and their logged
        // takes, clips reported copied, and each camera's clock offset.
        // Named offload.* so crew without the planners can offload too.
        Route::get('shoots', [PlannerOffloadController::class, 'shoots'])->name('offload.shoots');
        Route::post('shoot-videos/{shootVideo}/footage-copied', [PlannerOffloadController::class, 'footageCopied'])->middleware(\Illuminate\Routing\Middleware\SubstituteBindings::class)->whereNumber('shootVideo')->name('offload.footage-copied');
        Route::post('cameras/{serial}', [PlannerOffloadController::class, 'camera'])->where('serial', '[A-Za-z0-9._-]{1,64}')->name('offload.camera');

        Route::get('items', [PlannerItemController::class, 'index'])->name('items.index');
        Route::get('items/{item}', [PlannerItemController::class, 'show'])->whereNumber('item')->name('items.show');
        Route::get('items/{item}/script', [\App\Http\Controllers\Api\Planner\PlannerScriptController::class, 'show'])->whereNumber('item')->name('items.script');
        Route::patch('items/{item}', [PlannerItemController::class, 'update'])->whereNumber('item')->name('items.update');
        Route::post('items', [PlannerItemController::class, 'store'])->name('items.store');
        Route::post('items/{item}/captions', [\App\Http\Controllers\Api\Planner\PlannerCaptionController::class, 'write'])->whereNumber('item')->name('items.captions');
        Route::put('items/{item}/caption', [\App\Http\Controllers\Api\Planner\PlannerCaptionController::class, 'save'])->whereNumber('item')->name('items.caption');

        Route::post('app/releases', [PlannerReleaseController::class, 'store'])->name('app.releases.store');
    });
});
