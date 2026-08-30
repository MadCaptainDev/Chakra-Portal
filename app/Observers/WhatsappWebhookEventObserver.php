<?php

namespace App\Observers;

use App\Models\WhatsappConversation;
use App\Models\WhatsappWebhookEvent;

/**
 * Keeps the inbox's thread list in step with the webhook log.
 *
 * WhatsappWebhookEvent is the log of record for message content -- this
 * observer never reads from or writes back to it. It only mirrors the bit an
 * inbox list needs (last message, unread count) onto WhatsappConversation, the
 * same way PaymentObserver mirrors a payment onto Invoice::recalculateStatus()
 * rather than Invoice tracking its own balance.
 */
class WhatsappWebhookEventObserver
{
    public function created(WhatsappWebhookEvent $event): void
    {
        if ($event->wa_id === null) {
            return;
        }

        if (! in_array($event->type, [WhatsappWebhookEvent::TYPE_MESSAGE, WhatsappWebhookEvent::TYPE_OUTGOING], true)) {
            return;
        }

        $conversation = WhatsappConversation::updateOrCreate(
            ['wa_id' => $event->wa_id],
            [
                'last_message_at' => $event->occurred_at ?? now(),
                'last_message_summary' => $event->summary,
            ]
        );

        // Only an inbound message is unread -- our own outgoing send is not
        // something anyone here needs to be told to go read.
        if ($event->type === WhatsappWebhookEvent::TYPE_MESSAGE) {
            $conversation->increment('unread_count');
        }
    }
}
