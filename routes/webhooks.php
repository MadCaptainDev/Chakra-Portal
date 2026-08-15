<?php

use App\Http\Controllers\WhatsappWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * Callbacks from other people's servers.
 *
 * Registered outside routes/web.php for the same reason the MCP endpoint is:
 * nothing here should ever pick up the web middleware group. No session is
 * started, no CSRF token is expected -- Meta has no cookie and no token to send
 * -- and no cookie this endpoint sees can be turned into a browser login.
 *
 * The security is per-route and named on the route itself, so it cannot be lost
 * by someone tidying a middleware group later.
 */

/*
 * WhatsApp Cloud API.
 *
 * One URL, two verbs, because that is what Meta requires: the same callback URL
 * takes the GET handshake when the subscription is saved and the POST events
 * forever after. They are separate actions with separate proofs -- the verify
 * token for GET, the request signature for POST -- so only POST carries the
 * signature middleware.
 */
Route::prefix('webhooks')->name('webhooks.')->group(function (): void {
    Route::get('whatsapp', [WhatsappWebhookController::class, 'verify'])
        ->name('whatsapp.verify');

    Route::post('whatsapp', [WhatsappWebhookController::class, 'receive'])
        ->middleware(\App\Http\Middleware\VerifyWhatsappSignature::class)
        ->name('whatsapp.receive');
});
