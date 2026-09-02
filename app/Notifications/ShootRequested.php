<?php

namespace App\Notifications;

use App\Models\Shoot;
use App\Notifications\Channels\FcmChannel;
use App\Services\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A client asked for a shoot through their own portal
 * (Client\ShootRequestController) -- pushed once, immediately, to whoever
 * can triage it, so the "Requested" badge on the Shoots board is not the
 * only way anyone finds out.
 */
class ShootRequested extends Notification
{
    use Queueable;

    public function __construct(public Shoot $shoot) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [FcmChannel::class];
    }

    public function toFcm(object $notifiable): PushMessage
    {
        return new PushMessage(
            title: 'Shoot requested: '.$this->shoot->clientLabel(),
            body: $this->shoot->title.' — preferred date '.$this->shoot->starts_at->format('D j M'),
            url: route('shoots.show', $this->shoot),
            tag: 'shoot-requested-'.$this->shoot->id,
        );
    }
}
