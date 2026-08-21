<?php

namespace App\Services\Push;

use App\Models\PushSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * An OAuth2 bearer token for FCM's HTTP v1 API, minted from the studio's
 * service-account JSON.
 *
 * Hand-rolled rather than composer require google/auth. RFC 7523's
 * JWT-bearer grant has been frozen since 2015 and every service account on
 * Earth depends on it not changing; google/auth pulls firebase/php-jwt plus
 * three PSR packages plus a guzzlehttp/psr7 pin coupled to the Guzzle
 * version Laravel's own HTTP client already carries -- weight this app's
 * own conventions already argue against (see App\Support\Device's
 * docblock: "a new dependency costs something forever"). openssl_sign()
 * with OPENSSL_ALGO_SHA256 is confirmed present on this host's PHP 8.2
 * binary, and Laravel's own 'encrypted' cast already depends on the same
 * openssl extension.
 */
class FcmOAuth
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const TIMEOUT_SECONDS = 8;

    public function __construct(private readonly PushSetting $settings) {}

    public static function make(): self
    {
        return new self(PushSetting::current());
    }

    /**
     * A cached access token, minted fresh only when none is cached.
     *
     * Not Cache::remember(): its callback cannot hand a custom TTL back to
     * the outer call, and the TTL here has to come from Google's own
     * expires_in on every mint -- not a constant. Google returns 3599 in
     * practice; hardcoding 3600 hands FCM an expired token on the last
     * request of every hour.
     *
     * The cache key is fingerprinted to the credentials (a hash of the
     * service-account JSON), not a fixed string. Without that, an admin who
     * pastes a NEW service account on the Setup screen keeps getting the
     * OLD project's cached token for up to an hour, and the failure --
     * SENDER_ID_MISMATCH on every device -- looks nothing like its cause.
     * NotionSettingController solves the same problem by calling
     * forgetCaches() on save; the fingerprint is the version of that fix
     * that cannot be forgotten to call.
     *
     * The cached value is itself encrypted: the private key that mints this
     * token is encrypted three tables over, and a bearer token sitting
     * plaintext in the `cache` table would undo that for two lines of
     * saving.
     */
    public function accessToken(): string
    {
        $key = $this->cacheKey();
        $cached = Cache::get($key);

        if (is_string($cached)) {
            return Crypt::decryptString($cached);
        }

        [$token, $expiresIn] = $this->mint();

        Cache::put($key, Crypt::encryptString($token), max($expiresIn - 120, 60));
        Log::info('FCM access token minted.');

        return $token;
    }

    /** Drop the cached token. Call this on a 401 -- a key rotated mid-hour must not stay broken until the TTL lapses. */
    public function forget(): void
    {
        Cache::forget($this->cacheKey());
    }

    private function cacheKey(): string
    {
        return 'fcm-access-token-'.substr(sha1((string) $this->settings->service_account_json), 0, 12);
    }

    /** @return array{0: string, 1: int} the access token and Google's own expires_in */
    private function mint(): array
    {
        $account = $this->settings->serviceAccount();

        if ($account === null) {
            throw new RuntimeException(
                'Push notifications are not configured: add a Firebase service account under Setup -> Notifications.'
            );
        }

        $assertion = $this->assertion($account);

        $response = Http::asForm()
            ->timeout(self::TIMEOUT_SECONDS)
            ->post(self::TOKEN_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);

        if ($response->failed()) {
            Log::error('FCM OAuth token request failed.', [
                'status' => $response->status(),
                'error' => $response->json('error_description') ?? $response->body(),
            ]);

            throw new RuntimeException(
                'Google refused the request for an access token: '
                .($response->json('error_description') ?? 'HTTP '.$response->status())
            );
        }

        return [
            (string) $response->json('access_token'),
            (int) ($response->json('expires_in') ?? 3600),
        ];
    }

    /** Builds and signs the JWT assertion Google exchanges for an access token. */
    private function assertion(array $account): string
    {
        $now = time();

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss' => $account['client_email'],
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $input = self::base64url(json_encode($header)).'.'.self::base64url(json_encode($claims));

        $signed = openssl_sign($input, $signature, $account['private_key'], OPENSSL_ALGO_SHA256);

        if (! $signed) {
            throw new RuntimeException('Could not sign the Google OAuth assertion -- check the service account private key is valid.');
        }

        return $input.'.'.self::base64url($signature);
    }

    private static function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
