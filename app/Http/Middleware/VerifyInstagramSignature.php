<?php

namespace App\Http\Middleware;

use App\Models\InstagramSetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * The front door to the Instagram webhook.
 *
 * Same shape and same reasoning as VerifyWhatsappSignature: the callback URL
 * is public by necessity, is printed on an admin screen, and will end up in a
 * support ticket -- so the signature is the only thing separating a real event
 * from anybody who has learned the URL.
 *
 * Fails closed. No app secret means nothing is accepted, because an event we
 * cannot authenticate is a stranger writing rows into the studio's records.
 */
class VerifyInstagramSignature
{
    /** Meta signs with HMAC-SHA256 and prefixes the hex digest. */
    private const PREFIX = 'sha256=';

    public function handle(Request $request, Closure $next): Response
    {
        $secret = InstagramSetting::current()->app_secret;

        if (blank($secret)) {
            Log::warning('Instagram webhook rejected: no app secret configured.');

            return $this->refuse();
        }

        $signature = $request->header('X-Hub-Signature-256');

        if (! is_string($signature) || ! str_starts_with($signature, self::PREFIX)) {
            Log::warning('Instagram webhook rejected: missing signature header.', ['ip' => $request->ip()]);

            return $this->refuse();
        }

        /*
         * getContent(), not the parsed input. The signature covers the exact
         * bytes Meta sent, and decoding then re-encoding the JSON changes them
         * -- key order, unicode escapes, whitespace -- so every signature would
         * fail for reasons nobody would ever find.
         */
        $expected = self::PREFIX.hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            Log::warning('Instagram webhook rejected: signature mismatch.', ['ip' => $request->ip()]);

            return $this->refuse();
        }

        return $next($request);
    }

    /**
     * Deliberately identical for every reason above. Telling an
     * unauthenticated caller *why* it was refused tells an attacker whether
     * the secret is set and whether their guess was close.
     */
    private function refuse(): Response
    {
        return response('', 403);
    }
}
