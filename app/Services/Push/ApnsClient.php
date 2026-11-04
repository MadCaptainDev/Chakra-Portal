<?php

namespace App\Services\Push;

use App\Models\PushSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Apple Push Notification service, straight from the portal: token auth
 * with the studio's .p8 key (Settings → Push Notifications), HTTP/2.
 *
 * Apps installed from Xcode talk to the SANDBOX servers, TestFlight/App
 * Store builds to PRODUCTION, and a token from one is BadDeviceToken on
 * the other. Each token carries the environment the app reported; if Apple
 * still says BadDeviceToken we try the other one once and report which
 * worked, so the caller can remember it.
 */
class ApnsClient
{
    public const SANDBOX = 'sandbox';

    public const PRODUCTION = 'production';

    private const HOSTS = [
        self::SANDBOX => 'https://api.sandbox.push.apple.com',
        self::PRODUCTION => 'https://api.push.apple.com',
    ];

    public function __construct(private readonly PushSetting $settings) {}

    public static function make(): self
    {
        return new self(PushSetting::current());
    }

    public function isConfigured(): bool
    {
        return $this->settings->apnsConfigured();
    }

    public function bundleId(): string
    {
        return (string) $this->settings->apns_bundle_id;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, status: int, reason: ?string, environment: string, gone: bool}
     */
    public function send(string $token, string $environment, string $pushType, string $topic, array $payload, int $priority = 10): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Apple push is not set up: add the .p8 key under Settings → Push Notifications.');
        }

        $environment = $environment === self::SANDBOX ? self::SANDBOX : self::PRODUCTION;
        $result = $this->post($token, $environment, $pushType, $topic, $payload, $priority);

        if (! $result['ok'] && $result['reason'] === 'BadDeviceToken') {
            $other = $environment === self::SANDBOX ? self::PRODUCTION : self::SANDBOX;
            $retry = $this->post($token, $other, $pushType, $topic, $payload, $priority);
            if ($retry['ok'] || $retry['reason'] !== 'BadDeviceToken') {
                $result = $retry;
            }
        }

        // The token is dead for good: the app was deleted, or it was never valid.
        $result['gone'] = $result['status'] === 410
            || in_array($result['reason'], ['BadDeviceToken', 'Unregistered', 'DeviceTokenNotForTopic'], true);

        return $result;
    }

    /** @return array{ok: bool, status: int, reason: ?string, environment: string} */
    private function post(string $token, string $environment, string $pushType, string $topic, array $payload, int $priority): array
    {
        try {
            $response = Http::withOptions(['version' => 2.0, 'curl' => [CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0]])
                ->withHeaders([
                    'authorization' => 'bearer '.$this->jwt(),
                    'apns-push-type' => $pushType,
                    'apns-topic' => $topic,
                    'apns-priority' => (string) $priority,
                    'apns-expiration' => (string) (time() + 3600),
                ])
                ->timeout(10)->connectTimeout(5)
                ->withBody(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'application/json')
                ->post(self::HOSTS[$environment].'/3/device/'.$token);
        } catch (Throwable $e) {
            return ['ok' => false, 'status' => 0, 'reason' => mb_substr($e->getMessage(), 0, 100), 'environment' => $environment];
        }

        if ($response->status() === 403 && $response->json('reason') === 'ExpiredProviderToken') {
            Cache::forget($this->jwtKey());
        }

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'reason' => $response->successful() ? null : (string) ($response->json('reason') ?? 'HTTP '.$response->status()),
            'environment' => $environment,
        ];
    }

    /** The provider token: ES256 JWT, reused for 45 minutes (Apple allows up to 60, refuses churn under 20). */
    public function jwt(): string
    {
        return Cache::remember($this->jwtKey(), now()->addMinutes(45), function () {
            $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            $head = $b64(json_encode(['alg' => 'ES256', 'kid' => $this->settings->apns_key_id]));
            $claims = $b64(json_encode(['iss' => $this->settings->apns_team_id, 'iat' => time()]));
            $key = openssl_pkey_get_private((string) $this->settings->apns_key_p8);
            if ($key === false || ! openssl_sign($head.'.'.$claims, $der, $key, OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('The saved APNs .p8 key could not sign. Paste the whole file again.');
            }

            return $head.'.'.$claims.'.'.$b64(self::derToJose($der));
        });
    }

    private function jwtKey(): string
    {
        return 'apns-jwt:'.$this->settings->apns_key_id.':'.$this->settings->apns_team_id;
    }

    /** openssl gives an ASN.1 DER signature; JWS ES256 wants raw R||S, 32 bytes each. */
    public static function derToJose(string $der): string
    {
        $offset = 2 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7F : 0);
        $part = function () use ($der, &$offset): string {
            $len = ord($der[$offset + 1]);
            $value = substr($der, $offset + 2, $len);
            $offset += 2 + $len;

            return str_pad(ltrim($value, "\x00"), 32, "\x00", STR_PAD_LEFT);
        };

        return $part().$part();
    }
}
