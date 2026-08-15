<?php

namespace App\Http\Controllers;

use App\Models\WhatsappSetting;
use App\Models\WhatsappWebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The endpoint Meta talks to. Two verbs, two entirely different jobs.
 *
 * GET is the subscription handshake, run once from the Meta dashboard when
 * somebody presses "Verify and save". POST is every event afterwards.
 *
 * Neither is behind auth, and neither can be: Meta arrives as a stranger. GET
 * proves itself with the verify token, POST with a signature over the body --
 * see VerifyWhatsappSignature, which POST does not reach this class without.
 */
class WhatsappWebhookController extends Controller
{
    /**
     * The subscription handshake.
     *
     * Meta sends hub.mode=subscribe with the verify token that was typed into
     * the dashboard, and expects the hub.challenge echoed back as a bare body.
     * Not JSON, not wrapped, no trailing newline -- Meta compares the response
     * to the challenge exactly, and a JSON-encoded challenge fails with the
     * dashboard saying only "The callback URL or verify token couldn't be
     * validated", which is how an afternoon disappears.
     */
    public function verify(Request $request): Response
    {
        $settings = WhatsappSetting::current();

        /*
         * Meta sends these as hub.mode, hub.verify_token and hub.challenge.
         * They are read with underscores because PHP rewrites a dot in a query
         * string parameter name to an underscore before any framework sees it
         * -- asking for 'hub.mode' would instead ask Laravel for a nested key
         * under 'hub', find nothing, and refuse every handshake.
         */
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode !== 'subscribe' || ! $settings->matchesVerifyToken(is_string($token) ? $token : null)) {
            Log::warning('WhatsApp webhook verification failed.', [
                'ip' => $request->ip(),
                'mode' => $mode,
            ]);

            return response('', 403);
        }

        // Recorded so the admin screen can say "verified" with a date on it
        // rather than the admin having to remember whether they ever finished
        // the setup.
        $settings->forceFill(['verified_at' => now()])->save();

        Log::info('WhatsApp webhook verified by Meta.');

        return response((string) $challenge, 200)
            ->header('Content-Type', 'text/plain');
    }

    /**
     * Every event after the handshake: incoming messages, and the delivery
     * status of messages we sent.
     *
     * Always answers 200 once the signature has passed. Meta retries anything
     * else with backoff and, after enough failures, disables the subscription
     * outright -- so a bug in our parsing must not become Meta deciding the
     * studio's number is unreachable. The row is already stored or the error is
     * already logged; a 500 here would only cost us the subscription.
     */
    public function receive(Request $request): Response
    {
        $payload = $request->json()->all();

        try {
            $stored = WhatsappWebhookEvent::ingest($payload);

            if ($stored > 0) {
                WhatsappSetting::current()->forceFill(['last_event_at' => now()])->save();
            }

            Log::info('WhatsApp webhook received.', [
                'object' => $payload['object'] ?? null,
                'stored' => $stored,
            ]);
        } catch (Throwable $e) {
            /*
             * The payload goes in the log with the error, because a parse
             * failure is unfixable without the thing that failed to parse --
             * and unlike the credentials table there is nothing secret in it
             * beyond a phone number and a message the studio was sent anyway.
             */
            Log::error('WhatsApp webhook could not be stored.', [
                'error' => $e->getMessage(),
                'payload' => $payload,
            ]);
        }

        return response('', 200);
    }
}
