<?php

namespace App\Notifications;

use App\Models\ReviewVideo;
use App\Notifications\Channels\FcmChannel;
use App\Services\Push\PushMessage;
use Illuminate\Notifications\Notification;

/** A cut was uploaded for review, approved, or sent back with changes. */
class VideoReviewUpdate extends Notification
{
    public function __construct(public ReviewVideo $video, public string $title, public string $body) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return [FcmChannel::class];
    }

    public function toFcm(object $notifiable): PushMessage
    {
        return new PushMessage($this->title, $this->body, route('reviews.show', $this->video), 'review-'.$this->video->todo_id);
    }
}
