<?php

namespace App\Services\Instagram;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The low-level conversation with Instagram's endpoints.
 *
 * Everything that talks to Instagram goes through here, so the timeouts and --
 * far more importantly -- the error handling and the log redaction are decided
 * once. InstagramOAuth is the meaning; this is the plumbing.
 *
 * Three hosts are involved and they are not interchangeable:
 *
 *   www.instagram.com    the authorize screen a person is sent to
 *   api.instagram.com    the code -> short-lived token exchange (form POST)
 *   graph.instagram.com  everything afterwards
 *
 * This is the Instagram API with Instagram Login, NOT the retired Basic
 * Display API and not the Facebook-Login variant that goes through
 * graph.facebook.com and needs a linked Page.
 */
class InstagramGraph
{
    public const AUTHORIZE_URL = 'https://www.instagram.com/oauth/authorize';

    public const TOKEN_URL = 'https://api.instagram.com/oauth/access_token';

    public const GRAPH_HOST = 'https://graph.instagram.com';

    /**
     * Pinned rather than "latest". A version that changes under the app is a
     * version that removes a metric mid-quarter; moving it is a deliberate
     * edit with the changelog open.
     */
    public const API_VERSION = 'v23.0';

    /** Instagram answers quickly or not at all. */
    private const TIMEOUT_SECONDS = 15;

    /**
     * Exchange the authorization code for a short-lived token.
     *
     * Form-encoded, not JSON: this is the one Instagram endpoint that refuses
     * a JSON body, and it fails with a generic "Invalid platform app" that
     * points nowhere near the real cause.
     *
     * @return array<string, mixed>
     */
    public function exchangeCode(string $appId, string $appSecret, string $redirectUri, string $code): array
    {
        $response = Http::asForm()
            ->timeout(self::TIMEOUT_SECONDS)
            ->post(self::TOKEN_URL, [
                'client_id' => $appId,
                'client_secret' => $appSecret,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $redirectUri,
                'code' => $code,
            ]);

        return $this->result($response, 'oauth/access_token');
    }

    /**
     * Trade a short-lived token (1 hour) for a long-lived one (~60 days).
     *
     * Done immediately after the exchange, always. Storing the short-lived
     * token instead produces a connection that works while somebody is
     * watching and is dead by the next morning.
     *
     * @return array<string, mixed>
     */
    public function exchangeForLongLivedToken(string $appSecret, string $shortLivedToken): array
    {
        $response = Http::timeout(self::TIMEOUT_SECONDS)
            ->get(self::GRAPH_HOST.'/access_token', [
                'grant_type' => 'ig_exchange_token',
                'client_secret' => $appSecret,
                'access_token' => $shortLivedToken,
            ]);

        return $this->result($response, 'access_token');
    }

    /**
     * Refresh a long-lived token. Phase 5 uses this; it lives here now because
     * it is the same call shape and belongs beside its siblings.
     *
     * @return array<string, mixed>
     */
    public function refreshToken(string $longLivedToken): array
    {
        $response = Http::timeout(self::TIMEOUT_SECONDS)
            ->get(self::GRAPH_HOST.'/refresh_access_token', [
                'grant_type' => 'ig_refresh_token',
                'access_token' => $longLivedToken,
            ]);

        return $this->result($response, 'refresh_access_token');
    }

    /**
     * A read against the Graph host on behalf of a connected account.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, string $accessToken, array $query = []): array
    {
        $response = Http::timeout(self::TIMEOUT_SECONDS)
            ->get(self::GRAPH_HOST.'/'.self::API_VERSION.'/'.ltrim($path, '/'),
                $query + ['access_token' => $accessToken]);

        return $this->result($response, $path);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function result(Response $response, string $context): array
    {
        if ($response->failed()) {
            $this->throwFrom($response, $context);
        }

        return $response->json() ?? [];
    }

    /**
     * Meta's own message, verbatim, as an exception.
     *
     * Theirs are unusually specific -- "The user is not an Instagram Business
     * account", "Invalid redirect_uri" -- and each names its own fix. Replacing
     * them with "Instagram connection failed" throws away the only useful part
     * and turns a two-minute correction into an afternoon.
     *
     * The log carries the status and the error body and NEVER the token or the
     * app secret: this method is reached with both in scope, and a redacted
     * log is the difference between a leaked credential and a support ticket.
     */
    private function throwFrom(Response $response, string $context): never
    {
        $error = $response->json('error', []);

        // Two shapes in play: the OAuth endpoints answer with error_message /
        // error_type at the top level, the Graph host with a nested error
        // object. Both are handled rather than guessed at.
        $message = $error['message']
            ?? $response->json('error_message')
            ?? 'Instagram returned HTTP '.$response->status().' for '.$context;

        Log::error('Instagram API call failed.', [
            'context' => $context,
            'status' => $response->status(),
            'type' => $error['type'] ?? $response->json('error_type'),
            'code' => $error['code'] ?? null,
            'message' => $message,
        ]);

        throw new InstagramException($message, $response->status(), $error['code'] ?? null);
    }
}
