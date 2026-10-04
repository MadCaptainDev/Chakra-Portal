<?php

use App\Http\Controllers\Api\WhatsappRoutineController;
use App\Http\Controllers\Api\WidgetController;
use App\Http\Controllers\WidgetTokenController;
use App\Http\Middleware\AuthenticateMcpToken;
use App\Http\Middleware\AuthenticateWidgetToken;
use App\Http\Middleware\EnsureTokenOwnerIsAdmin;
use Illuminate\Support\Facades\Route;

/*
 * Routines API -- WhatsApp from the studio number for Claude Routines.
 * Needs "Authorization: Bearer chakra_..." with an admin's MCP token
 * (created on the Developer page); it was open to anyone before.
 */
Route::middleware([
    'api',
    AuthenticateMcpToken::class,
    EnsureTokenOwnerIsAdmin::class,
    'throttle:20,1',
])->group(function () {
    Route::post('/routines/whatsapp/send', [WhatsappRoutineController::class, 'sendToAdmin']);
    Route::post('/routines/whatsapp/send-to', [WhatsappRoutineController::class, 'sendToNumber']);
});

// The phone home-screen widget's one read. Its own key type, see
// AuthenticateWidgetToken; a widget refreshes a few times an hour at most.
Route::middleware(['api', AuthenticateWidgetToken::class, 'throttle:30,1'])
    ->get('/widget/today', [WidgetController::class, 'today'])
    ->name('api.widget.today');

/*
 * Making and revoking those keys, from the Profile page. These are ordinary
 * signed-in browser routes (session, CSRF) that only live in this file so
 * the widget's routes sit together; the /api prefix is incidental.
 */
Route::middleware(['web', 'auth'])->group(function () {
    Route::post('/profile/widget-tokens', [WidgetTokenController::class, 'store'])->name('widget-tokens.store');
    Route::delete('/profile/widget-tokens/{token}', [WidgetTokenController::class, 'destroy'])->name('widget-tokens.destroy');
});
