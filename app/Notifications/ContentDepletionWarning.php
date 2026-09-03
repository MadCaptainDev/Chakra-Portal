<?php

namespace App\Notifications;

use App\Models\Client;
use App\Notifications\Channels\FcmChannel;
use App\Services\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * "This client is about to run out of content, and nothing is booked in
 * time" -- the alert this whole feature exists for. One per client, staff
 * only (see SendDepletionAlerts for who and why); tapping it opens the
 * Forecast page rather than trying to hold the reasoning in a push body.
 */
class ContentDepletionWarning extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $forecast  A ContentForecast::forClient() row.
     */
    public function __construct(public Client $client, public array $forecast) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [FcmChannel::class];
    }

    public function toFcm(object $notifiable): PushMessage
    {
        $depletion = $this->forecast['depletion_date'];
        $remaining = $this->forecast['remaining'];

        $body = $remaining <= 0
            ? 'Already out of unposted content and nothing is booked.'
            : "Runs out around {$depletion->format('j M')} ({$remaining} piece(s) left) — nothing booked before then.";

        return new PushMessage(
            title: $this->client->name.' is running low',
            body: $body,
            url: route('forecast.show', $this->client),
            tag: 'content-depletion-'.$this->client->id,
        );
    }
}
