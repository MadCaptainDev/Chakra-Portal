<?php

use App\Http\Controllers\Api\Mobile\MobileActionController;
use App\Http\Controllers\Api\Mobile\MobileAuthController;
use App\Http\Controllers\Api\Mobile\MobileNotificationController;
use App\Http\Controllers\Api\Mobile\MobilePushTokenController;
use App\Http\Controllers\Api\WidgetController;
use App\Http\Controllers\My\EditingController;
use App\Http\Middleware\AuthenticateMobileToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

/*
 * The Chakra mobile app API (Flutter, iOS/Android).
 * Documented in docs/chakra-mobile/API.md -- keep the two in step.
 *
 * Same no-session shape as routes/planner-api.php (registered beside it in
 * bootstrap/app.php): a bearer token from POST login is the only way in.
 * The token is a Planner session token, so /api/planner/* (the planner
 * screens) accepts it as well.
 */
Route::prefix('api/mobile')->name('api.mobile.')->group(function () {
    Route::post('login', [MobileAuthController::class, 'login'])->name('login');

    Route::middleware(AuthenticateMobileToken::class)->group(function () {
        Route::get('me', [MobileAuthController::class, 'me'])->name('me');
        Route::post('logout', [MobileAuthController::class, 'logout'])->name('logout');

        // Home screen + home-screen widgets: the same summary as the Scriptable widget.
        Route::get('today', [WidgetController::class, 'today'])->name('today');

        Route::get('notifications', [MobileNotificationController::class, 'index'])->name('notifications.index');
        Route::post('notifications/read', [MobileNotificationController::class, 'read'])->name('notifications.read');

        Route::post('push-token', [MobilePushTokenController::class, 'store'])->name('push-token.store');
        Route::delete('push-token', [MobilePushTokenController::class, 'destroy'])->name('push-token.destroy');

        // My Editing, as in the portal and the desktop planner's widget.
        Route::get('editing', [EditingController::class, 'board'])->name('editing.board');
        Route::post('editing', [EditingController::class, 'store'])->name('editing.store');
        Route::post('editing/{todo}/act', [EditingController::class, 'act'])->middleware(SubstituteBindings::class)->whereNumber('todo')->name('editing.act');

        // Everything else: the portal's tool classes, gated by role and permission.
        Route::get('actions', [MobileActionController::class, 'index'])->name('actions.index');
        Route::post('actions/{name}', [MobileActionController::class, 'run'])->where('name', '[a-z0-9_]+')->name('actions.run');
    });
});
