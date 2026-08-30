<?php

namespace App\Support;

use App\Models\WhatsappWebhookEvent;
use App\Services\WhatsappSender;

/**
 * Whether Meta will accept free-form text to a number right now.
 *
 * The 24-hour customer-service window opens on the last inbound message
 * and closes a day later — WhatsappSender enforces this at send time, but
 * callers that choose between sendText and sendTemplate need to know
 * beforehand.
 */
class WhatsappServiceWindow
{
    public static function isOpen(string $phone): bool
    {
        $waId = WhatsappSender::normalise($phone);

        $lastInbound = WhatsappWebhookEvent::query()
            ->where('wa_id', $waId)
            ->where('type', WhatsappWebhookEvent::TYPE_MESSAGE)
            ->orderByDesc('occurred_at')
            ->first();

        return $lastInbound !== null
            && $lastInbound->occurred_at !== null
            && $lastInbound->occurred_at->gt(now()->subDay());
    }
}
