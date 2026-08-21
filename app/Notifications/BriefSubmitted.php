<?php

namespace App\Notifications;

use App\Models\ClientBrief;
use App\Notifications\Channels\FcmChannel;
use App\Services\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A client's brand brief coming in for the first time.
 *
 * Fired only on the FIRST submission of a brief. Both controllers that can
 * submit one (PublicBriefController for a client with no login, and
 * Client\BriefController for one who is signed in) deliberately preserve
 * submitted_at across a re-submit -- a client may keep editing after
 * sending it in -- so the caller must capture whether this was already
 * submitted BEFORE writing the new submitted_at, or reading it after
 * always returns non-null.
 */
class BriefSubmitted extends Notification
{
    use Queueable;

    public function __construct(public ClientBrief $brief) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [FcmChannel::class];
    }

    public function toFcm(object $notifiable): PushMessage
    {
        $client = $this->brief->client;

        return new PushMessage(
            title: 'Brand brief submitted',
            body: $client->name.' just sent in their brand brief.',
            url: route('clients.show', $client),
            tag: 'brief-'.$this->brief->id,
        );
    }
}
