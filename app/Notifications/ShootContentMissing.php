<?php

namespace App\Notifications;

use App\Models\Shoot;
use App\Notifications\Channels\FcmChannel;
use App\Services\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * "This shoot happened and nothing from it is in the Reel Planner yet" --
 * see Shoot::scopeNeedsContentAdded() for exactly what that means. One per
 * shoot, staff only.
 */
class ShootContentMissing extends Notification
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
            title: 'Add to Notion: '.$this->shoot->title,
            body: $this->shoot->starts_at->format('D j M').' — completed, but nothing\'s in the Reel Planner yet.',
            url: route('shoots.show', $this->shoot),
            tag: 'shoot-content-missing-'.$this->shoot->id,
        );
    }
}
