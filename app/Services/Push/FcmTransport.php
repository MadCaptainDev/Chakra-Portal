<?php

namespace App\Services\Push;

use App\Models\PushSetting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The low-level conversation with FCM's HTTP v1 API.
 *
 * Everything that talks to Firebase goes through here so that the endpoint,
 * the OAuth token and the timeouts are decided once. PushSender is the
 * meaning; this is the plumbing -- the same split as WhatsappGraph /
 * WhatsappSender.
 */
class FcmTransport
{
    /**
     * Shorter than WhatsappGraph's 15s: a WhatsApp send is a deliberate
     * button press somebody is watching, but a push send is a side effect
     * of an unrelated action (posting an announcement, rejecting a
     * timesheet day) and that action's own request must not hang for long
     * on Google's account.
     */
    private const TIMEOUT_SECONDS = 8;

    private const CONNECT_TIMEOUT_SECONDS = 3;

    public function __construct(
        private readonly PushSetting $settings,
        private readonly FcmOAuth $oauth,
    ) {}

    public static function make(): self
    {
        $settings = PushSetting::current();

        return new self($settings, new FcmOAuth($settings));
    }

    public function isConfigured(): bool
    {
        return $this->settings->canSend();
    }

    /** A pre-authorized request, ready to .post() the endpoint for one token. */
    public function request(): PendingRequest
    {
        return Http::withToken($this->accessToken())
            ->timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->asJson();
    }

    /**
     * The bearer token alone, for callers (PushSender's Http::pool() fan-out)
     * that build their own pooled requests rather than using request()
     * directly -- a pooled request is a fresh PendingRequest per call, so
     * there is nothing to reuse from request() except the token itself.
     */
    public function accessToken(): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(
                'Push notifications are not configured: add a Firebase service account under Setup -> Notifications.'
            );
        }

        return $this->oauth->accessToken();
    }

    public function timeoutSeconds(): int
    {
        return self::TIMEOUT_SECONDS;
    }

    public function connectTimeoutSeconds(): int
    {
        return self::CONNECT_TIMEOUT_SECONDS;
    }

    public function endpoint(): string
    {
        $endpoint = $this->settings->endpoint();

        if ($endpoint === null) {
            throw new RuntimeException('Push notifications are not configured: no project id in the service account.');
        }

        return $endpoint;
    }

    /**
     * The full FCM v1 envelope for one device.
     *
     * message.data only -- see PushMessage's own docblock for why there is
     * no message.notification and no webpush.notification anywhere in
     * this array.
     *
     * @return array<string, mixed>
     */
    public function buildMessage(string $token, PushMessage $message): array
    {
        return [
            'message' => [
                'token' => $token,
                'data' => $message->toData(),
                'webpush' => [
                    // 24h: a phone that was off overnight still gets
                    // yesterday evening's to-do when it comes back online.
                    'headers' => ['TTL' => '86400', 'Urgency' => 'high'],
                ],
            ],
        ];
    }

    public function forgetOAuthToken(): void
    {
        $this->oauth->forget();
    }

    /**
     * What a per-token response means, without deciding what to do about
     * it -- PushSender owns the decision, this only names the shape.
     *
     * The 400/INVALID_ARGUMENT split matters: treating every 400 as a dead
     * token means one malformed payload (our own bug) deletes every device
     * in the studio on the first announcement after a bad deploy. Only a
     * 400 whose error.details names message.token is actually about the
     * token.
     *
     * @return array{outcome: string, reason: string}
     */
    public function classify(Response $response): array
    {
        if ($response->successful()) {
            return ['outcome' => 'sent', 'reason' => ''];
        }

        $status = $response->status();
        $error = $response->json('error') ?? [];
        $code = collect($error['details'] ?? [])->pluck('errorCode')->first();
        $reason = $error['message'] ?? ('HTTP '.$status);

        if ($status === 401) {
            return ['outcome' => 'reauth', 'reason' => $reason];
        }

        if ($status === 404 && $code === 'UNREGISTERED') {
            return ['outcome' => 'prune', 'reason' => $reason];
        }

        if ($status === 403 && $code === 'SENDER_ID_MISMATCH') {
            return ['outcome' => 'prune', 'reason' => $reason];
        }

        if ($status === 400 && $code === 'INVALID_ARGUMENT'
            && collect($error['details'] ?? [])->contains(fn ($d) => str_contains($d['fieldViolations'][0]['field'] ?? '', 'token'))
        ) {
            return ['outcome' => 'prune', 'reason' => $reason];
        }

        return ['outcome' => 'retry', 'reason' => $reason];
    }
}
