<?php

namespace App\Observers;

use App\Models\WhatsappContact;
use App\Models\WhatsappConversation;
use App\Models\WhatsappWebhookEvent;
use App\Services\WhatsappFlow\FlowEngine;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps the inbox's thread list in step with the webhook log.
 *
 * WhatsappWebhookEvent is the log of record for message content -- this
 * observer never reads from or writes back to it. It only mirrors the bit an
 * inbox list needs (last message, unread count, who this actually is) onto
 * WhatsappConversation and WhatsappContact, the same way PaymentObserver
 * mirrors a payment onto Invoice::recalculateStatus() rather than Invoice
 * tracking its own balance.
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

        $this->attachContact($conversation, $event);

        // Only an inbound message is unread -- our own outgoing send is not
        // something anyone here needs to be told to go read. It is also the
        // only direction a flow ever reacts to -- nothing here should run
        // for our own outgoing sends.
        if ($event->type === WhatsappWebhookEvent::TYPE_MESSAGE) {
            $conversation->increment('unread_count');

            // FlowEngine::handleInbound() already catches everything a node
            // handler can throw and fails the session cleanly rather than
            // propagating -- this catch is defense-in-depth for whatever it
            // doesn't anticipate (container resolution, a bug in matching or
            // starting a session before the loop's own try/catch is reached).
            // This observer sits directly in the webhook's ingest path,
            // which must always reach its mandatory 200 response, so this
            // logs and swallows the same way
            // WhatsappWebhookController::receive()'s own catch block does,
            // rather than letting anything unwind past here.
            try {
                app(FlowEngine::class)->handleInbound($event);
            } catch (Throwable $e) {
                Log::error('WhatsApp flow could not be advanced.', [
                    'error' => $e->getMessage(),
                    'event_id' => $event->id,
                ]);
            }
        }
    }

    /**
     * Links this conversation to a WhatsappContact, naming it from WhatsApp's
     * own profile name (WhatsappWebhookEvent::ingest()'s contactsByWaId(),
     * carried on $event->contact_name) the first time we see one -- most
     * contacts in the table get their first name exactly this way, before
     * anyone at the studio has typed a thing. Never overwrites a name
     * already on file: WhatsApp's own display name is not necessarily what
     * the studio wants to call somebody, and a staff member setting one by
     * hand (WhatsappInboxController::updateContact()) is a decision this
     * must not quietly undo on the next message that happens to carry a
     * different profile name.
     */
    private function attachContact(WhatsappConversation $conversation, WhatsappWebhookEvent $event): void
    {
        if (blank($event->contact_name)) {
            return;
        }

        $contact = $conversation->contact ?? WhatsappContact::firstOrNew(['phone' => $event->wa_id]);

        if (blank($contact->name)) {
            $contact->name = $event->contact_name;
            $contact->save();
        }

        if ($conversation->contact_id !== $contact->id) {
            $conversation->update(['contact_id' => $contact->id]);
        }
    }
}
