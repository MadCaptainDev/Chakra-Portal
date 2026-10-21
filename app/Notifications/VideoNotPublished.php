<?php

namespace App\Notifications;

use App\Models\Todo;
use App\Notifications\Channels\FcmChannel;
use App\Services\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A Video ready that is still not marked posted 30 minutes after its
 * publishing time -- to the editor and to the admins (PublishReminders).
 */
class VideoNotPublished extends Notification
{
    use Queueable;

    public function __construct(public Todo $video) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return [FcmChannel::class];
    }

    public function toFcm(object $notifiable): PushMessage
    {
        $late = (int) $this->video->publish_at->diffInMinutes(now());
        $mine = $notifiable->id === $this->video->user_id;

        return new PushMessage(
            title: 'Due and not out yet',
            body: Str::limit($this->video->title, 90).' was due at '.$this->video->publish_at->format('g:i A')
                .' ('.$late.' min ago)'.($mine ? '. Mark it posted or delivered.' : ' — '.($this->video->user?->name ?? 'editor').'.'),
            url: $notifiable->isAdmin() && ! $mine ? route('editor-updates') : route('my.editing'),
            tag: 'publish-late-'.$this->video->id,
        );
    }
}
