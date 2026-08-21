<?php

namespace App\Notifications;

use App\Models\Announcement;
use App\Notifications\Channels\FcmChannel;
use App\Services\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A new announcement, pushed to every member of staff except whoever
 * posted it.
 *
 * Fired only from AnnouncementController::store() -- never update(). Fixing
 * a typo in an announcement must not re-alert twelve people.
 *
 * The deep link is deliberately role-aware. Announcements also render on
 * my.dashboard for staff without the announcements.view module (that is why
 * recipients here are ALL of User::staff(), not User::canSee('announcements')),
 * so a recipient without that permission who followed a straight link to
 * announcements.index would hit a 403.
 */
class AnnouncementPosted extends Notification
{
    use Queueable;

    public function __construct(public Announcement $announcement) {}

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
            title: 'New announcement',
            body: Str::limit($this->announcement->title, 150),
            url: $notifiable->allows('announcements')
                ? route('announcements.index')
                : route($notifiable->homeRoute()),
            tag: 'announcement-'.$this->announcement->id,
        );
    }
}
