<?php

namespace App\Http\Controllers;

use App\Models\InstagramSetting;
use App\Models\SocialAccount;
use App\Models\SocialWebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The endpoint Instagram posts to. Two verbs, two entirely different jobs.
 *
 * GET is the subscription handshake, run once when somebody presses "Verify
 * and save" in the Meta dashboard. POST is every event afterwards.
 *
 * Neither is behind auth, and neither can be: Meta arrives as a stranger with
 * no cookie. GET proves itself with the verify token, POST with a signature
 * over the body -- see VerifyInstagramSignature, which POST does not reach
 * this class without.
 *
 * NOT to be confused with InstagramConnectionController::callback(), which is
 * where a signed-in person lands after authorising. Same product, different
 * URL, opposite authentication story.
 */
class InstagramWebhookController extends Controller
{
    /**
     * The subscription handshake.
     *
     * Meta sends hub.mode=subscribe with the verify token typed into the
     * dashboard and expects hub.challenge echoed back as a bare body. Not
     * JSON, not wrapped -- Meta compares the response to the challenge
     * exactly, and anything else fails with the dashboard saying only that the
     * callback URL or verify token could not be validated.
     */
    public function verify(Request $request): Response
    {
        $settings = InstagramSetting::current();

        /*
         * Read with underscores because PHP rewrites a dot in a query string
         * parameter name to an underscore before any framework sees it.
         * Asking for 'hub.mode' would instead look for a nested key under
         * 'hub', find nothing, and refuse every handshake.
         */
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode !== 'subscribe' || ! $settings->matchesVerifyToken(is_string($token) ? $token : null)) {
            Log::warning('Instagram webhook verification failed.', [
                'ip' => $request->ip(),
                'mode' => $mode,
            ]);

            return response('', 403);
        }

        $settings->forceFill(['webhook_verified_at' => now()])->save();

        Log::info('Instagram webhook verified by Meta.');

        return response((string) $challenge, 200)->header('Content-Type', 'text/plain');
    }

    /**
     * Every event after the handshake.
     *
     * Always answers 200 once the signature has passed. Meta retries anything
     * else with backoff and eventually disables the subscription, so a bug in
     * our parsing must not become Meta deciding the studio is unreachable.
     */
    public function receive(Request $request): Response
    {
        $payload = $request->json()->all();

        try {
            $stored = SocialWebhookEvent::ingest(SocialAccount::PLATFORM_INSTAGRAM, $payload);

            Log::info('Instagram webhook received.', [
                'object' => $payload['object'] ?? null,
                'stored' => $stored,
            ]);
        } catch (Throwable $e) {
            // The payload goes in the log with the error: a parse failure is
            // unfixable without the thing that failed to parse, and there is
            // no credential in an Instagram event body.
            Log::error('Instagram webhook could not be stored.', [
                'error' => $e->getMessage(),
                'payload' => $payload,
            ]);
        }

        return response('', 200);
    }
}
