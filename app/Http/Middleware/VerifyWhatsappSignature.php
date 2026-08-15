<?php

namespace App\Http\Middleware;

use App\Models\WhatsappSetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * The front door to the WhatsApp webhook.
 *
 * The callback URL is public by necessity -- Meta posts to it unauthenticated,
 * from addresses that change, with no bearer token to check. The signature is
 * therefore the *only* thing separating a real event from anybody who has
 * learned the URL, and the URL is not a secret: it is printed on an admin
 * screen, saved in the Meta dashboard, and will end up in a support ticket.
 *
 * So this fails closed. No app secret configured means nothing is accepted,
 * because a webhook we cannot authenticate is a stranger writing rows into the
 * studio's message log.
 */
class VerifyWhatsappSignature
{
    /** Meta signs with HMAC-SHA256 and prefixes the hex digest. */
    private const PREFIX = 'sha256=';

    public function handle(Request $request, Closure $next): Response
    {
        $secret = WhatsappSetting::current()->app_secret;

        if (blank($secret)) {
            /*
             * Warning, not debug: this is a live endpoint dropping real
             * events, and the only fix is a human pasting the app secret into
             * Settings -> WhatsApp. The admin screen says the same thing in
             * the same words, so whichever one is found first is enough.
             */
            Log::warning('WhatsApp webhook rejected: no app secret configured.');

            return $this->refuse();
        }

        $signature = $request->header('X-Hub-Signature-256');

        if (! is_string($signature) || ! str_starts_with($signature, self::PREFIX)) {
            Log::warning('WhatsApp webhook rejected: missing signature header.', [
                'ip' => $request->ip(),
            ]);

            return $this->refuse();
        }

        /*
         * getContent(), not the parsed input. The signature covers the exact
         * bytes Meta sent, and decoding then re-encoding the JSON changes them
         * -- key order, unicode escapes, whitespace -- so every signature would
         * fail for reasons no one would ever find.
         */
        $expected = self::PREFIX.hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            Log::warning('WhatsApp webhook rejected: signature mismatch.', [
                'ip' => $request->ip(),
            ]);

            return $this->refuse();
        }

        return $next($request);
    }

    /**
     * Deliberately identical for every reason above, and deliberately terse.
     * Telling an unauthenticated caller *why* it was refused tells an attacker
     * whether the secret is set and whether their guess was close.
     */
    private function refuse(): Response
    {
        return response('', 403);
    }
}
