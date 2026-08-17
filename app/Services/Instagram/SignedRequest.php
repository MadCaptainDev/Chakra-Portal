<?php

namespace App\Services\Instagram;

use Illuminate\Support\Facades\Log;

/**
 * Meta's `signed_request`, as sent to the deauthorize and data-deletion URLs.
 *
 * A different mechanism from the webhook's X-Hub-Signature-256 header, and
 * worth saying so: those two endpoints receive a single form field holding
 * `{signature}.{payload}`, both base64url, where the signature is an
 * HMAC-SHA256 of the *encoded payload string* keyed on the app secret.
 *
 * The subtlety that breaks naive implementations: the HMAC covers the encoded
 * payload exactly as received, not the decoded JSON. Decoding and re-encoding
 * changes the bytes and every signature fails.
 *
 * These endpoints are public and unauthenticated by necessity -- Meta posts to
 * them as a stranger -- so this verification is the only thing standing
 * between a stranger and "disconnect this account".
 */
class SignedRequest
{
    /**
     * Verify and decode, or null if it cannot be trusted.
     *
     * Null rather than an exception: the caller answers Meta either way, and a
     * failure here is a refusal rather than an error worth propagating.
     *
     * @return array<string, mixed>|null
     */
    public static function parse(?string $signedRequest, string $appSecret): ?array
    {
        if (! is_string($signedRequest) || ! str_contains($signedRequest, '.')) {
            return null;
        }

        [$encodedSignature, $encodedPayload] = explode('.', $signedRequest, 2);

        $signature = self::base64UrlDecode($encodedSignature);
        $payload = self::base64UrlDecode($encodedPayload);

        if ($signature === null || $payload === null) {
            return null;
        }

        // Keyed on the ENCODED payload, exactly as it arrived.
        $expected = hash_hmac('sha256', $encodedPayload, $appSecret, true);

        if (! hash_equals($expected, $signature)) {
            Log::warning('Instagram signed_request signature mismatch.');

            return null;
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Base64url, which is not base64: Meta uses - and _ in place of + and /,
     * and drops the padding. Feeding it to base64_decode() unchanged returns
     * plausible-looking rubbish rather than failing, which is why this is its
     * own function with a strict decode.
     */
    private static function base64UrlDecode(string $value): ?string
    {
        $translated = strtr($value, '-_', '+/');
        $padded = str_pad($translated, (int) (ceil(strlen($translated) / 4) * 4), '=', STR_PAD_RIGHT);

        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }
}
