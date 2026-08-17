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

/*
 * Instagram.
 *
 * Same two-verb shape as WhatsApp and for the same reason: one callback URL
 * takes the GET handshake when the subscription is saved and the POST events
 * forever after. Only POST carries the signature middleware -- the GET proves
 * itself with the verify token instead.
 *
 * Emphatically NOT /oauth/instagram/callback, which is where a signed-in
 * person lands after authorising. That one is behind `auth`; this one cannot
 * be, because Meta has no cookie to send.
 */
Route::prefix('webhooks')->name('webhooks.')->group(function (): void {
    Route::get('instagram', [\App\Http\Controllers\InstagramWebhookController::class, 'verify'])
        ->name('instagram.verify');

    Route::post('instagram', [\App\Http\Controllers\InstagramWebhookController::class, 'receive'])
        ->middleware(\App\Http\Middleware\VerifyInstagramSignature::class)
        ->name('instagram.receive');

    /*
     * The two URLs Meta requires an app to publish before it will ship.
     *
     * These do NOT carry VerifyInstagramSignature: they authenticate with a
     * `signed_request` form field rather than the X-Hub-Signature-256 header,
     * which is a different mechanism on the same app secret. See SignedRequest.
     */
    Route::post('instagram/deauthorize', [\App\Http\Controllers\InstagramWebhookController::class, 'deauthorize'])
        ->name('instagram.deauthorize');

    Route::post('instagram/data-deletion', [\App\Http\Controllers\InstagramWebhookController::class, 'dataDeletion'])
        ->name('instagram.data-deletion');
});

/*
 * Where a person checks what became of their deletion request. Public, and
 * unauthenticated on purpose -- they have no account here, which is rather the
 * point of having asked.
 */
Route::get('instagram/deletion-status', [\App\Http\Controllers\InstagramWebhookController::class, 'deletionStatus'])
    ->name('instagram.deletion-status');
