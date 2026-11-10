<?php

namespace App\Notifications;

use App\Models\Shoot;
use App\Notifications\Channels\FcmChannel;
use App\Services\Push\PushMessage;
use Illuminate\Notifications\Notification;

/**
 * "Squad Architects has not started -- 20 min late", to the admins. One per
 * kind of lateness per shoot; see App\Console\Commands\ShootLateAlerts.
 */
class ShootRunningLate extends Notification
{
    public function __construct(public Shoot $shoot, public string $kind, public string $message) {}

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
            route('shoots.show', $this->shoot),
            'shoot-late-'.$this->kind.'-'.$this->shoot->id,
        );
    }
}
