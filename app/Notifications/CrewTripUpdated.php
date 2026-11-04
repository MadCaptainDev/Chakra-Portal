<?php

namespace App\Notifications;

use App\Models\Shoot;
use App\Notifications\Channels\FcmChannel;
use App\Services\Push\PushMessage;
use Illuminate\Notifications\Notification;

/** "Sanjai is on the way to Squad Architects · ETA 11:20" -- to admins and the rest of the crew. */
class CrewTripUpdated extends Notification
{
    public function __construct(public Shoot $shoot, public string $message) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return [FcmChannel::class];
    }

    public function toFcm(object $notifiable): PushMessage
    {
        return new PushMessage(
            $this->shoot->client?->name ?? $this->shoot->title,
            $this->message,
            route('shoots.call-sheet', $this->shoot),
            'crew-trip-'.$this->shoot->id,
        );
    }
}
