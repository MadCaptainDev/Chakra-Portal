<?php

namespace App\Services\Push;

use App\Models\PushToken;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Ordinary notifications to iPhone app tokens registered as kind "apns" (no Firebase involved). */
class ApnsAlerts
{
    /** @param  Collection<int, PushToken>  $tokens */
    public static function send(Collection $tokens, PushMessage $message): void
    {
        $apns = ApnsClient::make();
        if ($tokens->isEmpty() || ! $apns->isConfigured()) {
            return;
        }

        $payload = [
            'aps' => array_filter([
                'alert' => ['title' => $message->title, 'body' => $message->body],
                'sound' => 'default',
                'thread-id' => $message->tag,
            ]),
        ] + $message->toData();

        foreach ($tokens->take(50) as $token) {
            try {
                $result = $apns->send($token->token, (string) ($token->environment ?? ApnsClient::PRODUCTION), 'alert', $apns->bundleId(), $payload);
                if ($result['gone']) {
                    $token->delete();
                } elseif ($result['ok']) {
                    $token->forceFill(['environment' => $result['environment'], 'last_used_at' => now()])->save();
                } else {
                    $token->markFailed(mb_substr((string) $result['reason'], 0, 120));
                }
            } catch (Throwable $e) {
                Log::error('APNs push failed.', ['push_token_id' => $token->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
