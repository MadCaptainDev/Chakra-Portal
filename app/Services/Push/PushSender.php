<?php

namespace App\Services\Push;

use App\Models\PushToken;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Sending a push notification to one or more devices.
 *
 * Fans out through Http::pool(), not a loop. FCM's HTTP v1 API has no batch
 * endpoint (the old /batch multipart endpoint was retired) -- a serial loop
 * over a dozen devices at 8s worst case each is a form POST hung for a
 * minute or more. Pooling issues every request concurrently, so wall-clock
 * cost is one round trip regardless of how many devices are notified.
 *
 * Never throws on a per-device failure. One dead phone must not stop the
 * other eleven -- send() only throws when NOTHING could be attempted at
 * all (not configured, or the OAuth exchange itself failed).
 */
class PushSender
{
    /**
     * A runaway fan-out on shared hosting is worse than a missed
     * notification -- capped and logged rather than attempted in full.
     */
    private const MAX_TOKENS_PER_SEND = 50;

    public function __construct(private readonly FcmTransport $transport) {}

    public static function make(): self
    {
        return new self(FcmTransport::make());
    }

    public function canSend(): bool
    {
        return $this->transport->isConfigured();
    }

    /**
     * @param  Collection<int, PushToken>  $tokens
     * @return array{sent: int, pruned: int, failed: int}
     */
    public function send(Collection $tokens, PushMessage $message): array
    {
        if (! $this->canSend()) {
            throw new RuntimeException(
                'Push notifications are not configured: add a Firebase service account under Setup -> Notifications.'
            );
        }

        if ($tokens->isEmpty()) {
            return ['sent' => 0, 'pruned' => 0, 'failed' => 0];
        }

        if ($tokens->count() > self::MAX_TOKENS_PER_SEND) {
            Log::warning('Push fan-out capped.', ['requested' => $tokens->count(), 'sent_to' => self::MAX_TOKENS_PER_SEND]);
            $tokens = $tokens->take(self::MAX_TOKENS_PER_SEND);
        }

        // Minted once, before the pool opens -- every pooled request reuses
        // it rather than each independently triggering FcmOAuth (which is
        // itself cached, but there is no reason to pay even a cache lookup
        // race across concurrent requests).
        $accessToken = $this->transport->accessToken();
        $endpoint = $this->transport->endpoint();
        $timeout = $this->transport->timeoutSeconds();
        $connectTimeout = $this->transport->connectTimeoutSeconds();

        $responses = Http::pool(fn ($pool) => $tokens->map(
            fn (PushToken $token) => $pool->as((string) $token->id)
                ->withToken($accessToken)
                ->timeout($timeout)->connectTimeout($connectTimeout)->asJson()
                ->post($endpoint, $this->transport->buildMessage($token->token, $message))
        )->all());

        $sent = 0;
        $pruned = 0;
        $failed = 0;
        $needsReauth = false;

        foreach ($tokens as $token) {
            $response = $responses[(string) $token->id] ?? null;

            // Http::pool() hands back a ConnectionException object, not a
            // Response, for anything that never got as far as an HTTP
            // status -- DNS, refused, timeout.
            if (! ($response instanceof Response)) {
                $token->markFailed('connection failed');
                $failed++;

                continue;
            }

            $result = $this->transport->classify($response);

            switch ($result['outcome']) {
                case 'sent':
                    $token->markUsed();
                    $sent++;
                    break;
                case 'prune':
                    Log::info('Pruning dead push token.', ['token_id' => $token->id, 'reason' => $result['reason']]);
                    $token->delete();
                    $pruned++;
                    break;
                case 'reauth':
                    $needsReauth = true;
                    $token->markFailed($result['reason']);
                    $failed++;
                    break;
                default: // 'retry'
                    $token->markFailed($result['reason']);
                    $failed++;
            }
        }

        if ($needsReauth) {
            // A rotated/revoked key mid-batch: drop the cached token so the
            // NEXT send mints a fresh one, rather than staying broken for
            // up to an hour with no way to clear it from a screen.
            $this->transport->forgetOAuthToken();
        }

        // Never the token, never the body: bodies carry review_note, a
        // manager's private feedback about someone's work.
        Log::info('Push sent.', [
            'devices' => $tokens->count(), 'sent' => $sent, 'pruned' => $pruned, 'failed' => $failed,
            'title' => $message->title,
        ]);

        return ['sent' => $sent, 'pruned' => $pruned, 'failed' => $failed];
    }
}
